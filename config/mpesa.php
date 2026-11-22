<?php
/**
 * SokoHub Safaricom Daraja M-Pesa Global Configuration
 * Reads all settings strictly from environment variables (.env).
 * Never hardcodes secrets or full tokens.
 */

require_once __DIR__ . '/config.php';

/**
 * Returns the resolved M-Pesa configuration array from environment variables.
 */
function get_mpesa_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $env = strtolower(trim((string) (getenv('MPESA_ENV') ?: 'sandbox')));
    if (!in_array($env, ['sandbox', 'production', 'live'], true)) {
        $env = 'sandbox';
    }

    $isLive = ($env === 'production' || $env === 'live');

    // Base endpoints
    $baseUrl = $isLive ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $oauthUrl = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
    $stkUrl = $baseUrl . '/mpesa/stkpush/v1/processrequest';
    $queryUrl = $baseUrl . '/mpesa/stkpushquery/v1/query';
    $b2cUrl = $baseUrl . '/mpesa/b2c/v1/paymentrequest';
    $b2bUrl = $baseUrl . '/mpesa/b2b/v1/paymentrequest';

    // Auto-detect default callback URL if not explicitly configured in .env
    $callbackUrl = trim((string) getenv('MPESA_CALLBACK_URL'));
    if ($callbackUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $callbackUrl = $proto . $_SERVER['HTTP_HOST'] . url('pages/api/mpesa_callback.php');
    }
    // Safaricom Daraja strictly mandates a valid HTTPS URL. If blank or local HTTP, provide public HTTPS fallback
    if ($callbackUrl === '' || !str_starts_with(strtolower($callbackUrl), 'https://')) {
        $callbackUrl = 'https://sokohub.co.ke/pages/api/mpesa_callback.php';
    }

    // Default Result & Timeout URLs for B2C/B2B
    $resultUrl = trim((string) getenv('MPESA_RESULT_URL'));
    if ($resultUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $resultUrl = $proto . $_SERVER['HTTP_HOST'] . url('pages/api/mpesa_payout_callback.php');
    }
    if ($resultUrl === '' || !str_starts_with(strtolower($resultUrl), 'https://')) {
        $resultUrl = 'https://sokohub.co.ke/pages/api/mpesa_payout_callback.php';
    }

    $timeoutUrl = trim((string) getenv('MPESA_TIMEOUT_URL'));
    if ($timeoutUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $timeoutUrl = $proto . $_SERVER['HTTP_HOST'] . url('pages/api/mpesa_payout_timeout.php');
    }
    if ($timeoutUrl === '' || !str_starts_with(strtolower($timeoutUrl), 'https://')) {
        $timeoutUrl = 'https://sokohub.co.ke/pages/api/mpesa_payout_timeout.php';
    }

    $platformFeePercent = (float) (getenv('PLATFORM_FEE_PERCENT') !== false && getenv('PLATFORM_FEE_PERCENT') !== '' ? getenv('PLATFORM_FEE_PERCENT') : 2.0);
    $tillNumber = trim((string) (getenv('MPESA_TILL_NUMBER') ?: '1648753'));
    $shortcode = trim((string) (getenv('MPESA_SHORTCODE') ?: '174379'));
    $passkey = trim((string) (getenv('MPESA_PASSKEY') ?: ''));

    // Transaction type switchable by config: CustomerBuyGoodsOnline (for Till) or CustomerPayBillOnline
    $transType = trim((string) (getenv('MPESA_TRANSACTION_TYPE') ?: 'CustomerBuyGoodsOnline'));
    if (!in_array($transType, ['CustomerBuyGoodsOnline', 'CustomerPayBillOnline'], true)) {
        $transType = 'CustomerBuyGoodsOnline';
    }

    $payoutTrigger = strtolower(trim((string) (getenv('MPESA_PAYOUT_TRIGGER') ?: 'on_delivery')));
    if (!in_array($payoutTrigger, ['on_delivery', 'on_payment'], true)) {
        $payoutTrigger = 'on_delivery';
    }

    $deliveryPayoutTrigger = strtolower(trim((string) (getenv('MPESA_DELIVERY_PAYOUT_TRIGGER') ?: 'automatic')));
    if (!in_array($deliveryPayoutTrigger, ['automatic', 'manual'], true)) {
        $deliveryPayoutTrigger = 'automatic';
    }

    $allowSimulation = (getenv('MPESA_ALLOW_SIMULATION') === false) ? true : filter_var(getenv('MPESA_ALLOW_SIMULATION'), FILTER_VALIDATE_BOOLEAN);
    // Hardening: In production/live environments, simulation tools are strictly disabled
    if ($isLive || $env === 'production') {
        $allowSimulation = false;
    }

    $config = [
        'env'                     => $env,
        'is_live'                 => $isLive,
        'consumer_key'            => trim((string) getenv('MPESA_CONSUMER_KEY')),
        'consumer_secret'         => trim((string) getenv('MPESA_CONSUMER_SECRET')),
        'passkey'                 => $passkey,
        'shortcode'               => $shortcode,
        'till_number'             => $tillNumber,
        'callback_url'            => $callbackUrl,
        'result_url'              => $resultUrl,
        'timeout_url'             => $timeoutUrl,
        'b2c_initiator_name'      => trim((string) getenv('MPESA_B2C_INITIATOR_NAME')),
        'b2c_security_credential' => trim((string) getenv('MPESA_B2C_SECURITY_CREDENTIAL')),
        'platform_fee_percent'    => $platformFeePercent,
        'transaction_type'        => $transType,
        'payout_trigger'          => $payoutTrigger,
        'delivery_payout_trigger' => $deliveryPayoutTrigger,
        'allow_simulation'        => $allowSimulation,
        'oauth_url'               => $oauthUrl,
        'stk_url'                 => $stkUrl,
        'query_url'               => $queryUrl,
        'b2c_url'                 => $b2cUrl,
        'b2b_url'                 => $b2bUrl,
    ];

    return $config;
}

/**
 * Check if real live/sandbox Daraja consumer key and secret are filled.
 */
function is_mpesa_live_configured(): bool
{
    $cfg = get_mpesa_config();
    return !empty($cfg['consumer_key']) && !empty($cfg['consumer_secret']) && !empty($cfg['passkey']);
}
