<?php

namespace Plugin\ScheduledMailCampaign;

use App\Models\User;
use App\Services\MailService;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const CACHE_PREFIX = 'plugin:scheduled_mail_campaign:';

    public function boot(): void {}
    public function install(): void {}
    public function cleanup(): void {}

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->queueMailBatch())
            ->name('scheduled-mail-campaign')
            ->everyMinute()
            // The callback sends synchronously. Keep a scheduler-level mutex
            // until the full batch exits; the cache lock below is a second guard.
            ->withoutOverlapping(10080);
    }

    private function queueMailBatch(): void
    {
        if (!$this->getBooleanConfig('status', false)) {
            return;
        }

        $campaignId = min(max((int) $this->getConfig('campaign_id', 1), 1), 999999999);
        $keys = $this->stateKeys($campaignId);
        $batchSize = min(max((int) $this->getConfig('batch_size', 20), 1), 100);
        $sendInterval = min(max((int) $this->getConfig('send_interval_seconds', 3), 1), 300);
        // Each email may wait for its SMTP request. Keep the lock for the full
        // batch duration so the next scheduler tick cannot start a second batch.
        $lockSeconds = (($batchSize - 1) * $sendInterval) + ($batchSize * 300) + 600;

        if (!Cache::add($keys['lock'], 1, now()->addSeconds($lockSeconds))) {
            return;
        }

        try {
            $enableLog = $this->getBooleanConfig('enable_log', true);
            $intervalDays = min(max((int) $this->getConfig('interval_days', 1), 1), 3650);
            $lastCompletedAt = (int) Cache::get($keys['last_completed_at'], 0);

            if ($lastCompletedAt > 0 && (time() - $lastCompletedAt) < $intervalDays * 86400) {
                return;
            }

            $cursor = (int) Cache::get($keys['cursor'], 0);
            $excludedIds = $this->parseList($this->getConfig('excluded_user_ids', ''));
            $excludedEmails = $this->parseList($this->getConfig('excluded_emails', ''));

            $users = $this->recipientQuery($cursor, $excludedIds, $excludedEmails)
                ->limit($batchSize)
                ->get()
                ->values();

            if ($users->isEmpty()) {
                Cache::put($keys['last_completed_at'], time(), now()->addDays($intervalDays + 7));
                Cache::forget($keys['cursor']);
                $this->log($enableLog, 'info', 'ScheduledMailCampaign completed campaign', [
                    'campaign_id' => $campaignId,
                ]);
                return;
            }

            $subject = trim((string) $this->getConfig('email_subject', '系统通知')) ?: '系统通知';
            $content = (string) $this->getConfig('email_content', '');
            $template = trim((string) $this->getConfig('email_template', 'cronnotify')) ?: 'cronnotify';
            $stateTtl = now()->addDays($intervalDays + 7);
            $sent = 0;
            $failed = 0;
            $recipientCount = $users->count();

            // This intentionally sends in-process, one recipient at a time.
            // Do not replace with queued jobs: the configured sleep interval is
            // the delivery interval, not merely a queue dispatch delay.
            @set_time_limit(0);

            for ($index = 0; $index < $recipientCount; $index++) {
                $user = $users[$index];
                $mailLog = MailService::sendEmail([
                    'email' => $user->email,
                    'subject' => $subject,
                    'template_name' => $template,
                    'template_value' => [
                        // Treat administrator-entered campaign content as text, not executable HTML.
                        'content' => $content,
                        'content_mode' => 'text',
                        'subject' => $subject,
                        'name' => admin_setting('app_name', 'XBoard'),
                        'url' => admin_setting('app_url', ''),
                    ],
                ]);

                if ($mailLog['error']) {
                    $failed++;
                    Log::warning('ScheduledMailCampaign mail failed', [
                        'campaign_id' => $campaignId,
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'error' => $mailLog['error'],
                    ]);
                } else {
                    $sent++;
                }

                $cursor = $user->id;
                Cache::put($keys['cursor'], $cursor, $stateTtl);

                if ($sendInterval > 0 && $index < ($recipientCount - 1)) {
                    sleep($sendInterval);
                }
            }

            $this->log($enableLog, 'info', 'ScheduledMailCampaign sent mail batch', [
                'campaign_id' => $campaignId,
                'sent' => $sent,
                'failed' => $failed,
                'cursor' => $cursor,
                'send_interval_seconds' => $sendInterval,
            ]);
        } catch (\Throwable $e) {
            Log::error('ScheduledMailCampaign failed to queue mail batch', [
                'campaign_id' => $campaignId,
                'exception' => $e->getMessage(),
            ]);
        } finally {
            Cache::forget($keys['lock']);
        }
    }

    /**
     * @param array<int, string> $excludedIds
     * @param array<int, string> $excludedEmails
     */
    private function recipientQuery(int $cursor, array $excludedIds, array $excludedEmails): Builder
    {
        $query = User::query()
            ->select(['id', 'email'])
            ->where('id', '>', $cursor)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id');

        if ($this->getBooleanConfig('only_active_users', false)) {
            $query->where('banned', 0)
                ->where('transfer_enable', '>', 0)
                ->where(function (Builder $builder): void {
                    $builder->where('expired_at', '>', time())
                        ->orWhereNull('expired_at');
                });
        }

        if ($excludedIds !== []) {
            $query->whereNotIn('id', array_map('intval', $excludedIds));
        }

        if ($excludedEmails !== []) {
            $query->whereNotIn('email', array_map('mb_strtolower', $excludedEmails));
        }

        return $query;
    }

    /** @return array{lock: string, cursor: string, last_completed_at: string} */
    private function stateKeys(int $campaignId): array
    {
        $prefix = self::CACHE_PREFIX . $campaignId . ':';

        return [
            'lock' => $prefix . 'lock',
            'cursor' => $prefix . 'cursor',
            'last_completed_at' => $prefix . 'last_completed_at',
        ];
    }

    /** @return array<int, string> */
    private function parseList(mixed $value): array
    {
        $items = preg_split('/[\s,;，；]+/u', trim((string) $value)) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
    }

    private function getBooleanConfig(string $key, bool $default): bool
    {
        return filter_var($this->getConfig($key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function log(bool $enabled, string $level, string $message, array $context = []): void
    {
        if ($enabled || $level === 'error') {
            Log::{$level}($message, $context);
        }
    }
}
