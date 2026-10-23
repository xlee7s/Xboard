<?php

namespace Plugin\InviteTrafficReward\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InviteTrafficRewardController extends Controller
{
    private const PLUGIN_CODE = 'invite_traffic_reward';
    private const BYTES_PER_MB = 1048576;
    private const BYTES_PER_GB = 1073741824;
    private const RECORD_LIMIT = 5;
    private const MAX_EXTENSION_SECONDS = 4294967295;

    public function status(Request $request): JsonResponse
    {
        $config = $this->config();
        $user = User::query()->findOrFail($request->user()->id);

        return $this->success($this->statusData($user, $config));
    }

    public function claim(Request $request): JsonResponse
    {
        $mode = (string) $request->input('mode', '');
        $config = $this->config();

        return DB::transaction(function () use ($request, $config, $mode) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $status = $this->statusData($user, $config);
            if (!$status['can_claim']) {
                return $this->fail([422, $status['message']]);
            }

            $invitee = $this->pendingInvitees($user, $config, true)->first();
            if (!$invitee) {
                return $this->fail([422, '暂时没有可领取的有效邀请。']);
            }

            $rewardType = $this->rewardTypeFor($user, $mode, $config);
            if ($rewardType === null) {
                return $this->fail([422, '当前套餐不支持该领取方式。']);
            }

            $rewardBytes = 0;
            $extensionSeconds = 0;
            $rewardBalance = 0;
            $message = '';
            if ($rewardType === 'plan') {
                $plan = $this->validateOneTimePlan($config['no_plan_reward_plan_id']);
                if (!$plan) {
                    return $this->fail([422, '管理员尚未配置可领取的不限时套餐。']);
                }
                $rewardBytes = $config['reward_bytes'];
                $this->grantOneTimePlan($user, $plan, $rewardBytes);
                $message = sprintf('已领取不限时套餐「%s」，并发放 %s 总流量。', $plan->name, $this->formatBytes($rewardBytes));
            } elseif ($rewardType === 'quota') {
                $rewardBytes = $config['reward_bytes'];
                $this->increaseTotalTraffic($user, $rewardBytes);
                $user->save();
                $message = sprintf('已增加 %s 总流量。', $this->formatBytes($rewardBytes));
            } elseif ($rewardType === 'traffic') {
                if ($user->getTotalUsedTraffic() < $config['reward_bytes']) {
                    return $this->fail([422, '当前已用流量不足一份邀请奖励，暂不可减免。']);
                }
                $rewardBytes = $config['reward_bytes'];
                $this->reduceUsedTraffic($user, $rewardBytes);
                $user->save();
                $message = sprintf('已减免 %s 已用流量。', $this->formatBytes($rewardBytes));
            } elseif ($rewardType === 'balance') {
                $rewardBalance = $config['reward_balance'];
                $this->increaseBalance($user, $rewardBalance);
                $user->save();
                $message = sprintf('已领取 %s 余额。', $this->formatBalance($rewardBalance));
            } else {
                $extensionSeconds = $config['extension_seconds'];
                $base = max((int) ($user->expired_at ?? 0), time());
                $user->expired_at = $base + $extensionSeconds;
                $user->save();
                $message = sprintf('套餐有效期已延长 %s。', $this->formatDuration($extensionSeconds));
            }

            DB::table('v2_invite_traffic_reward_claims')->insert([
                'inviter_user_id' => $user->id,
                'invitee_user_id' => $invitee->id,
                'reward_type' => $rewardType,
                'reward_bytes' => $rewardBytes,
                'extension_seconds' => $extensionSeconds,
                'reward_balance' => $rewardBalance,
                'claimed_at' => time(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            return $this->success([
                'message' => $message,
                'remaining_count' => $this->pendingInvitees($user, $config)->count(),
            ]);
        });
    }

    private function config(): array
    {
        $values = json_decode((string) Plugin::query()->where('code', self::PLUGIN_CODE)->value('config'), true) ?: [];
        $maxMb = intdiv(PHP_INT_MAX, self::BYTES_PER_MB);
        $rewardMb = min(max((int) round((float) ($values['reward_traffic_mb'] ?? 1024)), 1), $maxMb);
        $extensionHours = min(max((float) ($values['extension_hours'] ?? 24), 1 / 60), self::MAX_EXTENSION_SECONDS / 3600);
        $rewardBalance = min(max((int) round((float) ($values['reward_balance_yuan'] ?? 1) * 100), 0), PHP_INT_MAX);
        $startDate = trim((string) ($values['effective_invite_start_date'] ?? ''));
        try {
            $startAt = Carbon::createFromFormat('Y-m-d', $startDate, config('app.timezone'))->startOfDay()->timestamp;
        } catch (\Throwable) {
            $startAt = PHP_INT_MAX;
        }

        $rewardBytes = $rewardMb * self::BYTES_PER_MB;
        $extensionSeconds = min(self::MAX_EXTENSION_SECONDS, max(60, (int) round($extensionHours * 3600)));

        return [
            'start_at' => $startAt,
            'reward_bytes' => $rewardBytes,
            'no_plan_reward_plan_id' => max(0, (int) ($values['no_plan_reward_plan_id'] ?? 0)),
            'extension_seconds' => $extensionSeconds,
            'reward_balance' => $rewardBalance,
            'reward_title' => trim((string) ($values['reward_title'] ?? '邀请奖励')) ?: '邀请奖励',
            'reward_description' => trim((string) ($values['reward_description'] ?? '每邀请一位有效用户，即可领取一次奖励。')),
            'start_date' => $startDate,
        ];
    }

    private function statusData(User $user, array $config): array
    {
        $invitedCount = User::query()->where('invite_user_id', $user->id)->count();
        $validInviteCount = $this->validInvitees($user, $config)->count();
        $claimedCount = DB::table('v2_invite_traffic_reward_claims')
            ->where('inviter_user_id', $user->id)
            ->count();
        $pendingCount = $this->pendingInvitees($user, $config)->count();
        $type = $this->accountType($user);
        $noPlanReason = $this->noPlanReason($user);
        $planReady = $type !== 'none' || $this->validateOneTimePlan($config['no_plan_reward_plan_id']) !== null;
        $hasBalanceReward = $config['reward_balance'] > 0;
        $canReduceTraffic = $type === 'periodic' && $user->getTotalUsedTraffic() >= $config['reward_bytes'];
        $message = match ($type) {
            'none' => $planReady ? ($noPlanReason ?: '当前无套餐，领取将发放后台指定的不限时套餐和奖励总流量。') : ($hasBalanceReward ? '当前无套餐，可领取余额奖励。' : '管理员尚未配置可领取的不限时套餐。'),
            'onetime' => '不限时套餐可选择增加总流量或领取余额。',
            default => '周期套餐可选择减免已用流量、延长有效期或领取余额。',
        };
        if ($type === 'periodic' && !$canReduceTraffic) {
            $message = $hasBalanceReward
                ? '当前已用流量不足一份奖励，暂不能减免；仍可选择延长套餐有效期或领取余额。'
                : '当前已用流量不足一份奖励，暂不能减免；仍可选择延长套餐有效期。';
        }

        return [
            'can_claim' => !$user->banned && $pendingCount > 0 && ($planReady || $hasBalanceReward),
            'message' => $user->banned ? '当前账户不可领取邀请奖励。' : ($pendingCount ? $message : '暂无可领取的有效邀请。'),
            'invited_count' => $invitedCount,
            'valid_invite_count' => $validInviteCount,
            'claimed_count' => $claimedCount,
            'pending_count' => $pendingCount,
            'account_type' => $type,
            'plan_reward_available' => $planReady,
            'can_reduce_traffic' => $canReduceTraffic,
            'no_plan_reason' => $noPlanReason,
            'reward_traffic' => $this->formatBytes($config['reward_bytes']),
            'extension_duration' => $this->formatDuration($config['extension_seconds']),
            'reward_balance' => $this->formatBalance($config['reward_balance']),
            'has_balance_reward' => $hasBalanceReward,
            'start_date' => $config['start_date'],
            'reward_title' => $config['reward_title'],
            'reward_description' => $config['reward_description'],
            'invite_records' => $this->inviteRecords($user, $config),
            'claim_records' => $this->claimRecords($user),
        ];
    }

    private function inviteRecords(User $user, array $config): array
    {
        $claimedInviteeIds = DB::table('v2_invite_traffic_reward_claims')
            ->where('inviter_user_id', $user->id)
            ->pluck('invitee_user_id')
            ->flip();

        return User::query()
            ->where('invite_user_id', $user->id)
            ->orderByDesc('id')
            ->limit(self::RECORD_LIMIT)
            ->get(['id', 'email', 'banned', 'created_at'])
            ->map(function (User $invitee) use ($config, $claimedInviteeIds): array {
                $createdAt = (int) $invitee->getRawOriginal('created_at');
                $status = $createdAt < $config['start_at']
                    ? '起始日期前注册'
                    : ($invitee->banned ? '账户不可用' : (isset($claimedInviteeIds[$invitee->id]) ? '已领取' : '待领取'));

                return [
                    'email' => $this->maskEmail((string) $invitee->email),
                    'time' => $this->formatTimestamp($createdAt),
                    'status' => $status,
                ];
            })
            ->all();
    }

    private function claimRecords(User $user): array
    {
        return DB::table('v2_invite_traffic_reward_claims as claim')
            ->leftJoin('v2_user as invitee', 'invitee.id', '=', 'claim.invitee_user_id')
            ->where('claim.inviter_user_id', $user->id)
            ->orderByDesc('claim.claimed_at')
            ->limit(self::RECORD_LIMIT)
            ->get([
                'claim.reward_type',
                'claim.reward_bytes',
                'claim.extension_seconds',
                'claim.reward_balance',
                'claim.claimed_at',
                'invitee.email as invitee_email',
            ])
            ->map(function (object $claim): array {
                return [
                    'email' => $this->maskEmail((string) ($claim->invitee_email ?? '已删除用户')),
                    'time' => $this->formatTimestamp((int) $claim->claimed_at),
                    'reward' => $this->claimRewardLabel($claim),
                ];
            })
            ->all();
    }

    private function pendingInvitees(User $user, array $config, bool $lock = false)
    {
        $claimedInvitees = DB::table('v2_invite_traffic_reward_claims')->select('invitee_user_id');
        $query = $this->validInvitees($user, $config)
            ->whereNotIn('id', $claimedInvitees)
            ->orderBy('id');

        return $lock ? $query->lockForUpdate() : $query;
    }

    private function validInvitees(User $user, array $config)
    {
        return User::query()
            ->where('invite_user_id', $user->id)
            ->where('created_at', '>=', $config['start_at'])
            ->where('banned', false);
    }

    private function accountType(User $user): string
    {
        if (!$user->plan_id) {
            return 'none';
        }

        // 试用套餐优先于“是否不限时”判断，避免管理员将试用套餐设为不限时后被误判。
        if ($this->isTryOutPlan($user)) {
            return 'none';
        }

        if ($user->expired_at === null) {
            return 'onetime';
        }

        // 过期周期套餐和试用套餐都按无套餐处理，统一领取配置的不限时套餐。
        return $this->noPlanReason($user) === null ? 'periodic' : 'none';
    }

    private function noPlanReason(User $user): ?string
    {
        if (!$user->plan_id) {
            return null;
        }

        if ($this->isTryOutPlan($user)) {
            return '当前为试用套餐，将按无套餐发放指定的不限时套餐。';
        }

        if ($user->expired_at === null) {
            return null;
        }

        if ((int) $user->expired_at <= time()) {
            return '当前周期套餐已到期，将按无套餐发放指定的不限时套餐。';
        }

        return null;
    }

    private function isTryOutPlan(User $user): bool
    {
        $tryOutPlanId = (int) admin_setting('try_out_plan_id', 0);
        return $tryOutPlanId > 0 && (int) $user->plan_id === $tryOutPlanId;
    }

    private function rewardTypeFor(User $user, string $mode, array $config): ?string
    {
        if ($mode === 'balance' && $config['reward_balance'] > 0) {
            return 'balance';
        }

        return match ($this->accountType($user)) {
            'none' => $mode === 'plan' ? 'plan' : null,
            'onetime' => $mode === 'quota' ? 'quota' : null,
            'periodic' => in_array($mode, ['traffic', 'extension'], true) ? $mode : null,
            default => null,
        };
    }

    private function validateOneTimePlan(int $planId): ?Plan
    {
        if ($planId < 1) {
            return null;
        }
        if ($planId === (int) admin_setting('try_out_plan_id', 0)) {
            return null;
        }
        $plan = Plan::query()->find($planId);
        if (!$plan) {
            return null;
        }
        $prices = is_array($plan->prices) ? $plan->prices : [];
        $hasNewOneTimePrice = isset($prices[Plan::PERIOD_ONETIME]) && is_numeric($prices[Plan::PERIOD_ONETIME]);
        $legacyPrice = $plan->getAttribute('onetime_price');
        $hasLegacyOneTimePrice = $legacyPrice !== null && is_numeric($legacyPrice);
        if (!$hasNewOneTimePrice && !$hasLegacyOneTimePrice) {
            return null;
        }
        return $plan;
    }

    private function grantOneTimePlan(User $user, Plan $plan, int $rewardBytes): void
    {
        $user->u = 0;
        $user->d = 0;
        // 套餐仅决定账户权限；总流量完全由插件的每位邀请奖励流量决定。
        $user->transfer_enable = $rewardBytes;
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->speed_limit = $plan->speed_limit;
        $user->device_limit = $plan->device_limit;
        $user->expired_at = null;
        $user->save();
    }

    private function reduceUsedTraffic(User $user, int $bytes): void
    {
        $deductDown = min((int) ($user->d ?? 0), $bytes);
        $user->d = (int) ($user->d ?? 0) - $deductDown;
        $user->u = max(0, (int) ($user->u ?? 0) - ($bytes - $deductDown));
    }

    private function increaseTotalTraffic(User $user, int $bytes): void
    {
        $current = max(0, (int) ($user->transfer_enable ?? 0));
        $user->transfer_enable = $current > PHP_INT_MAX - $bytes ? PHP_INT_MAX : $current + $bytes;
    }

    private function increaseBalance(User $user, int $balance): void
    {
        $current = max(0, (int) ($user->balance ?? 0));
        $user->balance = $current > PHP_INT_MAX - $balance ? PHP_INT_MAX : $current + $balance;
    }

    private function claimRewardLabel(object $claim): string
    {
        return match ($claim->reward_type) {
            'plan' => '领取不限时套餐 +' . $this->formatBytes((int) $claim->reward_bytes),
            'quota' => '增加总流量 ' . $this->formatBytes((int) $claim->reward_bytes),
            'traffic' => '减免已用流量 ' . $this->formatBytes((int) $claim->reward_bytes),
            'extension' => '延长有效期 ' . $this->formatDuration((int) $claim->extension_seconds),
            'balance' => '领取余额 ' . $this->formatBalance((int) $claim->reward_balance),
            default => '已领取奖励',
        };
    }

    private function formatTimestamp(int $timestamp): string
    {
        return $timestamp > 0
            ? Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format('Y-m-d H:i')
            : '-';
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return $email === '' ? '未知用户' : mb_substr($email, 0, 1) . '***';
        }

        $name = substr($email, 0, $at);
        return mb_substr($name, 0, 1) . '***' . substr($email, $at);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= self::BYTES_PER_GB) {
            return rtrim(rtrim(number_format($bytes / self::BYTES_PER_GB, 2, '.', ''), '0'), '.') . ' GB';
        }
        return rtrim(rtrim(number_format($bytes / self::BYTES_PER_MB, 2, '.', ''), '0'), '.') . ' MB';
    }

    private function formatBalance(int $balance): string
    {
        return rtrim(rtrim(number_format($balance / 100, 2, '.', ''), '0'), '.') . ' 元';
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds % 86400 === 0) {
            return intdiv($seconds, 86400) . ' 天';
        }
        return rtrim(rtrim(number_format($seconds / 3600, 2, '.', ''), '0'), '.') . ' 小时';
    }
}
