<?php

namespace Plugin\SubscriptionRiskControl\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Models\Plugin as PluginModel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\SubscriptionRiskControl\Services\SubscriptionRiskService;

class SubscriptionRiskController extends Controller
{
    private const WATCHLIST_TABLE = 'v2_subscription_risk_watchlist';
    private const BANLIST_TABLE = 'v2_subscription_risk_banlist';
    private const EVENT_TABLE = 'v2_subscription_risk_events';
    private const REQUEST_TABLE = 'v2_subscription_risk_requests';

    public function summary(): JsonResponse
    {
        $today = strtotime('today');
        $sevenDaysAgo = time() - 7 * 86400;

        return response()->json(['data' => [
            'watching' => DB::table(self::WATCHLIST_TABLE)->count(),
            'today_pulls' => DB::table(self::REQUEST_TABLE)
                ->where('requested_at', '>=', $today)
                ->count(),
            'today_normal' => DB::table(self::EVENT_TABLE)
                ->where('action', 'token')
                ->where('occurred_at', '>=', $today)
                ->count(),
            'today_high' => DB::table(self::EVENT_TABLE)
                ->where('action', 'token_uuid')
                ->where('occurred_at', '>=', $today)
                ->count(),
            'recent_auto_released' => DB::table(self::EVENT_TABLE)
                ->where('action', 'auto_release')
                ->where('occurred_at', '>=', $sevenDaysAgo)
                ->count(),
        ]]);
    }

    public function watchlist(Request $request): JsonResponse
    {
        $request->validate(['keyword' => 'nullable|string|max:255']);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $query = DB::table(self::WATCHLIST_TABLE . ' as watch')
            ->leftJoin('v2_user as user', 'user.id', '=', 'watch.user_id')
            ->orderByDesc('watch.last_abnormal_at');
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $query->where('user.email', 'like', '%' . $keyword . '%');
        }
        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get([
            'watch.user_id', 'user.email', 'watch.first_flagged_at', 'watch.last_abnormal_at',
            'watch.last_action', 'watch.last_reasons', 'watch.hit_count',
        ])->map(fn (object $item): array => $this->watchlistItem($item))->all();

