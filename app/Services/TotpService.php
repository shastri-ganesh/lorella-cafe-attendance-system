<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Class TotpService
 * RFC 6238 & RFC 4226 compliant Time-Based One-Time Password (TOTP) engine.
 * Pure PHP implementation with zero external library dependencies.
 */
class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const TIME_STEP = 30; // 30-second window
    private const DIGITS = 6;

    /**
     * Generate a new random Base32 secret key.
     */
    public static function generateSecret(int $byteLength = 20): string
    {
        $randomBytes = random_bytes($byteLength);
        return self::base32Encode($randomBytes);
    }

    /**
     * Generate standard TOTP token for given secret and optional timestamp.
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = (int) floor($timestamp / self::TIME_STEP);
        return self::calculateHotp($secret, $counter);
    }

    /**
     * Verify submitted TOTP token against secret within drift window.
     */
    public static function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return false;
        }

        $timestamp ??= time();
        $currentCounter = (int) floor($timestamp / self::TIME_STEP);

        for ($drift = -$window; $drift <= $window; $drift++) {
            $expectedCode = self::calculateHotp($secret, $currentCounter + $drift);
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate standard otpauth:// URI for QR code enrollment.
     */
    public static function getOtpAuthUri(string $username, string $secret, string $issuer = 'Lorella Cafe'): string
    {
        $label = rawurlencode($issuer . ':' . $username);
        $encodedIssuer = rawurlencode($issuer);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Generate single-use recovery codes.
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $plainCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < $count; $i++) {
            // Generate readable 10-char alphanumeric code: XXXX-XXXXX
            $bytes = bin2hex(random_bytes(4));
            $code = strtoupper(substr($bytes, 0, 4) . '-' . substr($bytes, 4, 4));
            $plainCodes[] = $code;
            $hashedCodes[] = password_hash($code, PASSWORD_DEFAULT);
        }

        return [
            'plain' => $plainCodes,
            'hashed' => $hashedCodes,
        ];
    }

    /**
     * Generate pure-PHP vector SVG QR code markup for authenticator app enrollment.
     */
    public static function generateQrCodeSvg(string $otpauthUri, int $size = 240): string
    {
        return QrCodeService::generateSvg($otpauthUri, $size);
    }

    /**
     * Generate base64 Data URI for authenticator QR code.
     */
    public static function generateQrCodeDataUri(string $otpauthUri, int $size = 240): string
    {
        return QrCodeService::generateDataUri($otpauthUri, $size);
    }

    /**
     * Verify a submitted recovery code against stored unused code hashes.
     * Returns matching recovery code record ID or null if invalid.
     */
    public static function verifyRecoveryCode(string $plainCode, array $unusedRecords): ?int
    {
        $normalized = strtoupper(trim($plainCode));
        if ($normalized === '') {
            return null;
        }

        foreach ($unusedRecords as $record) {
            $codeHash = $record['code_hash'] ?? '';
            if ($codeHash !== '' && password_verify($normalized, $codeHash)) {
                return (int) $record['id'];
            }
        }

        return null;
    }

    /**
     * Calculate HOTP (RFC 4226) for given secret and counter.
     */
    private static function calculateHotp(string $secret, int $counter): string
    {
        $secretBinary = self::base32Decode($secret);

        // Pack 64-bit integer counter in big-endian
        $counterBinary = pack('N*', 0, $counter);

        $hash = hash_hmac('sha1', $counterBinary, $secretBinary, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0f;
        $binary = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );

        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * RFC 4648 Base32 Encoder.
     */
    public static function base32Encode(string $data): string
    {
        if (empty($data)) {
            return '';
        }

        $binaryString = '';
        foreach (str_split($data) as $char) {
            $binaryString .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binaryString, 5);
        $result = '';

        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $result;
    }

    /**
     * RFC 4648 Base32 Decoder.
     */
    public static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));
        if (empty($base32)) {
            return '';
        }

        $binaryString = '';
        foreach (str_split($base32) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue; // Ignore non-base32 chars
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binaryString, 8);
        $result = '';

        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $result .= chr(bindec($chunk));
            }
        }

        return $result;
    }
}
