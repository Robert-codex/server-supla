<?php

namespace SuplaBundle\Security;

use RuntimeException;

class AdminBackupScheduleStore {
    private string $storageFile;

    public function __construct(?string $storageFile = null) {
        $this->storageFile = $storageFile ?: (getenv('ADMIN_BACKUP_SCHEDULE_FILE') ?: '/var/www/cloud/var/admin_backup_schedule.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchedule(): array {
        $data = $this->read();
        return $this->normalize($data);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    public function saveSchedule(array $schedule): array {
        $data = $this->read();
        $data['schedule'] = $this->normalizeSchedule($schedule);
        $this->write($data);
        return $data['schedule'];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function updateRunState(array $state): array {
        $data = $this->read();
        $data['schedule'] = $this->normalizeSchedule(array_merge($data['schedule'] ?? [], $state));
        $this->write($data);
        return $data['schedule'];
    }

    /**
     * @return array<string, mixed>
     */
    public function describeNextRun(array $schedule, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable {
        $now = $now ?: new \DateTimeImmutable('now');
        if (empty($schedule['enabled'])) {
            return null;
        }

        $timeParts = explode(':', (string)($schedule['time'] ?? '03:00'));
        $hour = (int)($timeParts[0] ?? 3);
        $minute = (int)($timeParts[1] ?? 0);

        if (($schedule['mode'] ?? 'daily') === 'weekly') {
            $days = array_values(array_unique(array_filter(array_map('intval', (array)($schedule['days'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
            if (!$days) {
                return null;
            }
            for ($offset = 0; $offset < 14; $offset++) {
                $candidate = $now->modify('+' . $offset . ' days')->setTime($hour, $minute, 0);
                if (in_array((int)$candidate->format('N'), $days, true) && $candidate > $now) {
                    return $candidate;
                }
                if (in_array((int)$candidate->format('N'), $days, true) && $candidate->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) {
                    return $candidate;
                }
            }
            return null;
        }

        $candidate = $now->setTime($hour, $minute, 0);
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }
        return $candidate;
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
            throw new RuntimeException('Unable to create backup schedule directory.');
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode backup schedule.');
        }
        if (@file_put_contents($this->storageFile, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write backup schedule.');
        }
        @chmod($this->storageFile, 0600);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array {
        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        return $this->normalizeSchedule($schedule);
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    private function normalizeSchedule(array $schedule): array {
        $mode = (string)($schedule['mode'] ?? 'daily');
        if (!in_array($mode, ['daily', 'weekly'], true)) {
            $mode = 'daily';
        }
        $time = (string)($schedule['time'] ?? '03:00');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = '03:00';
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($schedule['days'] ?? [1, 2, 3, 4, 5])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        if (!$days) {
            $days = [1, 2, 3, 4, 5];
        }
        $retention = max(1, min(90, (int)($schedule['retention'] ?? 7)));
        $prefix = trim((string)($schedule['prefix'] ?? 'supla-auto-backup'));
        if ($prefix === '') {
            $prefix = 'supla-auto-backup';
        }
        $lastRunAt = trim((string)($schedule['lastRunAt'] ?? ''));
        $lastStatus = trim((string)($schedule['lastStatus'] ?? ''));
        $lastMessage = trim((string)($schedule['lastMessage'] ?? ''));
        $lastBackupFile = trim((string)($schedule['lastBackupFile'] ?? ''));
        $lastBackupSize = isset($schedule['lastBackupSize']) ? (int)$schedule['lastBackupSize'] : null;

        return [
            'enabled' => (bool)($schedule['enabled'] ?? false),
            'mode' => $mode,
            'time' => $time,
            'days' => $days,
            'retention' => $retention,
            'prefix' => $prefix,
            'lastRunAt' => $lastRunAt !== '' ? $lastRunAt : null,
            'lastStatus' => $lastStatus !== '' ? $lastStatus : null,
            'lastMessage' => $lastMessage !== '' ? $lastMessage : null,
            'lastBackupFile' => $lastBackupFile !== '' ? $lastBackupFile : null,
            'lastBackupSize' => $lastBackupSize,
        ];
    }
}
