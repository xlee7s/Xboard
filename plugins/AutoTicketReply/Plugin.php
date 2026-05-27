<?php

namespace Plugin\AutoTicketReply;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void {}
    public function install(): void {}
    public function cleanup(): void {}

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {

            // =========================
            // 分布式锁
            // =========================
            $lockKey = 'plugin:auto_ticket_reply:lo2ck';

            if (!cache()->add($lockKey, 1, 300)) {
                return;
            }

            try {

                // =========================
                // 插件开关（默认关闭）
                // =========================
                if (!(bool)$this->getConfig('status', false)) {
                    return;
                }

                // =========================
                // 日志开关（默认开启）
                // =========================
                $enableLog = (bool)$this->getConfig('enable_log', true);

                // =========================
                // 配置
                // =========================
                $adminUserId = (int)$this->getConfig('admin_user_id', 1);

                $defaultReply = trim(
                    (string)$this->getConfig(
                        'default_reply',
                        '您好，工单已收到，我们会尽快处理。'
                    )
                );

                $keywordRulesRaw = (string)$this->getConfig('keyword_rules', '');

                // =========================
                // 解析关键词规则
                // 退款|退钱=>xxx
                // =========================
                $rules = [];

                foreach (explode("\n", $keywordRulesRaw) as $line) {

                    $line = trim($line);

                    if (!$line || !str_contains($line, '=>')) {
                        continue;
                    }

                    [$keys, $reply] = explode('=>', $line, 2);

                    $rules[] = [
                        'keys' => explode('|', trim($keys)),
                        'reply' => trim($reply)
                    ];
                }

                // =========================
                // 查找待回复工单
                // v2_ticket: status=0 未关闭
                // reply_status=1 待回复
                // =========================
                $tickets = Ticket::query()
                    ->where('status', 0)
                    ->where('reply_status', 1)
                    ->orderBy('id', 'asc')
                    ->limit(50)
                    ->get();

                foreach ($tickets as $ticket) {

                    DB::beginTransaction();

                    try {

                        // =========================
                        // 获取用户最后一条消息
                        // =========================
                        $lastMessage = TicketMessage::query()
                            ->where('ticket_id', $ticket->id)
                            ->orderByDesc('id')
                            ->first();

                        if (!$lastMessage) {
                            DB::rollBack();
                            continue;
                        }

                        $userMessage = trim($lastMessage->message);

                        // =========================
                        // 默认回复
                        // =========================
                        $replyMessage = $defaultReply;

                        // =========================
                        // 关键词匹配（包含）
                        // =========================
                        foreach ($rules as $rule) {

                            foreach ($rule['keys'] as $kw) {

                                $kw = trim($kw);

                                if (!$kw) continue;

                                if (mb_stripos($userMessage, $kw) !== false) {
                                    $replyMessage = $rule['reply'];
                                    break 2;
                                }
                            }
                        }

                        // =========================
                        // 写入工单回复（关键：无 is_me）
                        // =========================
                        TicketMessage::create([
                            'ticket_id'  => $ticket->id,
                            'user_id'    => $adminUserId,
                            'message'    => $replyMessage,
                            'created_at' => time(),
                            'updated_at' => time(),
                        ]);

                        // =========================
                        // 更新工单状态
                        // reply_status = 0 已回复
                        // =========================
                        $ticket->reply_status = 0;
                        $ticket->updated_at = time();
                        $ticket->save();

                        // =========================
                        // 日志
                        // =========================
                        if ($enableLog) {
                            
                            info("AutoTicketReply ticket_id={$ticket->id} OK");
                        }

                        DB::commit();

                    } catch (\Throwable $e) {

                        DB::rollBack();

                        if ($enableLog) {
                            info("AutoTicketReply ticket_id={$ticket->id} ERROR");
                        }
                    }
                }

            } finally {
                cache()->forget($lockKey);
            }

        })->everyMinute();
    }
}