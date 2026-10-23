<?php

namespace Plugin\InviteTrafficReward;

use App\Models\Plugin as PluginModel;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Plugin\InviteTrafficReward\Services\OneTimePlanOptions;

class Plugin extends AbstractPlugin
{
    private const SCRIPT_ID = 'invite-traffic-reward-plugin-script';
    private const LAST_REWARD_PLAN_SETTING = 'invite_traffic_reward_last_plan_id';

    public function boot(): void
    {
        $this->syncOneTimePlanOptions();
        $this->syncRewardPlanUsers();

        $theme = admin_setting('frontend_theme', 'Xboard');
        $settingKey = 'theme_' . $theme;
        $themeConfig = admin_setting($settingKey, []);
        $themeConfig = is_array($themeConfig) ? $themeConfig : [];
        $html = (string) ($themeConfig['custom_html'] ?? '');

        if (str_contains($html, 'id="' . self::SCRIPT_ID . '"') && str_contains($html, 'invite-traffic-reward.js?v=1.0.30')) {
            return;
        }

        $html = preg_replace(
            '#\s*<script\s+id="' . self::SCRIPT_ID . '"\s+src="/plugins/invite_traffic_reward/invite-traffic-reward\.js\?v=[^"]+"\s+defer></script>#',
            '',
            $html
        );
        $themeConfig['custom_html'] = rtrim($html) . PHP_EOL
            . '<script id="' . self::SCRIPT_ID . '" src="/plugins/invite_traffic_reward/invite-traffic-reward.js?v=1.0.30" defer></script>';
        admin_setting([$settingKey => $themeConfig]);
    }

    public function cleanup(): void
    {
        $theme = admin_setting('frontend_theme', 'Xboard');
        $settingKey = 'theme_' . $theme;
        $themeConfig = admin_setting($settingKey, []);
        $themeConfig = is_array($themeConfig) ? $themeConfig : [];
        $themeConfig['custom_html'] = preg_replace(
            '#\s*<script\s+id="' . self::SCRIPT_ID . '"\s+src="/plugins/invite_traffic_reward/invite-traffic-reward\.js\?v=[^"]+"\s+defer></script>#',
            '',
            (string) ($themeConfig['custom_html'] ?? '')
        );
        admin_setting([$settingKey => $themeConfig]);
        admin_setting([self::LAST_REWARD_PLAN_SETTING => null]);
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin) {
            return;
        }

        $config = json_decode((string) $plugin->config, true) ?: [];
        if (($config['reward_title'] ?? null) !== '邀请流量返利') {
            return;
        }

        $config['reward_title'] = '邀请奖励';
        $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE);
        $plugin->save();
    }

    private function syncOneTimePlanOptions(): void
    {
        try {
            $configPath = $this->getBasePath() . '/config.json';
            $config = json_decode((string) File::get($configPath), true);
            if (!is_array($config) || !isset($config['config']['no_plan_reward_plan_id'])) {
                return;
            }

            $options = OneTimePlanOptions::forAdminConfig();
            $currentOptions = $config['config']['no_plan_reward_plan_id']['options'] ?? [];
            if ($currentOptions === $options) {
                return;
            }

            $config['config']['no_plan_reward_plan_id']['options'] = $options;
            File::put($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        } catch (\Throwable $e) {
            Log::warning('Invite traffic reward: failed to refresh one-time plan options', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Keeps users of the previous reward plan on the newly selected reward plan.
     * Only plan_id is changed: traffic counters, quota, expiry and trial data are untouched.
     */
    private function syncRewardPlanUsers(): void
    {
        try {
            $values = json_decode((string) PluginModel::query()
                ->where('code', $this->getPluginCode())
                ->value('config'), true) ?: [];
            $currentPlanId = (int) ($values['no_plan_reward_plan_id'] ?? 0);
            if (!OneTimePlanOptions::contains($currentPlanId)) {
                return;
            }

            $previousPlanId = (int) admin_setting(self::LAST_REWARD_PLAN_SETTING, 0);
            if ($previousPlanId < 1) {
                admin_setting([self::LAST_REWARD_PLAN_SETTING => $currentPlanId]);
                return;
            }
            if ($previousPlanId === $currentPlanId) {
                return;
            }

            $updatedUsers = DB::table('v2_user')
                ->where('plan_id', $previousPlanId)
                ->update(['plan_id' => $currentPlanId]);
            admin_setting([self::LAST_REWARD_PLAN_SETTING => $currentPlanId]);
            Log::info('Invite traffic reward: reward plan switched for existing users', [
                'from_plan_id' => $previousPlanId,
                'to_plan_id' => $currentPlanId,
                'updated_users' => $updatedUsers,
            ]);
        } catch (\Throwable $e) {
            // Keep the previous marker so a failed migration can be retried safely.
            Log::warning('Invite traffic reward: failed to switch reward plan users', ['error' => $e->getMessage()]);
        }
    }
}
