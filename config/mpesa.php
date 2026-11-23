<?php
/**
 * SokoHub M-Pesa Daraja API Global Configuration Resolver
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
    if (!in_array($env, ['sandbox', 'live'], true)) {
        $env = 'sandbox';
    }

    // 3. Resolve API URLs according to environment
    if ($env === 'live') {
        $oauthUrl = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $stkUrl = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        $queryUrl = 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    } else {
        $oauthUrl = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $stkUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        $queryUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    }

    // 4. Safaricom Daraja Sandbox standard test defaults
    // Official Safaricom Daraja test credentials for Paybill 174379:
    $defaultSandboxShortcode = '174379';
    $defaultSandboxPasskey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';

    // 5. Build configuration array with fallbacks
    $consumerKey = $local['consumer_key'] ?? getenv('MPESA_CONSUMER_KEY') ?: '';
    $consumerSecret = $local['consumer_secret'] ?? getenv('MPESA_CONSUMER_SECRET') ?: '';
    $shortcode = $local['shortcode'] ?? getenv('MPESA_SHORTCODE') ?: $defaultSandboxShortcode;
    $passkey = $local['passkey'] ?? getenv('MPESA_PASSKEY') ?: $defaultSandboxPasskey;

    // Detect default callback URL based on server environment
    // Note: Safaricom Daraja strictly requires a public HTTPS URL. Localhost/HTTP is rejected with HTTP 400 (Invalid CallBackURL).
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

    $type = strtolower(trim((string) ($local['type'] ?? getenv('MPESA_TYPE') ?: 'till')));
    if (!in_array($type, ['paybill', 'till', 'buy_goods'], true)) {
        $type = 'till';
    }
    $tillNumber = trim((string) ($local['till_number'] ?? getenv('MPESA_TILL_NUMBER') ?: '1648753'));
    $accountName = trim((string) ($local['account_name'] ?? getenv('MPESA_ACCOUNT_NAME') ?: 'sokohub'));

    $config = [
        'env'             => $env,
        'type'            => $type, // 'paybill' (CustomerPayBillOnline) or 'till' / 'buy_goods' (CustomerBuyGoodsOnline)
        'till_number'     => $tillNumber,
        'account_name'    => $accountName !== '' ? $accountName : 'sokohub',
        'consumer_key'    => trim((string) $consumerKey),
        'consumer_secret' => trim((string) $consumerSecret),
        'shortcode'       => trim((string) $shortcode),
        'passkey'         => trim((string) $passkey),
        'callback_url'    => trim((string) $callbackUrl),
        'oauth_url'       => $oauthUrl,
        'stk_url'         => $stkUrl,
        'query_url'       => $queryUrl,
        // When true or when keys are blank/unreachable, enables friendly simulation for local presentation demos
        'allow_simulation' => (bool) ($local['allow_simulation'] ?? true),
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
        && $cfg['consumer_key'] !== 'YOUR_CONSUMER_KEY_HERE';
}
