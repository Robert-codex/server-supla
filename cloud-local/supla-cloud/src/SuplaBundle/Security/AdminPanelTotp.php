<?php
namespace SuplaBundle\Security;

use RuntimeException;

class AdminPanelTotp {
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1;

    public static function generateSecret(): string {
        return self::base32Encode(random_bytes(20));
    }

    public static function buildOtpAuthUri(string $issuer, string $accountName, string $secret): string {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    public static function verifyCode(string $secret, string $code): bool {
        $code = preg_replace('/\D+/', '', $code);
        if (!$code || strlen($code) !== self::DIGITS) {
            return false;
        }
        $timeSlice = intdiv(time(), self::PERIOD);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::generateCode($secret, $timeSlice + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function generateCode(string $secret, int $timeSlice): string {
        $binarySecret = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $binarySecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );
        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($bytes) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binary, 5);
        $encoded = '';
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= $alphabet[bindec($chunk)];
        }
        return $encoded;
    }

    private static function base32Decode(string $secret): string {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $binary = '';
        foreach (str_split($secret) as $char) {
            if (!array_key_exists($char, $alphabet)) {
                throw new RuntimeException('Invalid two-factor secret.');
            }
            $binary .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }
}

