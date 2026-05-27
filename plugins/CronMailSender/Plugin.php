<?php

namespace Plugin\CronMailSender;

use App\Models\User;
use App\Models\Setting;
use App\Services\MailService;
use Illuminate\Console\Scheduling\Schedule;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Mail;

class Plugin extends AbstractPlugin
{
    public function boot(): void {}
    public function install(): void {}
    public function cleanup(): void {}

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            
            
            $status = (bool)$this->getConfig('status', false);

            if($status){
                // =========================
                // 🔒 防并发锁
                // =========================
                $lockKey = 'cron_email_send_lock';
                if (!cache()->add($lockKey, 1, 864000)) {
                    return;
                }
    
                try {
    
                    // =========================
                    // 📌 配置读取
                    // =========================
                    $days = max((int)$this->getConfig('interval_days', 1), 1);
                    $batchSize = max((int)$this->getConfig('batch_size', 500), 50);
                    $enableLog = (bool)$this->getConfig('enable_log', true);
    
                    $subject = (string)$this->getConfig('email_subject', '系统通知');
                    $content = (string)$this->getConfig('email_content', '系统邮件');
                    $app_name = Setting::where('name', 'app_name')->value('value');
					$app_url = Setting::where('name', 'app_url')->value('value');
                    
                    // =========================
                    // ⏱ 执行周期控制
                    // =========================
                    $lastRunKey = 'cron_email_send_last_run';
                    $lastRun = cache()->get($lastRunKey, 0);
                    $now = time();
    
                    if (($now - $lastRun) < ($days * 86400)) {
                        return;
                    }
    
                    // =========================
                    // 📍 断点游标
                    // =========================
                    $cursorKey = 'cron_email_send_cursor';
                    $lastId = cache()->get($cursorKey, 0);
    
                    if ($enableLog) {
                        info("MailSender batch start from ID={$lastId}");
                    }
    
                    // =========================
                    // 🚀 分批处理（核心）
                    // =========================
                    $users = User::query()
                        ->where('id', '>', $lastId)
                        ->orderBy('id', 'asc')
                        ->limit($batchSize)
                        ->get();
    
                    // 如果没有用户了 => 完成
                    if ($users->isEmpty()) {
    
                        cache()->put($lastRunKey, $now, now()->addDays(365));
                        cache()->put($cursorKey, 0);
    
                        if ($enableLog) {
                            info("[MailSender] ALL USERS FINISHED");
                        }
    
                        cache()->forget($lockKey);
                        return;
                    }
    
                    foreach ($users as $user) {
    
                        try {
    
                            MailService::sendEmail([
                                'email' => $user->email,
                                'subject' => $subject,
                                'template_name' => 'cronnotify',
                                'template_value' => [
                                    'content' => $content,
                                    'subject' => $subject,
                                    'name' => $app_name,
									'url' => $app_url
                                ]
                            ]);
    
                            if ($enableLog) {
                                info("MailSender sent user_id={$user->id}");
                            }
    
                            // =========================
                            // 🐢 2秒限速
                            // =========================
                            sleep(2);
    
                            // 更新游标
                            $lastId = $user->id;
                            cache()->put($cursorKey, $lastId, now()->addDays(7));
    
                        } catch (\Throwable $e) {
                            info("MailSender failed user_id={$user->id}");
                        }
                    }
    
                } finally {
                    cache()->forget($lockKey);
                }

            }

        })->everyMinute();
    }
}