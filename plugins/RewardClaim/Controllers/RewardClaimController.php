<?php

namespace Plugin\RewardClaim\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RewardClaimController extends Controller
{
    private const PLUGIN_CODE = 'reward_claim';

    public function status(Request $request): JsonResponse
    {
        $config = $this->config();
        $user = User::query()->findOrFail($request->user()->id);
        $lastClaim = DB::table('v2_reward_claims')
            ->where('user_id', $user->id)
            ->latest('claimed_at')
            ->first();

        return $this->success($this->statusData($user, $config, $lastClaim?->claimed_at));
    }

    public function claim(Request $request): JsonResponse
    {
        $config = $this->config();

        return DB::transaction(function () use ($request, $config) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $lastClaim = DB::table('v2_reward_claims')
                ->where('user_id', $user->id)
                ->latest('claimed_at')
                ->lockForUpdate()
                ->first();
            $status = $this->statusData($user, $config, $lastClaim?->claimed_at);

            if (!$status['is_eligible']) {
                return $this->fail([422, $status['eligibility_message']]);
            }

            if ($user->getTotalUsedTraffic() < $config['min_reward_bytes']) {
                return $this->fail([422, '当前已用流量不足最小随机减免值，暂不可领取。']);
            }

            if (!$status['can_claim']) {
                return $this->fail([422, '暂不可领取，请在可领取时间后再试。']);
            }

            $reducedBytes = $this->randomRewardBytes($config, $user->getTotalUsedTraffic());
            $now = time();

            $this->reduceUsedTraffic($user, $reducedBytes);
            $user->save();

            DB::table('v2_reward_claims')->insert([
                'user_id' => $user->id,
                'reward_bytes' => $reducedBytes,
                'claimed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->success([
                'message' => sprintf('领取成功，已减免 %s 已用流量。', $this->formatBytes($reducedBytes)),
                'reduced_bytes' => $reducedBytes,
                'next_claim_at' => $now + ((int) $config['claim_interval_hours'] * 3600),
            ]);
        });
    }

    private function config(): array
    {
        $values = json_decode((string) Plugin::query()->where('code', self::PLUGIN_CODE)->value('config'), true) ?: [];
        $maxMbAllowed = intdiv(PHP_INT_MAX, 1024 * 1024);
        $minMb = min(max((int) round((float) ($values['min_reward_mb'] ?? 100)), 1), $maxMbAllowed);
        // max_reward_gb is retained as a fallback for installations upgraded from 1.0.6.
        $maxMb = $values['max_reward_mb'] ?? ((float) ($values['max_reward_gb'] ?? 2) * 1024);
        $maxMb = min(max((int) round((float) $maxMb), $minMb), $maxMbAllowed);
        $minBytes = $minMb * 1024 * 1024;
        $maxBytes = $maxMb * 1024 * 1024;

        return [
            'min_reward_bytes' => $minBytes,
            'max_reward_bytes' => $maxBytes,
            'claim_interval_hours' => min(max((int) ($values['claim_interval_hours'] ?? 24), 1), 8760),
            'reward_title' => trim((string) ($values['reward_title'] ?? '每日流量奖励')) ?: '每日流量奖励',
            'reward_description' => trim((string) ($values['reward_description'] ?? '领取后将在设定范围内随机减免您的已用流量。')),
        ];
    }

    private function statusData(User $user, array $config, ?int $lastClaimAt): array
    {
        $nextClaimAt = $lastClaimAt ? $lastClaimAt + ((int) $config['claim_interval_hours'] * 3600) : null;
        $eligibilityMessage = $this->eligibilityMessage($user);

        return [
            'can_claim' => $eligibilityMessage === null && $user->getTotalUsedTraffic() >= $config['min_reward_bytes'] && ($nextClaimAt === null || $nextClaimAt <= time()),
            'is_eligible' => $eligibilityMessage === null,
            'eligibility_message' => $eligibilityMessage,
            'insufficient_used_traffic' => $user->getTotalUsedTraffic() < $config['min_reward_bytes'],
            'next_claim_at' => $nextClaimAt,
            'reward_range' => [
                'min' => $this->formatBytes($config['min_reward_bytes']),
                'max' => $this->formatBytes($config['max_reward_bytes']),
            ],
            'reward_title' => $config['reward_title'],
            'reward_description' => $config['reward_description'],
        ];
    }

    private function eligibilityMessage(User $user): ?string
    {
        if ($user->banned) {
            return '当前账户不可领取奖励。';
        }

        if (!$user->plan_id) {
            return '仅已购买有效周期套餐的用户可以领取奖励。';
        }

        if ($user->expired_at === null || (int) $user->expired_at <= time()) {
            return '仅未到期的周期套餐用户可以领取奖励；不限时流量包不可领取。';
        }

        $periodicOrder = Order::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $user->plan_id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereIn('period', [
                Plan::PERIOD_MONTHLY,
                Plan::PERIOD_QUARTERLY,
                Plan::PERIOD_HALF_YEARLY,
                Plan::PERIOD_YEARLY,
                Plan::PERIOD_TWO_YEARLY,
                Plan::PERIOD_THREE_YEARLY,
            ])
            ->exists();

        if (!$periodicOrder) {
            return '仅已购买有效周期套餐的用户可以领取奖励。';
        }

        return null;
    }

    private function randomRewardBytes(array $config, int $usedTraffic): int
    {
        $effectiveMax = min($config['max_reward_bytes'], $usedTraffic);
        return random_int($config['min_reward_bytes'], $effectiveMax);
    }

    private function reduceUsedTraffic(User $user, int $bytes): void
    {
        // Deduct downstream usage first, then upstream usage. Neither can become negative.
        $deductDown = min((int) ($user->d ?? 0), $bytes);
        $user->d = (int) ($user->d ?? 0) - $deductDown;
        $user->u = max(0, (int) ($user->u ?? 0) - ($bytes - $deductDown));
    }

    private function formatBytes(int $bytes): string
    {
        $gb = 1024 * 1024 * 1024;
        if ($bytes >= $gb) {
            return rtrim(rtrim(number_format($bytes / $gb, 2, '.', ''), '0'), '.') . ' GB';
        }

        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 2, '.', ''), '0'), '.') . ' MB';
    }
}
