<?php

namespace SuplaBundle\Security;

use RuntimeException;

class RegistrationBlockStore {
    private const DEFAULT_MESSAGE = 'Obecnie dodawanie nowych kont użytkowników jest zablokowne. W celu dodania nowego konta użytkownika proszę o kontakt z Administratorem pod adresem e-mail: suplalocal@langnet.eu';

    private string $storageFile;

    public function __construct(?string $storageFile = null) {
        $this->storageFile = $storageFile ?: (getenv('ADMIN_REGISTRATION_BLOCK_FILE') ?: '/var/www/cloud/var/admin_registration_block.json');
    }

    /**
     * @return array{blocked:bool,changedAt:?string,changedBy:?string,message:string}
     */
    public function getState(): array {
        $data = $this->read();
        return $this->normalize($data);
    }

    public function isBlocked(): bool {
        return (bool)$this->getState()['blocked'];
    }

    public function getBlockedMessage(): string {
        return self::DEFAULT_MESSAGE;
    }

    /**
     * @return array{blocked:bool,changedAt:?string,changedBy:?string,message:string}
     */
    public function setBlocked(bool $blocked, ?string $changedBy = null): array {
        $data = $this->read();
        $data['state'] = [
            'blocked' => $blocked,
            'changedAt' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'changedBy' => $changedBy !== null && trim($changedBy) !== '' ? trim($changedBy) : null,
            'message' => self::DEFAULT_MESSAGE,
        ];
        $this->write($data);
        return $this->normalize($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array {
        if (!is_file($this->storageFile) || filesize($this->storageFile) === 0) {
            return [];
        }
        $content = @file_get_contents($this->storageFile);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create registration block directory.');
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode registration block state.');
        }
        if (@file_put_contents($this->storageFile, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write registration block state.');
        }
        @chmod($this->storageFile, 0600);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{blocked:bool,changedAt:?string,changedBy:?string,message:string}
     */
    private function normalize(array $data): array {
        $state = is_array($data['state'] ?? null) ? $data['state'] : [];
        $blocked = array_key_exists('blocked', $state) ? (bool)$state['blocked'] : false;
        $changedAt = trim((string)($state['changedAt'] ?? ''));
        $changedBy = trim((string)($state['changedBy'] ?? ''));
        $message = trim((string)($state['message'] ?? self::DEFAULT_MESSAGE));
        if ($message === '') {
            $message = self::DEFAULT_MESSAGE;
        }
        return [
            'blocked' => $blocked,
            'changedAt' => $changedAt !== '' ? $changedAt : null,
            'changedBy' => $changedBy !== '' ? $changedBy : null,
            'message' => $message,
        ];
    }
}
