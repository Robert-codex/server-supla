<?php

namespace SuplaBundle\Model;

use RuntimeException;
use SuplaBundle\Entity\Main\User;

class LoginChallengeTokenService {
    private const CIPHER = 'aes-256-gcm';
    private const TTL = 300;

    private string $secret;

    public function __construct(string $secret) {
        $this->secret = $secret;
    }

    public function issue(User $user, string $password): string {
        $payload = json_encode([
            'username' => $user->getEmail(),
            'password' => $password,
            'expiresAt' => time() + self::TTL,
            'passwordFingerprint' => $this->passwordFingerprint($user),
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($payload, self::CIPHER, $this->getEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt login challenge.');
        }

        return implode('.', [
            $this->base64UrlEncode($iv),
            $this->base64UrlEncode($tag),
            $this->base64UrlEncode($ciphertext),
        ]);
    }

    public function consume(string $token): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid login challenge token.');
        }

        [$ivPart, $tagPart, $ciphertextPart] = $parts;
        $iv = $this->base64UrlDecode($ivPart);
        $tag = $this->base64UrlDecode($tagPart);
        $ciphertext = $this->base64UrlDecode($ciphertextPart);
        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new RuntimeException('Invalid login challenge token.');
        }

        $payload = openssl_decrypt($ciphertext, self::CIPHER, $this->getEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($payload === false) {
            throw new RuntimeException('Invalid login challenge token.');
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid login challenge token.');
        }

        return $decoded;
    }

    public function isExpired(array $payload): bool {
        return intval($payload['expiresAt'] ?? 0) < time();
    }

    public function matchesUser(array $payload, User $user): bool {
        return ($payload['username'] ?? '') === $user->getEmail()
            && ($payload['passwordFingerprint'] ?? '') === $this->passwordFingerprint($user);
    }

    public function getTtl(): int {
        return self::TTL;
    }

    private function passwordFingerprint(User $user): string {
        return substr(hash('sha256', (string)$user->getPassword()), 0, 24);
    }

    private function getEncryptionKey(): string {
        return hash('sha256', $this->secret, true);
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value) {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
