<?php

namespace Plugin\ScheduledTokenReset;

use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const CACHE_PREFIX = 'plugin:scheduled_token_reset:';

    public function boot(): void {}
    public function install(): void {}
    public function cleanup(): void {}

    public function update(string $oldVersion, string $newVersion): void
    {
        $config = $this->getConfig();

        // Preserve the previous schedule when upgrading 1.0.0 (days -> minutes).
        if (array_key_exists('interval_minutes', $config) || !array_key_exists('interval_days', $config)) {
            return;
        }

        $config['interval_minutes'] = min(max((int) $config['interval_days'], 1) * 1440, 5256000);
        unset($config['interval_days'], $config['scheduled_hour']);

        PluginModel::query()
            ->where('code', $this->getPluginCode())
            ->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);

        $this->setConfig($config);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->runBatch())->everyMinute();
    }

    private function runBatch(): void
    {
        if (!$this->getBooleanConfig('status', false)) {
            return;
        }

        $intervalMinutes = min(max((int) $this->getConfig('interval_minutes', 60), 1), 5256000);
        $keys = $this->stateKeys();

        if (!Cache::add($keys['lock'], 1, now()->addMinutes(10))) {
            return;
        }

        try {
            $enableLog = $this->getBooleanConfig('enable_log', true);
            $isRunning = (bool) Cache::get($keys['running'], false);

            if (!$isRunning) {
                if (!$this->shouldStartNewCycle($keys['last_completed_at'], $intervalMinutes)) {
                    return;
                }

                Cache::put($keys['running'], true, $this->stateTtl($intervalMinutes));
                Cache::forget($keys['cursor']);
                $this->log($enableLog, 'info', 'ScheduledTokenReset started cycle');
            }

            $batchSize = min(max((int) $this->getConfig('batch_size', 100), 1), 1000);
            $cursor = (int) Cache::get($keys['cursor'], 0);
            $excludedIds = $this->parseList($this->getConfig('excluded_user_ids', ''));
            $excludedEmails = $this->parseList($this->getConfig('excluded_emails', ''));

            $result = DB::transaction(function () use ($cursor, $batchSize, $excludedIds, $excludedEmails): array {
                $users = $this->resettableUsersQuery($cursor, $excludedIds, $excludedEmails)
                    ->limit($batchSize)
                    ->lockForUpdate()
                    ->get();

                if ($users->isEmpty()) {
                    return ['count' => 0, 'last_id' => $cursor];
                }

                foreach ($users as $user) {
                    User::query()
                        ->whereKey($user->id)
                        ->update([
                            'token' => Helper::guid(),
                            'updated_at' => time(),
                        ]);
                }

                return ['count' => $users->count(), 'last_id' => (int) $users->last()->id];
            }, 3);

            if ($result['count'] === 0) {
                Cache::put($keys['last_completed_at'], time(), $this->stateTtl($intervalMinutes));
                Cache::forget($keys['cursor']);
                Cache::forget($keys['running']);
                $this->log($enableLog, 'info', 'ScheduledTokenReset completed cycle');
                return;
            }

            Cache::put($keys['cursor'], $result['last_id'], $this->stateTtl($intervalMinutes));
            $this->log($enableLog, 'info', 'ScheduledTokenReset completed batch', [
                'count' => $result['count'],
                'cursor' => $result['last_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('ScheduledTokenReset batch failed', ['exception' => $e->getMessage()]);
        } finally {
            Cache::forget($keys['lock']);
        }
    }

    private function shouldStartNewCycle(string $lastCompletedKey, int $intervalMinutes): bool
    {
        $lastCompletedAt = (int) Cache::get($lastCompletedKey, 0);
        return $lastCompletedAt === 0 || (time() - $lastCompletedAt) >= $intervalMinutes * 60;
    }

    /**
     * @param array<int, string> $excludedIds
     * @param array<int, string> $excludedEmails
     */
    private function resettableUsersQuery(int $cursor, array $excludedIds, array $excludedEmails): Builder
    {
        $query = User::query()
            ->select(['id', 'email'])
            ->where('id', '>', $cursor)
            ->orderBy('id');

        if ($this->getBooleanConfig('only_active_users', false)) {
            $query->where(function (Builder $builder): void {
                    $builder->where('banned', 0)->orWhereNull('banned');
                })
                ->where('transfer_enable', '>', 0)
                ->where(function (Builder $builder): void {
                    $builder->where('expired_at', '>', time())
                        ->orWhereNull('expired_at');
                });
        }

        if ($this->getBooleanConfig('exclude_admin_users', true)) {
            $query->where(function (Builder $builder): void {
                $builder->where('is_admin', 0)->orWhereNull('is_admin');
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

    /** @return array{lock: string, cursor: string, running: string, last_completed_at: string} */
    private function stateKeys(): array
    {
        return [
            'lock' => self::CACHE_PREFIX . 'lock',
            'cursor' => self::CACHE_PREFIX . 'cursor',
            'running' => self::CACHE_PREFIX . 'running',
            'last_completed_at' => self::CACHE_PREFIX . 'last_completed_at',
        ];
    }

    private function stateTtl(int $intervalMinutes): \DateTimeInterface
    {
        return now()->addMinutes(max($intervalMinutes + 60, 10080));
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
