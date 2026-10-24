<?php

namespace Plugin\SubscriptionRiskControl\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionRiskService
{
    private const REQUEST_TABLE = 'v2_subscription_risk_requests';
    private const EVENT_TABLE = 'v2_subscription_risk_events';
    private const WATCHLIST_TABLE = 'v2_subscription_risk_watchlist';
    private const BANLIST_TABLE = 'v2_subscription_risk_banlist';

    /**
     * @return array{action: 'token'|'token_uuid', reasons: array<int, string>, reset: bool, email?: string}|null
     */
    public function inspect(User $requestUser, Request $request, array $values): ?array
    {
        $config = $this->normalizeConfig($values);
        $ip = $this->clientIp($request);
        $userAgent = mb_substr(trim((string) $request->userAgent()), 0, 512);
        $now = time();
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
            $previousTokenSuffix = substr((string) $user->token, -6);
            $user->token = Helper::guid();
            if ($action === 'token_uuid') {
                $user->uuid = Helper::guid(true);
            }
            $user->save();

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
        $query = DB::table(self::REQUEST_TABLE)->where('user_id', $userId)->where('requested_at', '>=', $from);
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
        if ($days === 0) {
            return 0;
        }

        return DB::table(self::REQUEST_TABLE)
            ->where('requested_at', '<', time() - $days * 86400)
            ->delete();
    }

    private function recordPull(int $userId, string $ip, string $userAgent, int $now): void
    {
        DB::table(self::REQUEST_TABLE)->insert([
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => $userAgent === '' ? null : $userAgent,
            'user_agent_hash' => hash('sha256', $userAgent),
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
            'enable_log' => filter_var($values['enable_log'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function highThreshold(mixed $value, int $normalThreshold, int $default, bool $allowZero = false): int
    {
        $threshold = $this->boundedInt($value, $allowZero ? 0 : 1, 100000);
        if (($allowZero && $threshold === 0) || $normalThreshold === 0 || $threshold < $normalThreshold) {
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

    private function clientIp(Request $request): string
    {
        $ip = trim((string) $request->header('CF-Connecting-IP'));
        if ($ip === '') {
            $ip = trim(explode(',', (string) $request->header('X-Forwarded-For'))[0]);
        }
        return $ip !== '' ? $ip : (string) $request->ip();
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
        // semicolons are accepted as equivalent separators.
        $items = preg_split('/[\r\n\t ,;，；]+/u', trim((string) $value)) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
    }
}
