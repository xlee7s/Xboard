<?php

namespace Plugin\SubscriptionRiskControl;

use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Jobs\SendEmailJob;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Plugin\SubscriptionRiskControl\Services\SubscriptionRiskService;

class Plugin extends AbstractPlugin
{
    private const ASSET_VERSION = '2.2.5';

    public function boot(): void
    {
        $this->migrateHighRiskDefaults();
        $this->normalizeHighRiskThresholds();
        $this->removeLegacyManualReleaseConfig();
        $this->ensureAllowedUserAgentDefaults();
        $this->ensureTrustedProxyDefaults();
        $this->ensurePublishedAssets();
        $this->registerAdminMenuInjector();
        $this->registerResetNoticeInterceptor();
        $this->filter('client.subscribe.servers', [$this, 'inspectSubscription'], 1);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->runMaintenance())->everyMinute();
        $schedule->call(fn () => $this->prunePullLogs())->dailyAt('03:20')->withoutOverlapping();
    }

    /**
     * The hook is reached only after Xboard has authenticated the subscription
     * request and confirmed that the user is available.
     */
    public function inspectSubscription(array $servers, User $user, Request $request): array
    {
        if (!$this->getBooleanConfig('status', false)) {
            return $servers;
        }

        try {
            $decision = $this->service()->inspect($user, $request, $this->getConfig());
        } catch (\Throwable $e) {
            // A risk-control failure must never break a valid subscription response.
            Log::error('SubscriptionRiskControl inspection failed', ['error' => $e->getMessage()]);
            return $servers;
        }

        if ($decision === null) {
            return $servers;
        }

        if (($decision['reset'] ?? false) && $this->getBooleanConfig('send_reset_email', true)) {
            $this->queueResetEmail($decision);
        }

        if ($decision['action'] === 'ua_blocked') {
            $this->intercept(response()
                ->view('SubscriptionRiskControl::ua-blocked', [
                    'app_name' => (string) admin_setting('app_name', 'XBoard'),
                ], 403)
                ->header('Cache-Control', 'no-store, private')
            );
        }

        $this->intercept(response()
            ->view('SubscriptionRiskControl::risk-reset', [
                'app_name' => (string) admin_setting('app_name', 'XBoard'),
                'is_high_risk' => $decision['action'] === 'token_uuid',
            ], 403)
            ->header('Cache-Control', 'no-store, private')
        );
    }

    /** @param array{action: string, email?: string} $decision */
    private function queueResetEmail(array $decision): void
    {
        $email = trim((string) ($decision['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $highRisk = $decision['action'] === 'token_uuid';
        $prefix = $highRisk ? 'high' : 'normal';
        $subject = $this->mailText(
            $prefix . '_reset_email_subject',
            $highRisk ? '订阅链接与设备认证已重置' : '订阅链接已重置'
        );
        $content = $this->mailText(
            $prefix . '_reset_email_content',
            $highRisk
                ? "系统检测您的订阅链接已经严重泄露滥用，现已重置订阅链接和设备认证信息。\n\n目前所有已导入设备都已无法使用，请登录官网仪表盘获取最新订阅链接后重新使用。"
                : "订阅拉取异常，疑似订阅泄露，现已重置订阅链接。\n\n此操作不影响已有设备使用；如需导入新设备或更新订阅，请登录官网仪表盘获取最新订阅链接。"
        );
        $template = $this->mailTemplate($prefix . '_reset_email_template');

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
                        'risk_level' => $highRisk ? '高危异常' : '普通异常',
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SubscriptionRiskControl failed to queue reset email', [
                'email' => $email,
                'action' => $decision['action'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function mailText(string $key, string $default): string
    {
        $value = trim((string) $this->getConfig($key, $default));
        return $value === '' ? $default : $value;
    }

    private function mailTemplate(string $key): string
    {
        $template = trim((string) $this->getConfig($key, 'cronnotify'));
        return preg_match('/^[A-Za-z0-9_-]+$/', $template) ? $template : 'cronnotify';
    }

    private function runMaintenance(): void
    {
        if (!$this->getBooleanConfig('status', false)) {
            return;
        }

        try {
            $this->service()->runMaintenance($this->getConfig());
        } catch (\Throwable $e) {
            Log::error('SubscriptionRiskControl maintenance failed', ['error' => $e->getMessage()]);
        }
    }

    private function prunePullLogs(): void
    {
        try {
            $this->service()->pruneRequestLogs($this->getConfig());
        } catch (\Throwable $e) {
            Log::error('SubscriptionRiskControl pull-log retention failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Previous shipped high-risk limits could never be reached before the
     * lower normal-risk limits reset the Token. Only migrate those exact
     * defaults, leaving administrator-defined values untouched.
     */
    private function migrateHighRiskDefaults(): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin) {
            return;
        }

        $config = json_decode((string) $plugin->config, true) ?: [];
        if (($config['_subscription_risk_defaults_version'] ?? '') === '1.4.3') {
            return;
        }

        $legacy = [
            'high_request_limit' => [30, 40],
            'high_ip_limit' => [10, 15],
            'high_user_agent_limit' => [10, 15],
        ];
        $updated = ['high_request_limit' => 8, 'high_ip_limit' => 4, 'high_user_agent_limit' => 4];
        foreach ($updated as $key => $value) {
            $isLegacy = in_array((int) ($config[$key] ?? 0), $legacy[$key], true);
            if (!array_key_exists($key, $config) || $isLegacy) {
                $config[$key] = $value;
            }
        }

        $config['_subscription_risk_defaults_version'] = '1.4.3';
        $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE);
        $plugin->save();
        $this->setConfig($config);
    }

    private function removeLegacyManualReleaseConfig(): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin) {
            return;
        }

        $config = json_decode((string) $plugin->config, true) ?: [];
        if (!array_key_exists('manual_release_user_ids', $config)) {
            return;
        }

        unset($config['manual_release_user_ids']);
        $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE);
        $plugin->save();
        $this->setConfig($config);
    }

    /**
     * Existing installations predate allowed_user_agents. Persist the shipped
     * baseline once so the configuration page is truthful and an empty saved
     * value cannot accidentally block every subscription client.
     */
    private function ensureAllowedUserAgentDefaults(): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin) {
            return;
        }

        $config = json_decode((string) $plugin->config, true) ?: [];
        $saved = trim((string) ($config['allowed_user_agents'] ?? ''));
        if ($saved === '') {
            $config['allowed_user_agents'] = SubscriptionRiskService::defaultAllowedUserAgents();
        } elseif ($this->containsAllListEntries($saved, SubscriptionRiskService::legacyDefaultAllowedUserAgents())) {
            // Existing installations received the 39-item baseline before
            // 2.2.4. Append only the new shipped families, preserving every
            // custom entry and never restoring a baseline term the admin
            // deliberately removed.
            $expanded = implode("\n", array_values(array_unique(array_merge(
                $this->splitListEntries($saved),
                $this->splitListEntries(SubscriptionRiskService::defaultAllowedUserAgents())
            ))));
            if ($expanded === $saved) {
                return;
            }
            $config['allowed_user_agents'] = $expanded;
        } else {
            return;
        }
        $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE);
        $plugin->save();
        $this->setConfig($config);
    }

    private function containsAllListEntries(string $saved, string $expected): bool
    {
        $current = array_flip(array_map('mb_strtolower', $this->splitListEntries($saved)));
        foreach ($this->splitListEntries($expected) as $keyword) {
            if (!isset($current[mb_strtolower($keyword)])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Existing installations had the full IPv4 Cloudflare baseline but no
     * IPv6 proxy ranges. Upgrade only that untouched baseline; a deliberately
     * customised proxy list remains exactly as the administrator entered it.
     */
    private function ensureTrustedProxyDefaults(): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin) {
            return;
        }

        $config = json_decode((string) $plugin->config, true) ?: [];
        $saved = trim((string) ($config['trusted_proxy_ips'] ?? ''));
        $expected = implode("\n", SubscriptionRiskService::legacyDefaultTrustedProxyIps());
        $defaults = implode("\n", SubscriptionRiskService::defaultTrustedProxyIps());
        if ($saved === '') {
            $config['trusted_proxy_ips'] = $defaults;
        } elseif ($this->containsAllListEntries($saved, $expected)) {
            $expanded = implode("\n", array_values(array_unique(array_merge(
                $this->splitListEntries($saved),
                $this->splitListEntries($defaults)
            ))));
            if ($expanded === $saved) {
                return;
            }
            $config['trusted_proxy_ips'] = $expanded;
        } else {
            return;
        }
        $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE);
        $plugin->save();
        $this->setConfig($config);
    }

    /** @return array<int, string> */
    private function splitListEntries(string $value): array
    {
        $normalized = str_replace(['\\r\\n', '\\n', '\\r'], "\n", trim($value));
        $items = preg_split('/[\r\n\t ,;，；]+/u', $normalized) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
    }

    /** Keep high-risk thresholds strictly below their normal-risk counterparts. */
    private function normalizeHighRiskThresholds(): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin) {
            return;
        }

        $config = json_decode((string) $plugin->config, true) ?: [];
        $updated = false;
        $normalWindow = max(5, (int) ($config['normal_window_minutes'] ?? 10));
        if (array_key_exists('normal_window_minutes', $config) && (int) $config['normal_window_minutes'] < 5) {
            $config['normal_window_minutes'] = 5;
            $updated = true;
        }
        $normal = [
            'high_request_limit' => max(2, (int) ($config['normal_request_limit'] ?? 12)),
            'high_ip_limit' => max(0, (int) ($config['normal_ip_limit'] ?? 6)),
            'high_user_agent_limit' => max(0, (int) ($config['normal_user_agent_limit'] ?? 6)),
        ];
        $defaults = ['high_request_limit' => 8, 'high_ip_limit' => 4, 'high_user_agent_limit' => 4];

        if (array_key_exists('high_window_minutes', $config) && (int) $config['high_window_minutes'] >= $normalWindow) {
            $config['high_window_minutes'] = 1;
            $updated = true;
        }

        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $value = (int) $config[$key];
            // A zero IP/UA value explicitly disables that high-risk signal.
            // When the normal counterpart is disabled, high risk must also be
            // disabled so an optional rule cannot bypass its normal setting.
            if ($key !== 'high_request_limit' && $normal[$key] === 0) {
                if ($value !== 0) {
                    $config[$key] = 0;
                    $updated = true;
                }
                continue;
            }
            if (($key !== 'high_request_limit' && $value === 0) || $value < $normal[$key]) {
                continue;
            }
            $config[$key] = max($key === 'high_request_limit' ? 1 : 0, min($default, $normal[$key] - 1));
            $updated = true;
        }

        if (!$updated) {
            return;
        }

        $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE);
        $plugin->save();
        $this->setConfig($config);
    }

    private function service(): SubscriptionRiskService
    {
        return app(SubscriptionRiskService::class);
    }

    /**
     * RouteMatched runs before the route's `client` middleware. It lets this
     * plugin recognise a Token it deliberately invalidated and return the
     * helpful reset page for both the normal and legacy subscribe routes,
     * without changing core authentication behavior for arbitrary invalid
     * subscription links.
     */
    private function registerResetNoticeInterceptor(): void
    {
        $key = 'subscription-risk-control.reset-notice-route-listener';
        if (app()->bound($key)) {
            return;
        }
        app()->instance($key, true);
        app('events')->listen(RouteMatched::class, function (RouteMatched $event): void {
            if (!in_array($event->route->getName(), ['client.subscribe', 'client.subscribe.legacy'], true)) {
                return;
            }
            $token = trim((string) ($event->request->route('token') ?: $event->request->input('token', '')));
            if ($token === '') {
                return;
            }

            try {
                if (!Schema::hasTable('v2_subscription_risk_reset_notices')) {
                    return;
                }
                $notice = DB::table('v2_subscription_risk_reset_notices')
                    ->where('token_hash', hash('sha256', $token))
                    ->where('expires_at', '>=', time())
                    ->first();
                if (!$notice) {
                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('SubscriptionRiskControl reset notice lookup failed', ['error' => $e->getMessage()]);
                return;
            }

            $this->intercept(response()
                ->view('SubscriptionRiskControl::reset-expired', [
                    'app_name' => (string) admin_setting('app_name', 'XBoard'),
                ], 403)
                ->header('Cache-Control', 'no-store, private')
            );
        });
    }

    /**
     * The stock admin panel is a compiled SPA and has no plugin-menu extension
     * point. Injecting this plugin-owned script keeps the menu implementation
     * self-contained and leaves the core admin assets untouched.
     */
    private function registerAdminMenuInjector(): void
    {
        $key = 'subscription-risk-control.admin-menu-listener';
        if (app()->bound($key)) {
            return;
        }
        app()->instance($key, true);

        $listener = fn (object $event) => $this->injectAdminMenu($event->request ?? null, $event->response ?? null);
        app('events')->listen(RequestHandled::class, $listener);

        // Octane dispatches its own completion event instead of the Foundation
        // event, so listen to both without requiring Octane to be installed.
        if (class_exists(\Laravel\Octane\Events\RequestHandled::class)) {
            app('events')->listen(\Laravel\Octane\Events\RequestHandled::class, $listener);
        }
    }

    private function injectAdminMenu(mixed $request, mixed $response): void
    {
        if (!$request instanceof Request || !is_object($response) || !method_exists($response, 'getContent')) {
            return;
        }
        $adminPath = trim((string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), '/');
        if (trim($request->path(), '/') !== $adminPath) {
            return;
        }
        $content = (string) $response->getContent();
        if ($content === '' || str_contains($content, 'subscription-risk-control-admin-menu')) {
            return;
        }
        $script = '<script id="subscription-risk-control-admin-menu" src="/plugins/subscription_risk_control/admin-menu.js?v=' . self::ASSET_VERSION . '" defer></script>';
        $position = strripos($content, '</body>');
        $response->setContent($position === false
            ? $content . $script
            : substr_replace($content, $script . '</body>', $position, 7));
    }

    /**
     * PluginManager publishes assets on first installation but not on upgrades.
     * Keep a plugin-owned version marker so an uploaded upgrade always refreshes
     * its own JavaScript and CSS without modifying the core manager.
     */
    private function ensurePublishedAssets(): void
    {
        $source = $this->getBasePath() . '/resources/assets';
        $destination = public_path('plugins/' . $this->getPluginCode());
        $marker = $destination . '/.subscription-risk-control-assets-version';
        if (!File::isDirectory($source) || (File::exists($marker) && trim((string) File::get($marker)) === self::ASSET_VERSION)) {
            return;
        }
        File::ensureDirectoryExists($destination);
        File::copyDirectory($source, $destination);
        File::put($marker, self::ASSET_VERSION . PHP_EOL);
    }

    private function getBooleanConfig(string $key, bool $default): bool
    {
        return filter_var($this->getConfig($key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

}
