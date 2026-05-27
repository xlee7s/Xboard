<?php

namespace Plugin\ResetSubscribe;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\DB;

class Plugin extends AbstractPlugin
{
    public function boot(): void {}
    public function install(): void {}
    public function cleanup(): void {}

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {

            $status = (bool)$this->getConfig('status', false);

            if ($status) {

                // ===== 防重复执行锁 =====
                $lockKey = 'plugin_reset_subscribe_lock';

                if (!cache()->add($lockKey, 1, 3600)) {
                    return;
                }

                try {

                    // 是否开启日志
                    $enableLog = (bool)$this->getConfig('enable_log', true);

                    // 间隔分钟
                    $minutes = max(
                        (int)$this->getConfig('interval_minutes', 60),
                        1
                    );

                    $lastRunKey = 'plugin_reset_subscribe_last_run';

                    $lastRun = cache()->get($lastRunKey, 0);

                    $now = time();

                    // 未达到执行间隔
                    if (($now - $lastRun) < ($minutes * 60)) {
                        return;
                    }

                    $total = User::count();

                    if ($enableLog) {
                        info("ResetSubscribe start reset token, total users: {$total}");
                    }

                    // =====================================================
                    // 🟢 小规模（<=5k）
                    // =====================================================
                    if ($total <= 5000) {

                        User::query()
                            ->chunkById(1000, function ($users) {

                                foreach ($users as $user) {

                                    // 仅重置订阅 token
                                    $user->token = Helper::guid();

                                    $user->save();
                                }
                            });
                    }

                    // =====================================================
                    // 🟡 中规模（5k ~ 200k）
                    // =====================================================
                    elseif ($total <= 200000) {

                        User::query()
                            ->chunkById(2000, function ($users) {

                                $casesToken = [];
                                $ids = [];

                                foreach ($users as $user) {

                                    $token = Helper::guid();

                                    $casesToken[] =
                                        "WHEN {$user->id} THEN '{$token}'";

                                    $ids[] = $user->id;
                                }

                                $idList = implode(',', $ids);

                                DB::update("
                                    UPDATE users
                                    SET 
                                        token = CASE id
                                            " . implode(' ', $casesToken) . "
                                        END
                                    WHERE id IN ({$idList})
                                ");
                            });
                    }

                    // =====================================================
                    // 🔴 超大规模（200k+）
                    // =====================================================
                    else {

                        User::query()
                            ->select(['id'])
                            ->chunkById(5000, function ($users) {

                                DB::transaction(function () use ($users) {

                                    $casesToken = [];
                                    $ids = [];

                                    foreach ($users as $user) {

                                        $token = Helper::guid();

                                        $casesToken[] =
                                            "WHEN {$user->id} THEN '{$token}'";

                                        $ids[] = $user->id;
                                    }

                                    $idList = implode(',', $ids);

                                    DB::update("
                                        UPDATE users
                                        SET
                                            token = CASE id
                                                " . implode(' ', $casesToken) . "
                                            END
                                        WHERE id IN ({$idList})
                                    ");
                                });
                            });
                    }

                    // 更新最后执行时间
                    cache()->put(
                        $lastRunKey,
                        $now,
                        now()->addDays(7)
                    );

                    if ($enableLog) {
                        info("ResetSubscribe completed");
                    }

                } finally {

                    // 释放锁
                    cache()->forget($lockKey);
                }
            }

        })->everyMinute();
    }
}