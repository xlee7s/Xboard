<?php

namespace Plugin\SubscriptionRiskControl\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SubscriptionRiskService
{
    private const REQUEST_TABLE = 'v2_subscription_risk_requests';
    private const EVENT_TABLE = 'v2_subscription_risk_events';
    private const WATCHLIST_TABLE = 'v2_subscription_risk_watchlist';
    private const BANLIST_TABLE = 'v2_subscription_risk_banlist';
    private const RESET_NOTICE_TABLE = 'v2_subscription_risk_reset_notices';

    /**
     * @return array{action: 'token'|'token_uuid'|'ua_blocked', reasons: array<int, string>, reset: bool, email?: string}|null
     */
    public function inspect(User $requestUser, Request $request, array $values): ?array
    {
        $config = $this->normalizeConfig($values);
        $ip = $this->clientIp($request, $config['real_ip_headers'], $config['trusted_proxy_ips']);
        $userAgent = mb_substr(trim((string) $request->userAgent()), 0, 512);
        $now = time();
        if (!$this->isAllowedUserAgent($userAgent, $config['allowed_user_agents'])) {
            $reason = '未过白User-Agent';
            $this->recordPull($requestUser->id, $ip, $userAgent, $now, false, $reason);
            return ['action' => 'ua_blocked', 'reasons' => [$reason], 'reset' => false];
        }

        if ($this->isWhitelisted($requestUser, $ip, $config)) {
            $this->recordPull($requestUser->id, $ip, $userAgent, $now);
            return null;
        }

        return DB::transaction(function () use ($requestUser, $config, $ip, $userAgent, $now): ?array {
            /** @var User|null $user */
            $user = User::query()->whereKey($requestUser->id)->lockForUpdate()->first();
            if (!$user) {
                return null;
            }

            // Concurrent requests authenticated with the old token must not rotate
            // the fresh token again. They are still rejected by the caller.
            if ((string) $user->token !== (string) $requestUser->token) {
                return ['action' => 'token', 'reasons' => ['订阅链接已失效'], 'reset' => false];
            }

            $this->recordPull($user->id, $ip, $userAgent, $now);

            // A credential reset starts a fresh detection period. Without this
            // boundary, the first pull using the new Token would inherit the
            // pulls that caused the reset and immediately be reset again.
            $lastResetAt = $this->lastCredentialResetAt($user->id);
            $normal = $this->windowStats($user->id, max($now - $config['normal_window_minutes'] * 60, $lastResetAt + 1));
            $high = $this->windowStats($user->id, max($now - $config['high_window_minutes'] * 60, $lastResetAt + 1));
            $normalReasons = $this->matchedReasons($normal, $config, 'normal');
            $highReasons = $this->matchedReasons($high, $config, 'high');
            if ($normalReasons === [] && $highReasons === []) {
                return null;
            }

            $action = $highReasons !== [] ? 'token_uuid' : 'token';
            $reasons = $highReasons !== [] ? $highReasons : $normalReasons;
            $previousToken = (string) $user->token;
            $previousTokenSuffix = substr($previousToken, -6);
            $user->token = Helper::guid();
            if ($action === 'token_uuid') {
                $user->uuid = Helper::guid(true);
            }
            $user->save();

            $this->rememberResetNotice($previousToken, $user->id, $action, $now);
            $this->upsertWatchlist($user->id, $action, $reasons, $now);
            $this->recordEvent($user->id, $action, $reasons, $ip, $userAgent, $normal, $high, $now);

            if ($config['enable_log']) {
                Log::warning('SubscriptionRiskControl reset subscription credentials', [
                    'user_id' => $user->id,
                    'action' => $action,
                    'reasons' => $reasons,
                    'previous_token_suffix' => $previousTokenSuffix,
                ]);
            }

            return [
                'action' => $action,
                'reasons' => $reasons,
                'reset' => true,
                'email' => (string) $user->email,
            ];
        }, 3);
    }

    public function runMaintenance(array $values): void
    {
        $config = $this->normalizeConfig($values);
        $now = time();
        $this->releaseExpiredWatchlist($now, $config['watch_days']);
    }

