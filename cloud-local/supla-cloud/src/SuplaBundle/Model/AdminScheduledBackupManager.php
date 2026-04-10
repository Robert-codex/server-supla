<?php

namespace SuplaBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use SuplaBundle\Security\AdminBackupScheduleStore;
use SuplaBundle\Security\AdminPanelAccountStore;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AdminScheduledBackupManager {
    public function __construct(
        private ParameterBagInterface $params,
        private AdminBackupScheduleStore $scheduleStore,
        private AdminPanelAccountStore $auditStore
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function runDueScheduledBackup(bool $force = false, string $actor = 'cron'): array {
        $schedule = $this->scheduleStore->getSchedule();
        if (!$force && empty($schedule['enabled'])) {
            return [
                'status' => 'disabled',
                'message' => 'Automatic backups are disabled.',
            ];
        }

        $now = new \DateTimeImmutable('now');
        if (!$force && !$this->isDueNow($schedule, $now)) {
            return [
                'status' => 'idle',
                'message' => 'Backup is not due now.',
                'nextRunAt' => $this->describeNextRun($schedule, $now),
            ];
        }

        return $this->executeBackup($schedule, $actor, $force ? 'manual' : 'scheduled');
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    public function runImmediateBackup(array $schedule, string $actor = 'admin'): array {
        return $this->executeBackup($schedule, $actor, 'manual');
    }

    /**
     * @return array<string, mixed>
     */
    private function executeBackup(array $schedule, string $actor, string $kind): array {
        $db = $this->getDatabaseConfig();
        $previousSchedule = $this->scheduleStore->getSchedule();
        $prefix = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($schedule['prefix'] ?? 'supla-auto-backup')) ?: 'supla-auto-backup';
        $directory = '/var/www/cloud/var/backups';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backup directory.');
        }

        $fileName = $prefix . '-' . date('Ymd-His') . '.sql';
        $targetFile = $directory . '/' . $fileName;
        $command = $this->buildDumpCommand($db);
        $result = $this->runCommandToFile($command, $targetFile, (string)$db['password']);
        if ((int)($result['code'] ?? 1) !== 0) {
            @unlink($targetFile);
            $message = 'Scheduled backup failed: ' . trim((string)($result['stderr'] ?? 'unknown error'));
            $this->scheduleStore->updateRunState([
                'lastRunAt' => $this->nowIso(),
                'lastStatus' => 'failed',
                'lastMessage' => $message,
                'lastBackupFile' => null,
                'lastBackupSize' => null,
            ]);
            $this->auditStore->audit('admin_backup_scheduled_failed', [
                'admin' => $actor,
                'mode' => $kind,
                'message' => $message,
            ]);
            $this->notifyAdminsAboutFailure($schedule, $message, $previousSchedule, $kind, $actor);
            return [
                'status' => 'failed',
                'message' => $message,
            ];
        }

        $size = @filesize($targetFile) ?: null;
        @chmod($targetFile, 0600);
        $removed = $this->pruneOldBackups($directory, $prefix, (int)($schedule['retention'] ?? 7));
        $this->scheduleStore->updateRunState([
            'enabled' => (bool)($schedule['enabled'] ?? false),
            'mode' => (string)($schedule['mode'] ?? 'daily'),
            'time' => (string)($schedule['time'] ?? '03:00'),
            'days' => (array)($schedule['days'] ?? []),
            'retention' => (int)($schedule['retention'] ?? 7),
            'prefix' => $prefix,
            'lastRunAt' => $this->nowIso(),
            'lastStatus' => 'ok',
            'lastMessage' => sprintf('Backup created: %s', $fileName),
            'lastBackupFile' => $fileName,
            'lastBackupSize' => $size,
        ]);
        $this->auditStore->audit($kind === 'manual' ? 'admin_backup_scheduled_run_now' : 'admin_backup_scheduled_run', [
            'admin' => $actor,
            'file' => $fileName,
            'size' => $size,
            'mode' => $kind,
            'retentionRemoved' => $removed,
        ]);

        return [
            'status' => 'ok',
            'message' => sprintf('Backup created: %s', $fileName),
            'file' => $fileName,
            'size' => $size,
            'removed' => $removed,
        ];
    }

    /**
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $previousSchedule
     */
    private function notifyAdminsAboutFailure(array $schedule, string $message, array $previousSchedule, string $kind, string $actor): void {
        if ((string)($previousSchedule['lastStatus'] ?? '') === 'failed') {
            return;
        }

        $recipients = [];
        foreach ($this->auditStore->getAdmins() as $admin) {
            if (!(bool)($admin['active'] ?? true)) {
                continue;
            }
            $email = trim((string)($admin['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $recipients[$email] = true;
        }
        if (!$recipients) {
            return;
        }

        $to = implode(', ', array_keys($recipients));
        $subject = 'SUPLA Admin: scheduled backup failed';
        $body = implode("\n", [
            'A scheduled backup failed on the SUPLA admin panel.',
            '',
            'Mode: ' . $kind,
            'Actor: ' . $actor,
            'Schedule: ' . (string)($schedule['mode'] ?? 'daily') . ' ' . (string)($schedule['time'] ?? '03:00'),
            'Retention: ' . (int)($schedule['retention'] ?? 7),
            'Prefix: ' . (string)($schedule['prefix'] ?? 'supla-auto-backup'),
            'Message: ' . $message,
            'Time: ' . $this->nowIso(),
        ]);

        @mail($to, $subject, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function getDatabaseConfig(): array {
        $port = $this->params->has('database_port') ? (int)$this->params->get('database_port') : 0;
        return [
            'host' => (string)$this->params->get('database_host'),
            'name' => (string)$this->params->get('database_name'),
            'user' => (string)$this->params->get('database_user'),
            'password' => (string)$this->params->get('database_password'),
            'port' => $port > 0 ? $port : null,
        ];
    }

    /**
     * @param array<string, mixed> $db
     */
    private function buildDumpCommand(array $db): string {
        $parts = [
            '/usr/bin/mysqldump',
            '--host=' . escapeshellarg((string)$db['host']),
            '--user=' . escapeshellarg((string)$db['user']),
            '--protocol=tcp',
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            escapeshellarg((string)$db['name']),
        ];
        if (!empty($db['port'])) {
            array_splice($parts, 3, 0, '--port=' . (int)$db['port']);
        }
        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommandToFile(string $command, string $outputFile, string $password): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outputFile, 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, ['MYSQL_PWD' => $password]);
        if (!is_resource($process)) {
            return ['code' => 1, 'stderr' => 'Unable to start backup process.'];
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stderr = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }
        $code = proc_close($process);
        return ['code' => $code, 'stderr' => $stderr];
    }

    private function pruneOldBackups(string $directory, string $prefix, int $retention): int {
        $retention = max(1, min(90, $retention));
        $files = glob($directory . '/' . $prefix . '-*.sql') ?: [];
        usort($files, static function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });
        $removed = 0;
        foreach (array_slice($files, $retention) as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function isDueNow(array $schedule, \DateTimeImmutable $now): bool {
        $time = (string)($schedule['time'] ?? '03:00');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = '03:00';
        }
        if ($now->format('H:i') !== $time) {
            return false;
        }
        if (($schedule['mode'] ?? 'daily') === 'weekly') {
            $days = array_values(array_unique(array_filter(array_map('intval', (array)($schedule['days'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
            return in_array((int)$now->format('N'), $days, true);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function describeNextRun(array $schedule, \DateTimeImmutable $now): ?string {
        $next = $this->scheduleStore->describeNextRun($schedule, $now);
        return $next ? $next->format(DATE_ATOM) : null;
    }

    private function nowIso(): string {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
