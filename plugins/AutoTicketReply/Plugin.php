<?php

namespace Plugin\AutoTicketReply;

use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const LOCK_KEY = 'plugin:auto_ticket_reply:lock';

    public function boot(): void {}
    public function install(): void {}
    public function cleanup(): void {}

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->processTickets())->everyMinute();
    }

    private function processTickets(): void
    {
        if (!$this->getBooleanConfig('status', false)) {
            return;
        }

        // Ticket rows are also locked below. This lock prevents unnecessary concurrent batches.
        if (!Cache::add(self::LOCK_KEY, 1, now()->addMinutes(10))) {
            return;
        }

        try {
            $enableLog = $this->getBooleanConfig('enable_log', true);
            $admin = $this->getReplyAdmin();
            if (!$admin) {
                $this->log($enableLog, 'error', 'AutoTicketReply skipped: configured reply account is not an administrator');
                return;
            }

            $rules = $this->parseRules((string) $this->getConfig('keyword_rules', ''));
            $defaultReply = trim((string) $this->getConfig('default_reply', ''));
            $onlyKeywordMatch = $this->getBooleanConfig('only_keyword_match', false);
            $maxTickets = min(max((int) $this->getConfig('max_tickets_per_run', 50), 1), 200);

            $ticketIds = Ticket::query()
                ->where('status', Ticket::STATUS_OPENING)
                ->where('reply_status', 1)
                ->orderBy('id')
                ->limit($maxTickets)
                ->pluck('id');

            foreach ($ticketIds as $ticketId) {
                try {
                    $result = $this->replyToTicket(
                        (int) $ticketId,
                        $admin,
                        $rules,
                        $defaultReply,
                        $onlyKeywordMatch
                    );

                    if ($result === null) {
                        continue;
                    }

                    $this->notifyAfterReply($result['ticket'], $result['message'], $result['user'], $enableLog);
                } catch (\Throwable $e) {
                    $this->log($enableLog, 'error', 'AutoTicketReply failed to process ticket', [
                        'ticket_id' => $ticketId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    /**
     * @param array<int, array{keywords: array<int, string>, reply: string}> $rules
     * @return array{ticket: Ticket, message: TicketMessage, user: User}|null
     */
    private function replyToTicket(int $ticketId, User $admin, array $rules, string $defaultReply, bool $onlyKeywordMatch): ?array
    {
        return DB::transaction(function () use ($ticketId, $admin, $rules, $defaultReply, $onlyKeywordMatch): ?array {
            $ticket = Ticket::query()->with('user')->lockForUpdate()->find($ticketId);

            // A staff member or another worker may have replied after this batch selected the ID.
            if (!$ticket || (int) $ticket->status !== Ticket::STATUS_OPENING || (int) $ticket->reply_status !== 1) {
                return null;
            }

            $user = $ticket->user;
            if (!$user instanceof User || $this->isExcluded($user)) {
                return null;
            }

            $lastMessage = TicketMessage::query()
                ->where('ticket_id', $ticket->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Never auto-reply to an administrator's message.
            if (!$lastMessage || (int) $lastMessage->user_id !== (int) $ticket->user_id) {
                return null;
            }

            $reply = $this->matchReply(trim($lastMessage->message), $rules, $defaultReply, $onlyKeywordMatch);
            if ($reply === null) {
                return null;
            }

            $replyMessage = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $admin->id,
                'message' => $reply,
            ]);

            // This is the state used by the existing ticket flow after an administrator replies.
            $ticket->reply_status = 0;
            $ticket->save();

            return ['ticket' => $ticket, 'message' => $replyMessage, 'user' => $user];
        }, 3);
    }

    private function notifyAfterReply(Ticket $ticket, TicketMessage $message, User $user, bool $enableLog): void
    {
        // The record has committed. A Hook failure can no longer roll back the reply.
        try {
            HookManager::call('ticket.reply.admin.after', [$ticket, $message]);
        } catch (\Throwable $e) {
            $this->log($enableLog, 'warning', 'AutoTicketReply post-reply hook failed', [
                'ticket_id' => $ticket->id,
                'exception' => $e->getMessage(),
            ]);
        }

        $emailSubject = trim((string) $this->getConfig('email_subject', '工单回复')) ?: '工单回复';
        $emailTemplate = trim((string) $this->getConfig('email_template', 'cronnotify')) ?: 'cronnotify';

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => $emailSubject,
            'template_name' => $emailTemplate,
            'template_value' => [
                'content' => $message->message,
                'subject' => $emailSubject,
                'name' => admin_setting('app_name', 'XBoard'),
                'url' => admin_setting('app_url', ''),
            ],
        ]);

        $this->log($enableLog, 'info', 'AutoTicketReply replied and queued email', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message_id' => $message->id,
        ]);
    }

    private function getReplyAdmin(): ?User
    {
        return User::query()
            ->whereKey((int) $this->getConfig('admin_user_id', 1))
            ->where('is_admin', true)
            ->first();
    }

    /** @return array<int, array{keywords: array<int, string>, reply: string}> */
    private function parseRules(string $rulesText): array
    {
        $rules = [];

        foreach (preg_split('/\R/u', $rulesText) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=>')) {
                continue;
            }

            [$keywordsText, $reply] = explode('=>', $line, 2);
            $keywords = array_values(array_filter(array_map('trim', explode('|', $keywordsText))));
            $reply = trim($reply);

            if ($keywords === [] || $reply === '') {
                continue;
            }

            $rules[] = ['keywords' => $keywords, 'reply' => $reply];
        }

        return $rules;
    }

    /** @param array<int, array{keywords: array<int, string>, reply: string}> $rules */
    private function matchReply(string $message, array $rules, string $defaultReply, bool $onlyKeywordMatch): ?string
    {
        foreach ($rules as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (mb_stripos($message, $keyword) !== false) {
                    return $rule['reply'];
                }
            }
        }

        return $onlyKeywordMatch || $defaultReply === '' ? null : $defaultReply;
    }

    private function isExcluded(User $user): bool
    {
        if (in_array((string) $user->id, $this->parseList($this->getConfig('excluded_user_ids', '')), true)) {
            return true;
        }

        $emails = array_map('mb_strtolower', $this->parseList($this->getConfig('excluded_emails', '')));
        return in_array(mb_strtolower((string) $user->email), $emails, true);
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
        if ($enabled || in_array($level, ['error', 'warning'], true)) {
            Log::{$level}($message, $context);
        }
    }
}
