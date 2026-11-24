<?php
/**
 * SokoHub Safaricom Daraja M-Pesa Global Configuration Resolver
 *
 * Priority order for configuration:
 * 1. config/mpesa.local.php (local developer secrets, gitignored)
 * 2. Environment variables (Docker / Cloud / Hosting)
 * 3. Default Daraja Sandbox credentials (for testing & development)
 */

require_once __DIR__ . '/config.php';

/**
 * Returns the resolved M-Pesa Daraja API configuration array.
 */
function get_mpesa_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    // 1. Check for dedicated local overrides file (gitignored)
    $localFile = __DIR__ . '/mpesa.local.php';
    $local = [];
    if (file_exists($localFile)) {
        $loaded = require $localFile;
        if (is_array($loaded)) {
            $local = $loaded;
        }
    }

    // 2. Resolve Environment (sandbox or live)
    $env = $local['env'] ?? getenv('MPESA_ENV') ?: 'sandbox';
    $env = strtolower(trim((string) $env));
    if (!in_array($env, ['sandbox', 'production', 'live'], true)) {
        $env = 'sandbox';
    }
    $isLive = ($env === 'production' || $env === 'live');

    // 3. Resolve API URLs according to environment
    $baseUrl = $isLive ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $oauthUrl = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
    $stkUrl = $baseUrl . '/mpesa/stkpush/v1/processrequest';
    $queryUrl = $baseUrl . '/mpesa/stkpushquery/v1/query';
    $b2cUrl = $baseUrl . '/mpesa/b2c/v1/paymentrequest';
    $b2bUrl = $baseUrl . '/mpesa/b2b/v1/paymentrequest';

    // 4. Safaricom Daraja Sandbox standard test defaults
    $defaultSandboxShortcode = '174379';
    $defaultSandboxPasskey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';

    // 5. Build configuration array with fallbacks
    $consumerKey = $local['consumer_key'] ?? getenv('MPESA_CONSUMER_KEY') ?: '';
    $consumerSecret = $local['consumer_secret'] ?? getenv('MPESA_CONSUMER_SECRET') ?: '';
    $shortcode = $local['shortcode'] ?? getenv('MPESA_SHORTCODE') ?: $defaultSandboxShortcode;
    $passkey = $local['passkey'] ?? getenv('MPESA_PASSKEY') ?: $defaultSandboxPasskey;

    // Detect default callback URL based on server environment
    // Note: Safaricom Daraja strictly requires a public HTTPS URL. Localhost/HTTP is rejected with HTTP 400.
    $customCallback = trim((string) ($local['callback_url'] ?? getenv('MPESA_CALLBACK_URL') ?: ''));
    if ($customCallback !== '') {
        $callbackUrl = $customCallback;
    } else {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocal = empty($host)
            || str_contains($host, 'localhost')
            || str_contains($host, '127.0.0.1')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        if (!$isLocal && $isHttps) {
            $callbackUrl = 'https://' . $host . url('pages/api/mpesa_callback.php');
        } else {
            // Valid public HTTPS callback format for Daraja Sandbox validation
            $callbackUrl = 'https://sokohub.co.ke/pages/api/mpesa_callback.php';
        }
    }

    // Default Result & Timeout URLs for B2C payouts
    $resultUrl = trim((string) ($local['result_url'] ?? getenv('MPESA_RESULT_URL') ?: ''));
    if ($resultUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $resultUrl = $proto . $_SERVER['HTTP_HOST'] . url('pages/api/mpesa_payout_callback.php');
    }
    if ($resultUrl === '' || !str_starts_with(strtolower($resultUrl), 'https://')) {
        $resultUrl = 'https://sokohub.co.ke/pages/api/mpesa_payout_callback.php';
    }

    $timeoutUrl = trim((string) ($local['timeout_url'] ?? getenv('MPESA_TIMEOUT_URL') ?: ''));
    if ($timeoutUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $timeoutUrl = $proto . $_SERVER['HTTP_HOST'] . url('pages/api/mpesa_payout_timeout.php');
    }
    if ($timeoutUrl === '' || !str_starts_with(strtolower($timeoutUrl), 'https://')) {
        $timeoutUrl = 'https://sokohub.co.ke/pages/api/mpesa_payout_timeout.php';
    }

    $type = strtolower(trim((string) ($local['type'] ?? getenv('MPESA_TYPE') ?: 'till')));
    if (!in_array($type, ['paybill', 'till', 'buy_goods'], true)) {
        $type = 'till';
    }
    $tillNumber = trim((string) ($local['till_number'] ?? getenv('MPESA_TILL_NUMBER') ?: '1648753'));
    $accountName = trim((string) ($local['account_name'] ?? getenv('MPESA_ACCOUNT_NAME') ?: 'sokohub'));
    $platformFeePercent = (float) ($local['platform_fee_percent'] ?? (getenv('PLATFORM_FEE_PERCENT') !== false && getenv('PLATFORM_FEE_PERCENT') !== '' ? getenv('PLATFORM_FEE_PERCENT') : 2.0));

    $allowSimulation = (bool) ($local['allow_simulation'] ?? (getenv('MPESA_ALLOW_SIMULATION') === false ? true : filter_var(getenv('MPESA_ALLOW_SIMULATION'), FILTER_VALIDATE_BOOLEAN)));
    if ($isLive) {
        $allowSimulation = false;
    }

    $config = [
        'env'                     => $env,
        'is_live'                 => $isLive,
        'type'                    => $type, // 'paybill' or 'till'
        'till_number'             => $tillNumber,
        'account_name'            => $accountName !== '' ? $accountName : 'sokohub',
        'consumer_key'            => trim((string) $consumerKey),
        'consumer_secret'         => trim((string) $consumerSecret),
        'shortcode'               => trim((string) $shortcode),
        'passkey'                 => trim((string) $passkey),
        'callback_url'            => trim((string) $callbackUrl),
        'result_url'              => trim((string) $resultUrl),
        'timeout_url'             => trim((string) $timeoutUrl),
        'platform_fee_percent'    => $platformFeePercent,
        'oauth_url'               => $oauthUrl,
        'stk_url'                 => $stkUrl,
        'query_url'               => $queryUrl,
        'b2c_url'                 => $b2cUrl,
        'b2b_url'                 => $b2bUrl,
        'allow_simulation'        => $allowSimulation,
    ];

    return $config;
}

/**
 * Returns true if real Daraja API credentials have been configured.
 */
function is_mpesa_live_configured(): bool
{
    $cfg = get_mpesa_config();
    return !empty($cfg['consumer_key'])
        && !empty($cfg['consumer_secret'])
        && $cfg['consumer_key'] !== 'YOUR_CONSUMER_KEY_HERE'
        && !empty($cfg['passkey']);
}

/**
 * Alias for get_mpesa_config()
 */
function mpesa_config(): array
{
    return get_mpesa_config();
}