        return response()->json(['data' => [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]]);
    }

    public function banlist(Request $request): JsonResponse
    {
        $request->validate(['keyword' => 'nullable|string|max:255']);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $query = DB::table(self::BANLIST_TABLE . ' as ban')
            ->leftJoin('v2_user as user', 'user.id', '=', 'ban.user_id')
            ->orderByDesc('ban.banned_at');
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $query->where('user.email', 'like', '%' . $keyword . '%');
        }
        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get([
            'ban.user_id', 'user.email', 'ban.reasons', 'ban.hit_count', 'ban.banned_at',
        ])->map(fn (object $item): array => [
            'user_id' => (int) $item->user_id,
            'email' => (string) ($item->email ?? '已删除用户'),
            'reasons' => $this->decodeReasons($item->reasons),
            'hit_count' => (int) $item->hit_count,
            'banned_at' => (int) $item->banned_at,
        ])->all();

        return response()->json(['data' => [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]]);
    }

    /** Per-account subscription-pull totals for the current day. */
    public function pulls(Request $request): JsonResponse
    {
        $request->validate(['keyword' => 'nullable|string|max:255', 'period' => 'nullable|in:day,7d']);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $period = (string) $request->query('period', 'day');
        $from = $period === '7d' ? now()->subDays(7)->timestamp : now()->startOfDay()->timestamp;
        $base = DB::table(self::REQUEST_TABLE . ' as pull')
            ->where('pull.requested_at', '>=', $from);
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $base->leftJoin('v2_user as user', 'user.id', '=', 'pull.user_id')
                ->where('user.email', 'like', '%' . $keyword . '%');
        }
        $total = (clone $base)->distinct()->count('pull.user_id');
        $items = $base
            ->when($keyword === '', fn ($query) => $query->leftJoin('v2_user as user', 'user.id', '=', 'pull.user_id'))
            ->groupBy('pull.user_id', 'user.id', 'user.email')
            ->selectRaw('pull.user_id, user.email, COUNT(*) as pull_count, COUNT(DISTINCT pull.ip) as ip_count, COUNT(DISTINCT pull.user_agent_hash) as user_agent_count, MAX(pull.requested_at) as last_pulled_at')
            ->orderByDesc('pull_count')
            ->orderByDesc('last_pulled_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $item): array => [
                'user_id' => (int) $item->user_id,
                'email' => (string) ($item->email ?? '已删除用户'),
                'pull_count' => (int) $item->pull_count,
                'ip_count' => (int) $item->ip_count,
                'user_agent_count' => (int) $item->user_agent_count,
                'last_pulled_at' => (int) $item->last_pulled_at,
            ])->all();

        return response()->json(['data' => [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'from' => $from,
            'period' => $period,
        ]]);
    }

    /** Every valid subscription pull is retained and can be reviewed here. */
    public function pullLogs(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'keyword' => 'nullable|string|max:255',
        ]);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $query = DB::table(self::REQUEST_TABLE . ' as pull')
            ->leftJoin('v2_user as user', 'user.id', '=', 'pull.user_id');
        if ($request->filled('user_id')) {
            $query->where('pull.user_id', (int) $request->query('user_id'));
        }
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $query->where('user.email', 'like', '%' . $keyword . '%');
        }
        $total = (clone $query)->count();
        $items = $query->orderByDesc('pull.requested_at')->orderByDesc('pull.id')->forPage($page, $perPage)->get([
            'pull.id', 'pull.user_id', 'user.email', 'pull.ip', 'pull.user_agent', 'pull.requested_at',
        ])->map(fn (object $item): array => [
            'id' => (int) $item->id,
            'user_id' => (int) $item->user_id,
            'email' => (string) ($item->email ?? '已删除用户'),
            'ip' => $item->ip,
            'user_agent' => $item->user_agent,
            'requested_at' => (int) $item->requested_at,
        ])->all();

        return response()->json(['data' => [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]]);
    }

    public function events(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:255']);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $query = DB::table(self::EVENT_TABLE . ' as event')
            ->leftJoin('v2_user as user', 'user.id', '=', 'event.user_id');
        if ($request->filled('user_id')) {
            $query->where('event.user_id', (int) $request->query('user_id'));
        }
        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $query->where('user.email', 'like', '%' . $keyword . '%');
        }
        $total = (clone $query)->count();
        $items = $query->orderByDesc('event.occurred_at')->orderByDesc('event.id')
            ->forPage($page, $perPage)
            ->get([
            'event.user_id', 'user.email', 'event.action', 'event.risk_level', 'event.reasons',
            'event.ip', 'event.user_agent', 'event.normal_requests', 'event.normal_ips',
            'event.normal_user_agents', 'event.high_requests', 'event.high_ips',
            'event.high_user_agents', 'event.actor_admin_id', 'event.actor_admin_email', 'event.actor_ip', 'event.occurred_at',
        ])->map(fn (object $item): array => $this->eventItem($item))->all();

        return response()->json(['data' => [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]]);
    }

    public function release(Request $request, SubscriptionRiskService $service): JsonResponse
    {
        $payload = $request->validate(['user_id' => 'required|integer|min:1']);
        $count = $service->releaseUsers([(int) $payload['user_id']], 'manual_api', null, $this->actor($request));

        return response()->json(['data' => [
            'released' => $count > 0,
            'message' => $count > 0 ? '已解除该用户的风控观察名单。' : '该用户当前不在风控观察名单中。',
        ]]);
    }

    public function ban(Request $request, SubscriptionRiskService $service): JsonResponse
    {
        $payload = $request->validate(['user_id' => 'required|integer|min:1']);
        $banned = $service->banWatchedUser((int) $payload['user_id'], $this->actor($request));
        if ($banned) {
            $this->queueAccountActionEmail((int) $payload['user_id'], 'ban');
        }

        return response()->json(['data' => [
            'banned' => $banned,
            'message' => $banned ? '用户已封禁，并已从风控观察名单移除。' : '该用户当前不在风控观察名单中，无法封禁。',
        ]]);
    }

    public function unban(Request $request, SubscriptionRiskService $service): JsonResponse
    {
        $payload = $request->validate(['user_id' => 'required|integer|min:1']);
        $unbanned = $service->unbanUser((int) $payload['user_id'], $this->actor($request));
        if ($unbanned) {
            $this->queueAccountActionEmail((int) $payload['user_id'], 'unban');
        }

        return response()->json(['data' => [
            'unbanned' => $unbanned,
            'message' => $unbanned ? '用户已解封，并已重新加入风控观察名单。' : '该用户当前不在风控封禁名单中，无法解封。',
        ]]);
    }

    private function watchlistItem(object $item): array
    {
        return [
            'user_id' => (int) $item->user_id,
            'email' => (string) ($item->email ?? '已删除用户'),
            'first_flagged_at' => (int) $item->first_flagged_at,
            'last_abnormal_at' => (int) $item->last_abnormal_at,
            'last_action' => (string) $item->last_action,
            'last_reasons' => $this->decodeReasons($item->last_reasons),
            'hit_count' => (int) $item->hit_count,
        ];
    }

    /** @return array{id?: int, email?: string, ip?: string} */
    private function actor(Request $request): array
    {
        $admin = $request->user();
        return [
            'id' => $admin?->id ? (int) $admin->id : null,
            'email' => $admin?->email ? (string) $admin->email : null,
            'ip' => trim((string) ($request->header('CF-Connecting-IP') ?: $request->ip())),
        ];
    }

    private function queueAccountActionEmail(int $userId, string $action): void
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);
        $email = trim((string) ($user?->email ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $config = json_decode((string) PluginModel::query()
            ->where('code', 'subscription_risk_control')
            ->value('config'), true) ?: [];
        $defaults = $action === 'ban'
            ? [
                'subject' => '账户已被封禁',
                'content' => "系统检测到您的订阅存在异常使用，账户现已被封禁，无法登录或使用订阅。\n\n如认为封禁有误，请联系管理员处理。",
                'risk_action' => '账户封禁',
            ]
            : [
                'subject' => '账户已解除封禁',
                'content' => "您的账户已解除封禁，现在可以重新登录和使用订阅。\n\n账户已重新加入风控观察名单，请遵守订阅使用规则。",
                'risk_action' => '账户解封',
            ];
        if (!filter_var($config['send_' . $action . '_email'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $subject = trim((string) ($config[$action . '_email_subject'] ?? $defaults['subject']));
        $content = trim((string) ($config[$action . '_email_content'] ?? $defaults['content']));
        $template = trim((string) ($config[$action . '_email_template'] ?? 'cronnotify'));
        if ($subject === '') {
            $subject = $defaults['subject'];
        }
        if ($content === '') {
            $content = $defaults['content'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $template)) {
            $template = 'cronnotify';
        }

        try {
            SendEmailJob::dispatch([
                'email' => $email,
                'subject' => $subject,
                'template_name' => $template,
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => $content,
                    'subject' => $subject,
                    'vars' => [
                        'app_name' => admin_setting('app_name', 'XBoard'),
                        'dashboard_url' => admin_setting('app_url', ''),
                        'email' => $email,
                        'risk_action' => $defaults['risk_action'],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SubscriptionRiskControl failed to queue account action email', [
                'user_id' => $userId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function eventItem(object $item): array
    {
        return [
            'user_id' => (int) $item->user_id,
            'email' => (string) ($item->email ?? '已删除用户'),
            'action' => (string) $item->action,
            'risk_level' => (string) $item->risk_level,
            'reasons' => $this->decodeReasons($item->reasons),
            'ip' => $item->ip,
            'user_agent' => $item->user_agent,
            'normal' => ['requests' => (int) $item->normal_requests, 'ips' => (int) $item->normal_ips, 'user_agents' => (int) $item->normal_user_agents],
            'high' => ['requests' => (int) $item->high_requests, 'ips' => (int) $item->high_ips, 'user_agents' => (int) $item->high_user_agents],
            'actor' => [
                'id' => $item->actor_admin_id === null ? null : (int) $item->actor_admin_id,
                'email' => $item->actor_admin_email,
                'ip' => $item->actor_ip,
            ],
            'occurred_at' => (int) $item->occurred_at,
        ];
    }

    /** @return array<int, string> */
    private function decodeReasons(mixed $reasons): array
    {
        $decoded = json_decode((string) $reasons, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
