<?php

namespace Plugin\SubscribeRateReset;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 此过滤器在订阅用户有效性校验后调用，并会收到当前用户与请求。
        $this->filter('client.subscribe.servers', [$this, 'trackSubscribeRequest'], 10);
    }

    /**
     * Count valid subscription requests and rotate the token once the limit is reached.
     * The server list must be returned unchanged so the normal subscription flow continues.
     */
    public function trackSubscribeRequest($servers, User $user, Request $request)
    {
        if (!(bool) $this->getConfig('status', false) || $this->isExcluded($user)) {
            return $servers;
        }

        $windowMinutes = min(max((int) $this->getConfig('window_minutes', 5), 1), 10080);
        $requestLimit = min(max((int) $this->getConfig('request_limit', 2), 1), 100000);
        $cacheKey = 'plugin:subscribe_rate_reset:user:' . $user->id;

        // add + increment avoids the race condition caused by a separate has + put check.
        Cache::add($cacheKey, 0, now()->addMinutes($windowMinutes));
        $requestCount = (int) Cache::increment($cacheKey);

        if ($requestCount < $requestLimit) {
            return $servers;
        }

        $previousToken = $user->token;
        $user->token = Helper::guid();
        $user->save();
        Cache::forget($cacheKey);

        if ((bool) $this->getConfig('enable_log', true)) {
            Log::info('SubscribeRateReset rotated subscription token', [
                'user_id' => $user->id,
                'window_minutes' => $windowMinutes,
                'request_limit' => $requestLimit,
                'previous_token_suffix' => substr($previousToken, -6),
            ]);
        }

        return $servers;
    }

    private function isExcluded(User $user): bool
    {
        $excludedUserIds = $this->parseList($this->getConfig('excluded_user_ids', ''));
        if (in_array((string) $user->id, $excludedUserIds, true)) {
            return true;
        }

        $excludedEmails = array_map('mb_strtolower', $this->parseList($this->getConfig('excluded_emails', '')));

        return in_array(mb_strtolower((string) $user->email), $excludedEmails, true);
    }

    /**
     * Accept one item per line as well as comma- or semicolon-separated values.
     *
     * @return array<int, string>
     */
    private function parseList(mixed $value): array
    {
        $items = preg_split('/[\s,;，；]+/u', trim((string) $value)) ?: [];

        return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
    }
}
