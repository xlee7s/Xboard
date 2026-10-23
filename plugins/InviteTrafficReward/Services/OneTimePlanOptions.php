<?php

namespace Plugin\InviteTrafficReward\Services;

use Illuminate\Support\Facades\DB;

class OneTimePlanOptions
{
    public static function contains(int $planId): bool
    {
        if ($planId < 1) {
            return false;
        }

        foreach (self::forAdminConfig() as $option) {
            if ((int) ($option['value'] ?? 0) === $planId) {
                return true;
            }
        }

        return false;
    }

    public static function forAdminConfig(): array
    {
        $options = [['value' => '0', 'label' => '请选择不限时套餐']];
        try {
            $tryOutPlanId = (int) admin_setting('try_out_plan_id', 0);
            foreach (DB::table('v2_plan')->orderBy('id')->get() as $plan) {
                // A trial plan must never be used as the reward plan: trial users
                // are intentionally classified as users without a package.
                if ($tryOutPlanId > 0 && (int) $plan->id === $tryOutPlanId) {
                    continue;
                }
                $prices = is_string($plan->prices ?? null)
                    ? json_decode($plan->prices, true)
                    : ($plan->prices ?? []);
                $prices = is_array($prices) ? $prices : [];
                $oneTimePrice = $prices['onetime'] ?? ($plan->onetime_price ?? null);
                if ($oneTimePrice === null || !is_numeric($oneTimePrice)) {
                    continue;
                }
                $options[] = ['value' => (string) $plan->id, 'label' => (string) $plan->name];
            }
        } catch (\Throwable) {
            // The default empty option keeps the configuration page usable.
        }

        return $options;
    }
}