    /** @param array<int, int> $userIds
     *  @param array{id?: int, email?: string, ip?: string} $actor
     */
    public function releaseUsers(array $userIds, string $source = 'manual', ?int $expiredBefore = null, array $actor = []): int
    {
        $ids = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $source, $expiredBefore, $actor): int {
            $query = DB::table(self::WATCHLIST_TABLE)->whereIn('user_id', $ids);
            if ($source === 'auto' && $expiredBefore !== null) {
                $query->where('last_abnormal_at', '<=', $expiredBefore);
            }
            $existing = $query->lockForUpdate()->pluck('user_id')->all();
            if ($existing === []) {
                return 0;
            }
            DB::table(self::WATCHLIST_TABLE)->whereIn('user_id', $existing)->delete();
            $now = time();
            foreach ($existing as $userId) {
                $this->recordEvent((int) $userId, $source === 'auto' ? 'auto_release' : 'manual_release', ['解除风控观察名单'], '', '', [], [], $now, $actor);
            }
            return count($existing);
        }, 3);
    }

    /**
     * Ban a watched user and remove the no-longer-actionable watchlist entry.
     * @param array{id?: int, email?: string, ip?: string} $actor
     */
    public function banWatchedUser(int $userId, array $actor = []): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return DB::transaction(function () use ($userId, $actor): bool {
            /** @var User|null $user */
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if (!$user) {
                return false;
            }

            // Keep the same lock order as inspect(): User first, then the
            // watchlist row. This avoids a ban and a concurrent pull waiting
            // on each other in opposite directions.
            $watching = DB::table(self::WATCHLIST_TABLE)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first(['last_reasons', 'hit_count']);
            if (!$watching) {
                return false;
            }

            $user->banned = true;
            $user->save();
            $now = time();
            DB::table(self::BANLIST_TABLE)->updateOrInsert(
                ['user_id' => $userId],
                [
                    'reasons' => $watching->last_reasons,
                    'hit_count' => (int) $watching->hit_count,
                    'banned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            DB::table(self::WATCHLIST_TABLE)->where('user_id', $userId)->delete();
            $this->recordEvent($userId, 'manual_ban', ['管理员从风控观察名单封禁用户'], '', '', [], [], $now, $actor);

            return true;
        }, 3);
    }

    /** @param array{id?: int, email?: string, ip?: string} $actor */
    public function unbanUser(int $userId, array $actor = []): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return DB::transaction(function () use ($userId, $actor): bool {
            /** @var User|null $user */
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if (!$user) {
                return false;
            }

            $banned = DB::table(self::BANLIST_TABLE)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first(['reasons', 'hit_count']);
            if (!$banned) {
                return false;
            }

            $user->banned = false;
            $user->save();
            $now = time();
            DB::table(self::WATCHLIST_TABLE)->updateOrInsert(
                ['user_id' => $userId],
                [
                    'first_flagged_at' => $now,
                    'last_abnormal_at' => $now,
                    'last_action' => 'manual_unban',
                    'last_reasons' => $banned->reasons,
                    'hit_count' => (int) $banned->hit_count,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            DB::table(self::BANLIST_TABLE)->where('user_id', $userId)->delete();
            $this->recordEvent($userId, 'manual_unban', ['管理员解封用户并重新加入风控观察名单'], '', '', [], [], $now, $actor);

            return true;
        }, 3);
    }

    /** @return array{requests: int, ips: int, user_agents: int} */
    private function windowStats(int $userId, int $from): array
    {
        $query = DB::table(self::REQUEST_TABLE)
            ->where('user_id', $userId)
            ->where('is_allowed', true)
            ->where('requested_at', '>=', $from);
        return [
            'requests' => (clone $query)->count(),
            'ips' => (clone $query)->whereNotNull('ip')->where('ip', '!=', '')->distinct()->count('ip'),
            'user_agents' => (clone $query)->distinct()->count('user_agent_hash'),
        ];
    }

    /**
     * Returns the time of the last action that rotated subscription
     * credentials. Pulls made before that point belong to the old link and
     * must never contribute to a new link's risk threshold.
     */
    private function lastCredentialResetAt(int $userId): int
    {
        return (int) (DB::table(self::EVENT_TABLE)
            ->where('user_id', $userId)
            ->whereIn('action', ['token', 'token_uuid'])
            ->max('occurred_at') ?? 0);
    }

    public function pruneRequestLogs(array $values): int
    {
        $days = $this->normalizeConfig($values)['pull_log_retention_days'];
        $deleted = $days === 0 ? 0 : DB::table(self::REQUEST_TABLE)
            ->where('requested_at', '<', time() - $days * 86400)
            ->delete();
        if (Schema::hasTable(self::RESET_NOTICE_TABLE)) {
            DB::table(self::RESET_NOTICE_TABLE)->where('expires_at', '<', time())->delete();
        }
        return $deleted;
    }

    /**
     * Keep only a hash of the invalidated link. The core authentication
     * middleware uses it to show a helpful page for a link this plugin reset,
     * while unrelated invalid tokens still receive the standard error.
     */
    private function rememberResetNotice(string $token, int $userId, string $action, int $now): void
    {
        if ($token === '') {
            return;
        }
        if (!Schema::hasTable(self::RESET_NOTICE_TABLE)) {
            return;
        }
        DB::table(self::RESET_NOTICE_TABLE)->updateOrInsert(
            ['token_hash' => hash('sha256', $token)],
            [
                'user_id' => $userId,
                'action' => $action,
                'expires_at' => $now + 30 * 86400,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function recordPull(int $userId, string $ip, string $userAgent, int $now, bool $isAllowed = true, ?string $blockReason = null): void
    {
        DB::table(self::REQUEST_TABLE)->insert([
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => $userAgent === '' ? null : $userAgent,
            'user_agent_hash' => hash('sha256', $userAgent),
            'is_allowed' => $isAllowed,
            'block_reason' => $blockReason,
            'requested_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** @param array{requests: int, ips: int, user_agents: int} $stats
     *  @return array<int, string>
     */
    private function matchedReasons(array $stats, array $config, string $level): array
    {
        $prefix = $level === 'high' ? '高危' : '普通';
        $reasons = [];
        if ($stats['requests'] >= $config[$level . '_request_limit']) {
            $reasons[] = sprintf('%s窗口内拉取 %d 次', $prefix, $stats['requests']);
        }
        if ($config[$level . '_ip_limit'] > 0 && $stats['ips'] >= $config[$level . '_ip_limit']) {
            $reasons[] = sprintf('%s窗口内出现 %d 个 IP', $prefix, $stats['ips']);
        }
        if ($config[$level . '_user_agent_limit'] > 0 && $stats['user_agents'] >= $config[$level . '_user_agent_limit']) {
            $reasons[] = sprintf('%s窗口内出现 %d 个 User-Agent', $prefix, $stats['user_agents']);
        }
        return $reasons;
    }

    /** @param array<int, string> $reasons
     *  @param array{requests: int, ips: int, user_agents: int} $normal
     *  @param array{requests: int, ips: int, user_agents: int} $high
     */
    private function recordEvent(int $userId, string $action, array $reasons, string $ip, string $userAgent, array $normal, array $high, int $now, array $actor = []): void
    {
        DB::table(self::EVENT_TABLE)->insert([
            'user_id' => $userId,
            'action' => $action,
            'risk_level' => $this->riskLevel($action),
            'reasons' => json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE),
            'ip' => $ip === '' ? null : $ip,
            'user_agent' => $userAgent === '' ? null : $userAgent,
            'normal_requests' => $normal['requests'] ?? 0,
            'normal_ips' => $normal['ips'] ?? 0,
            'normal_user_agents' => $normal['user_agents'] ?? 0,
            'high_requests' => $high['requests'] ?? 0,
            'high_ips' => $high['ips'] ?? 0,
            'high_user_agents' => $high['user_agents'] ?? 0,
            'actor_admin_id' => $actor['id'] ?? null,
            'actor_admin_email' => $actor['email'] ?? null,
            'actor_ip' => $actor['ip'] ?? null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function riskLevel(string $action): string
    {
        if ($action === 'token_uuid') {
            return 'high';
        }
        if (str_contains($action, 'release') || $action === 'manual_unban') {
            return 'released';
        }
        return str_contains($action, 'ban') ? 'banned' : 'normal';
    }

    /** @param array<int, string> $reasons */
    private function upsertWatchlist(int $userId, string $action, array $reasons, int $now): void
    {
        $watch = DB::table(self::WATCHLIST_TABLE)->where('user_id', $userId)->lockForUpdate()->first();
        if ($watch) {
            DB::table(self::WATCHLIST_TABLE)->where('user_id', $userId)->update([
                'last_abnormal_at' => $now,
                'last_action' => $action,
                'last_reasons' => json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE),
                'hit_count' => (int) $watch->hit_count + 1,
                'updated_at' => $now,
            ]);
            return;
        }
        DB::table(self::WATCHLIST_TABLE)->insert([
            'user_id' => $userId,
            'first_flagged_at' => $now,
            'last_abnormal_at' => $now,
            'last_action' => $action,
            'last_reasons' => json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE),
            'hit_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function releaseExpiredWatchlist(int $now, int $watchDays): void
    {
        $userIds = DB::table(self::WATCHLIST_TABLE)
            ->where('last_abnormal_at', '<=', $now - $watchDays * 86400)
            ->limit(500)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->releaseUsers($userIds, 'auto', $now - $watchDays * 86400);
    }

    /** @return array<string, mixed> */
    private function normalizeConfig(array $values): array
    {
        $normalWindow = $this->boundedInt($values['normal_window_minutes'] ?? 10, 5, 10080);
        $normalRequests = $this->boundedInt($values['normal_request_limit'] ?? 12, 2, 100000);
        $normalIps = $this->boundedInt($values['normal_ip_limit'] ?? 6, 0, 100000);
        $normalUserAgents = $this->boundedInt($values['normal_user_agent_limit'] ?? 6, 0, 100000);

        return [
            'normal_window_minutes' => $normalWindow,
            'normal_request_limit' => $normalRequests,
            'normal_ip_limit' => $normalIps,
            'normal_user_agent_limit' => $normalUserAgents,
            'high_window_minutes' => $this->highWindow($values['high_window_minutes'] ?? 1, $normalWindow),
            'high_request_limit' => $this->highThreshold($values['high_request_limit'] ?? 8, $normalRequests, 8),
            'high_ip_limit' => $this->highThreshold($values['high_ip_limit'] ?? 4, $normalIps, 4, true),
            'high_user_agent_limit' => $this->highThreshold($values['high_user_agent_limit'] ?? 4, $normalUserAgents, 4, true),
            'watch_days' => $this->boundedInt($values['watch_days'] ?? 7, 1, 365),
            'pull_log_retention_days' => $this->boundedInt($values['pull_log_retention_days'] ?? 0, 0, 3650),
            'whitelist_user_ids' => $this->parseList($values['whitelist_user_ids'] ?? ''),
            'whitelist_emails' => array_map('mb_strtolower', $this->parseList($values['whitelist_emails'] ?? '')),
            'whitelist_ips' => $this->parseList($values['whitelist_ips'] ?? ''),
            'real_ip_headers' => $this->realIpHeaders($values['real_ip_headers'] ?? ''),
            'trusted_proxy_ips' => $this->trustedProxyIps($values['trusted_proxy_ips'] ?? ''),
            'allowed_user_agents' => $this->allowedUserAgents($values['allowed_user_agents'] ?? ''),
            'enable_log' => filter_var($values['enable_log'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function highThreshold(mixed $value, int $normalThreshold, int $default, bool $allowZero = false): int
    {
        $threshold = $this->boundedInt($value, $allowZero ? 0 : 1, 100000);
        // An optional normal IP/UA signal set to 0 is disabled. Its high-risk
        // counterpart must be disabled as well: otherwise a supposedly closed
        // normal rule could still trigger a high-risk reset by itself.
        if ($allowZero && $normalThreshold === 0) {
            return 0;
        }
        if (($allowZero && $threshold === 0) || $threshold < $normalThreshold) {
            return $threshold;
        }
        return max($allowZero ? 0 : 1, min($default, $normalThreshold - 1));
    }

    private function highWindow(mixed $value, int $normalWindow): int
    {
        $window = $this->boundedInt($value, 1, 10080);
        return $window < $normalWindow ? $window : 1;
    }

    private function isWhitelisted(User $user, string $ip, array $config): bool
    {
        if (in_array((string) $user->id, $config['whitelist_user_ids'], true)) {
            return true;
        }
        if (in_array(mb_strtolower((string) $user->email), $config['whitelist_emails'], true)) {
            return true;
        }
        foreach ($config['whitelist_ips'] as $allowed) {
            if ($this->ipMatches($ip, $allowed)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, string> $allowedUserAgents */
    private function isAllowedUserAgent(string $userAgent, array $allowedUserAgents): bool
    {
        $userAgent = mb_strtolower($userAgent);
        if ($userAgent === '') {
            return false;
        }
        foreach ($allowedUserAgents as $allowed) {
            if ($allowed !== '' && str_contains($userAgent, $allowed)) {
                return true;
            }
        }
        return false;
    }

    /** The safe baseline used when an upgrade has no saved UA configuration yet. */
    public static function defaultAllowedUserAgents(): string
    {
        return self::legacyDefaultAllowedUserAgents() . "\nincy\nv2plus\nxray\ninvisibleman\nmieru\nthrone\nvarmlen";
    }

    /** The shipped list before the 2.2.4 client-family expansion. */
    public static function legacyDefaultAllowedUserAgents(): string
    {
        return "v2rayn\nv2rayng\nv2ray\nsingbox\nhomeproxy\nmomo\nssr-plus\nclash\nmihomo\nmeta\nverge\nflclash\nnekobox\nnekoray\nclashmetaforandroid\nsing-box\nhiddify\nsfm\nshadowrocket\nsurge\nstash\nloon\nquantumult\npasswall\nssrplus\nsagernet\ngeneral\ntidalab\ntunnelab\nkaring\nhapp\nmatsuri\nstreisand\nsurfboard\negern\nv2box\nfoxray\nkitsunebi\nnpv";
    }

    /** @return array<int, string> */
    private function allowedUserAgents(mixed $value): array
    {
        $allowed = array_map('mb_strtolower', $this->parseList($value));
        // A newly introduced configuration field is absent in existing plugin
        // rows. Treat an absent or blank value as the safe shipped baseline,
        // rather than turning every subscription request into a 403 response.
        return $allowed !== []
            ? $allowed
            : array_map('mb_strtolower', $this->parseList(self::defaultAllowedUserAgents()));
    }

    /** @param array<int, string> $headers @param array<int, string> $trustedProxyIps */
    private function clientIp(Request $request, array $headers, array $trustedProxyIps): string
    {
        // Never accept a browser-supplied forwarding header directly. It only
        // becomes trustworthy when the TCP peer is a configured CDN/reverse
        // proxy; direct visitors then always retain their real socket IP.
        $peerIp = trim((string) $request->server('REMOTE_ADDR'));
        if (!$this->isTrustedProxy($peerIp, $trustedProxyIps)) {
            return $peerIp !== '' ? $peerIp : trim((string) $request->ip());
        }
        foreach ($headers as $header) {
            foreach ($this->headerIps((string) $request->header($header), $header) as $ip) {
                if ($this->isPublicIp($ip)) {
                    return $ip;
                }
            }
        }
        return trim((string) $request->ip());
    }

    /** @param array<int, string> $trustedProxyIps */
    private function isTrustedProxy(string $peerIp, array $trustedProxyIps): bool
    {
        foreach ($trustedProxyIps as $allowed) {
            if ($this->ipMatches($peerIp, $allowed)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, string> */
    private function trustedProxyIps(mixed $value): array
    {
        $proxies = $this->parseList($value);
        return $proxies !== [] ? $proxies : self::defaultTrustedProxyIps();
    }

    /** @return array<int, string> */
    public static function defaultTrustedProxyIps(): array
    {
        return array_merge(self::legacyDefaultTrustedProxyIps(), [
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
            '::1/128', 'fc00::/7', 'fe80::/10',
        ]);
    }

    /** @return array<int, string> */
    public static function legacyDefaultTrustedProxyIps(): array
    {
        return [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '10.0.0.0/8',
            '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '127.0.0.0/8',
        ];
    }

    /** @return array<int, string> */
    private function realIpHeaders(mixed $value): array
    {
        $headers = $this->parseList($value);
        return $headers !== []
            ? $headers
            : ['X-Forwarded-For', 'Forwarded', 'True-Client-IP', 'CF-Connecting-IP', 'X-Real-IP'];
    }

    /** @return array<int, string> */
    private function headerIps(string $value, string $header): array
    {
        if ($value === '') {
            return [];
        }
        if (mb_strtolower($header) === 'forwarded') {
            preg_match_all('/(?:^|[;,])\s*for=(?:"?\[?)([^\];",\s]+)(?:\]?"?)/i', $value, $matches);
            return $matches[1] ?? [];
        }
        return array_map('trim', explode(',', $value));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function ipMatches(string $ip, string $allowed): bool
    {
        if ($ip === '' || $allowed === '') {
            return false;
        }
        if (!str_contains($allowed, '/')) {
            return hash_equals($allowed, $ip);
        }
        [$network, $prefix] = explode('/', $allowed, 2);
        $networkBinary = @inet_pton($network);
        $ipBinary = @inet_pton($ip);
        if ($networkBinary === false || $ipBinary === false || strlen($networkBinary) !== strlen($ipBinary) || !ctype_digit($prefix)) {
            return false;
        }
        $bits = (int) $prefix;
        $maxBits = strlen($networkBinary) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;
        if ($fullBytes > 0 && substr($networkBinary, 0, $fullBytes) !== substr($ipBinary, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($networkBinary[$fullBytes]) & $mask) === (ord($ipBinary[$fullBytes]) & $mask);
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        return min(max((int) $value, $min), $max);
    }

    /** @return array<int, string> */
    private function parseList(mixed $value): array
    {
        // One value per line is preferred; Chinese and English commas or
        // semicolons are accepted as equivalent separators. Some older saved
        // plugin configurations contain literal "\\n" instead of real line
        // breaks, so normalize those too before splitting.
        $raw = str_replace(['\\r\\n', '\\n', '\\r'], "\n", trim((string) $value));
        $items = preg_split('/[\r\n\t ,;，；]+/u', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
    }
}
