<?php

namespace Plugin\Epay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['EPay'] = [
                    'name' => $this->getConfig('display_name', '易支付'),
                    'icon' => $this->getConfig('icon', '💳'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'url' => [
                'label' => '支付网关地址',
                'type' => 'string',
                'required' => true,
                'description' => '请填写完整的支付网关地址，包括协议（http或https）'
            ],
            'pid' => [
                'label' => '商户ID',
                'type' => 'string',
                'description' => '请填写商户ID',
                'required' => true
            ],
            'key' => [
                'label' => '通信密钥',
                'type' => 'string',
                'required' => true,
                'description' => '请填写通信密钥'
            ],
            'type' => [
                'label' => '支付类型',
                'type' => 'string',
                'description' => '支付类型，如: alipay, wxpay, qqpay 等，可自定义'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->getConfig('pid')
        ];

        if ($paymentType = $this->getConfig('type')) {
            $params['type'] = $paymentType;
        }

        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('key');
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';

        return [
            'type' => 1,
            'data' => $this->getConfig('url') . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params): array|bool
    {
        $sign = $params['sign'] ?? null;
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('key');

        if (!is_string($sign) || !hash_equals(md5($str), $sign)) {
            return false;
        }

        $pid = $params['pid'] ?? null;
        if (!is_scalar($pid) || (string) $pid !== (string) $this->getConfig('pid')) {
            return false;
        }

        // 失败、关闭等回调已经通过验签，但绝不能进入订单开通流程。
        // 返回 success 以确认已收到，避免支付网关持续重试失败通知。
        if (($params['trade_status'] ?? null) !== 'TRADE_SUCCESS') {
            return [
                'ignore' => true,
                'custom_result' => 'success'
            ];
        }

        $outTradeNo = $params['out_trade_no'] ?? null;
        $tradeNo = $params['trade_no'] ?? null;
        if (!is_string($outTradeNo) || $outTradeNo === '' || !is_string($tradeNo) || $tradeNo === '') {
            return false;
        }

        $amount = $this->amountToCents($params['money'] ?? null);
        if ($amount === null) {
            return false;
        }

        return [
            'trade_no' => $outTradeNo,
            'callback_no' => $tradeNo,
            'amount' => $amount
        ];
    }

    /**
     * Convert a gateway decimal amount to cents without floating-point rounding.
     */
    private function amountToCents($money): ?int
    {
        if (!is_scalar($money)) {
            return null;
        }

        $money = trim((string) $money);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        if (strlen($whole) > 16) {
            return null;
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
