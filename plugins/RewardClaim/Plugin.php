<?php

namespace Plugin\RewardClaim;

use App\Models\Plugin as PluginModel;
use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    private const SCRIPT_ID = 'reward-claim-plugin-script';

    public function boot(): void
    {
        $theme = admin_setting('frontend_theme', 'Xboard');
        $settingKey = 'theme_' . $theme;
        $themeConfig = admin_setting($settingKey, []);
        $themeConfig = is_array($themeConfig) ? $themeConfig : [];
        $html = (string) ($themeConfig['custom_html'] ?? '');

        if (str_contains($html, 'id="' . self::SCRIPT_ID . '"') && str_contains($html, 'reward.js?v=1.1.12')) {
            return;
        }

        $html = preg_replace(
            '#\s*<script\s+id="' . self::SCRIPT_ID . '"\s+src="/plugins/reward_claim/reward\.js\?v=[^"]+"\s+defer></script>#',
            '',
            $html
        );
        $themeConfig['custom_html'] = rtrim($html) . PHP_EOL
            . '<script id="' . self::SCRIPT_ID . '" src="/plugins/reward_claim/reward.js?v=1.1.12" defer></script>';
        admin_setting([$settingKey => $themeConfig]);
    }

    public function install(): void
    {
    }

    public function cleanup(): void
    {
        $theme = admin_setting('frontend_theme', 'Xboard');
        $settingKey = 'theme_' . $theme;
        $themeConfig = admin_setting($settingKey, []);
        $themeConfig = is_array($themeConfig) ? $themeConfig : [];

        if (!isset($themeConfig['custom_html'])) {
            return;
        }

        $themeConfig['custom_html'] = preg_replace(
            '#\s*<script\s+id="' . self::SCRIPT_ID . '"\s+src="/plugins/reward_claim/reward\.js\?v=[^"]+"\s+defer></script>#',
            '',
            (string) $themeConfig['custom_html']
        );
        admin_setting([$settingKey => $themeConfig]);
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $plugin = PluginModel::query()->where('code', 'reward_claim')->first();
        if (!$plugin) {
            return;
        }

        $config = json_decode((string) $plugin->config, true) ?: [];
        $legacyDescriptions = [
            '领取后将立即增加到您的可用总流量中。',
            '领取后将在设定范围内随机减免您的已用流量，不增加套餐总流量。',
        ];
        if (in_array($config['reward_description'] ?? null, $legacyDescriptions, true)) {
            $config['reward_description'] = '领取后将在设定范围内随机减免您的已用流量。';
            $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE);
            $plugin->save();
        }
    }
}
