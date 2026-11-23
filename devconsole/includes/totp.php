<?php
/**
 * RFC 6238 / RFC 4226 Time-Based One-Time Password (TOTP) Implementation
 * Zero external dependencies. Uses built-in hash_hmac and pack.
 */

class Totp
{
    private static string $base32Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically secure Base32 secret string (16 chars = 80 bits).
     */
    public static function generateSecret(int $length = 16): string
    {
        $secret = '';
        $alphabet = self::$base32Alphabet;
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, $max)];
        }
        return $secret;
    }

    /**
     * Decode a Base32 string to raw binary string.
     */
    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(trim($b32));
        $alphabet = self::$base32Alphabet;
        $buffer = 0;
        $bitsLeft = 0;
        $binary = '';

        for ($i = 0; $i < strlen($b32); $i++) {
            $char = $b32[$i];
            if ($char === '=' || $char === ' ' || $char === '-') {
                continue;
            }
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }

    /**
     * Calculate 6-digit TOTP code for a secret at a specific Unix timestamp.
     */
    public static function getCode(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
    {
        $timestamp = $timestamp ?? time();
        $timeSlice = (int) floor($timestamp / $period);

        $secretBin = self::base32Decode($secret);

        // Pack timeSlice as 64-bit big-endian binary (8 bytes)
        $timePacked = pack('J', $timeSlice);

        // HMAC-SHA1
        $hash = hash_hmac('sha1', $timePacked, $secretBin, true);

        // Dynamic truncation (RFC 4226 Section 5.4)
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $mod = 10 ** $digits;
        $code = (string) ($binary % $mod);

        return str_pad($code, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify user code with tolerance window (+- $window time slices, default 1 = +-30s).
     */
    public static function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $timestamp = $timestamp ?? time();
        $period = 30;
        $currentTimeSlice = (int) floor($timestamp / $period);

        for ($i = -$window; $i <= $window; $i++) {
            $slice = $currentTimeSlice + $i;
            $calcCode = self::getCode($secret, $slice * $period, $period, 6);
            if (hash_equals($calcCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build standard otpauth URI for Google Authenticator / Authy.
     */
    public static function getOtpAuthUri(string $username, string $secret, string $issuer = 'SokoHub Ops'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($username);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }
}
