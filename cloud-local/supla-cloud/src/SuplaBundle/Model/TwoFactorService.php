<?php

namespace SuplaBundle\Model;

use Assert\Assertion;
use DateTimeImmutable;
use RuntimeException;
use SuplaBundle\Entity\Main\User;

class TwoFactorService {
    private const CIPHER = 'aes-256-gcm';
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1;
    private const RECOVERY_CODES_COUNT = 8;

    private const PREF_ENABLED = 'security.twoFactor.enabled';
    private const PREF_SECRET = 'security.twoFactor.secret';
    private const PREF_PENDING_SECRET = 'security.twoFactor.pendingSecret';
    private const PREF_RECOVERY_CODES = 'security.twoFactor.recoveryCodes';
    private const PREF_ENABLED_AT = 'security.twoFactor.enabledAt';

    private string $secret;

    public function __construct(string $secret) {
        $this->secret = $secret;
    }

    public function beginSetup(User $user, string $issuer): array {
        $manualEntryKey = $this->generateSecret();
        $user->setPreference(self::PREF_PENDING_SECRET, $this->encrypt($manualEntryKey));

        return [
            'manualEntryKey' => $manualEntryKey,
            'otpauthUri' => $this->buildOtpAuthUri($issuer, $user->getEmail(), $manualEntryKey),
        ];
    }

    public function confirmSetup(User $user, string $code): array {
        $secret = $this->getPendingSecret($user);
        Assertion::notBlank($secret, 'Two-factor authentication setup has not been started.');
        Assertion::true($this->verifyCodeForSecret($secret, $code), 'Invalid two-factor authentication code.');

        $user->setPreference(self::PREF_SECRET, $this->encrypt($secret));
        $user->setPreference(self::PREF_PENDING_SECRET, null);
        $user->setPreference(self::PREF_ENABLED, true);
        $user->setPreference(self::PREF_ENABLED_AT, (new DateTimeImmutable())->format(DATE_ATOM));

        return $this->regenerateRecoveryCodes($user);
    }

    public function disable(User $user): void {
        $user->setPreference(self::PREF_ENABLED, false);
        $user->setPreference(self::PREF_SECRET, null);
        $user->setPreference(self::PREF_PENDING_SECRET, null);
        $user->setPreference(self::PREF_RECOVERY_CODES, []);
        $user->setPreference(self::PREF_ENABLED_AT, null);
    }

    public function isEnabled(User $user): bool {
        return !!$user->getPreference(self::PREF_ENABLED, false) && !!$this->getSecret($user);
    }

    public function verifyCode(User $user, string $code): bool {
        $secret = $this->getSecret($user);
        if (!$secret) {
            return false;
        }
        return $this->verifyCodeForSecret($secret, $code);
    }

    public function consumeRecoveryCode(User $user, string $recoveryCode): bool {
        $recoveryCode = strtoupper(trim($recoveryCode));
        if ($recoveryCode === '') {
            return false;
        }
        $hashes = $user->getPreference(self::PREF_RECOVERY_CODES, []);
        if (!is_array($hashes)) {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify($recoveryCode, $hash)) {
                unset($hashes[$index]);
                $user->setPreference(self::PREF_RECOVERY_CODES, array_values($hashes));
                return true;
            }
        }

        return false;
    }

    public function regenerateRecoveryCodes(User $user): array {
        $recoveryCodes = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODES_COUNT; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $recoveryCodes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
            $hashes[] = password_hash($recoveryCodes[$i], PASSWORD_DEFAULT);
        }
        $user->setPreference(self::PREF_RECOVERY_CODES, $hashes);
        return $recoveryCodes;
    }

    public function getPublicState(User $user): array {
        return [
            'enabled' => $this->isEnabled($user),
            'recoveryCodesLeft' => count($this->getRecoveryCodeHashes($user)),
            'setupPending' => !!$this->getPendingSecret($user),
        ];
    }

    public function removeSensitivePreferences(array $preferences): array {
        unset($preferences[self::PREF_SECRET], $preferences[self::PREF_PENDING_SECRET], $preferences[self::PREF_RECOVERY_CODES]);
        return $preferences;
    }

    public function buildOtpAuthUri(string $issuer, string $accountName, string $manualEntryKey): string {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $manualEntryKey,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    private function getPendingSecret(User $user): ?string {
        return $this->decryptPreference($user, self::PREF_PENDING_SECRET);
    }

    private function getSecret(User $user): ?string {
        return $this->decryptPreference($user, self::PREF_SECRET);
    }

    private function getRecoveryCodeHashes(User $user): array {
        $hashes = $user->getPreference(self::PREF_RECOVERY_CODES, []);
        return is_array($hashes) ? $hashes : [];
    }

    private function decryptPreference(User $user, string $key): ?string {
        $value = $user->getPreference($key);
        return is_string($value) && $value !== '' ? $this->decrypt($value) : null;
    }

    private function verifyCodeForSecret(string $secret, string $code): bool {
        $code = preg_replace('/\D+/', '', $code);
        if (!$code || strlen($code) !== self::DIGITS) {
            return false;
        }
        $timeSlice = intdiv(time(), self::PERIOD);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals($this->generateCode($secret, $timeSlice + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    private function generateCode(string $secret, int $timeSlice): string {
        $binarySecret = $this->base32Decode($secret);
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

    private function generateSecret(): string {
        return $this->base32Encode(random_bytes(20));
    }

    private function base32Encode(string $bytes): string {
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

    private function base32Decode(string $secret): string {
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

    private function encrypt(string $plainText): string {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($plainText, self::CIPHER, $this->getEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt two-factor data.');
        }

        return implode(':', [
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    private function decrypt(string $cipherText): ?string {
        $parts = explode(':', $cipherText);
        if (count($parts) !== 3) {
            return null;
        }
        [$iv, $tag, $encrypted] = array_map('base64_decode', $parts);
        if ($iv === false || $tag === false || $encrypted === false) {
            return null;
        }

        $plainText = openssl_decrypt($encrypted, self::CIPHER, $this->getEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plainText === false ? null : $plainText;
    }

    private function getEncryptionKey(): string {
        return hash('sha256', $this->secret, true);
    }
}
