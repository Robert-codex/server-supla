<?php
namespace SuplaBundle\Security;

use RuntimeException;

class AdminPanelAccountStore {
    private const DEFAULT_ROLE = 'superadmin';
    private const ALLOWED_ROLES = ['superadmin', 'operator', 'readonly'];

    public function __construct(
        private string $secret,
        private ?string $storageFile = null,
        private ?string $auditLogFile = null,
        private ?string $attemptsFile = null
    ) {
        $this->storageFile = $storageFile ?: (getenv('ADMIN_PANEL_STORAGE_FILE') ?: '/var/www/cloud/var/admin_panel.json');
        $this->auditLogFile = $auditLogFile ?: (getenv('ADMIN_PANEL_AUDIT_LOG_FILE') ?: '/var/www/cloud/var/admin_panel_audit.log');
        $this->attemptsFile = $attemptsFile ?: (getenv('ADMIN_PANEL_ATTEMPTS_FILE') ?: '/var/www/cloud/var/admin_panel_attempts.json');
    }

    public function ensureInitializedFromEnvIfEmpty(): void {
        $file = $this->storageFile;
        if (is_file($file) && filesize($file) > 0) {
            return;
        }
        $username = (string)getenv('ADMIN_PANEL_USER');
        $passwordHash = (string)getenv('ADMIN_PANEL_PASSWORD_HASH');
        if ($username === '' || $passwordHash === '') {
            return;
        }
        $this->writeAccount([
            'admins' => [[
                'username' => $username,
                'passwordHash' => $passwordHash,
                'email' => filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : '',
                'role' => self::DEFAULT_ROLE,
                'active' => true,
                'twoFactorEnabled' => false,
                'twoFactorSecret' => null,
                'twoFactorPendingSecret' => null,
                'twoFactorRecoveryCodes' => [],
                'passwordResetToken' => null,
                'passwordResetExpiresAt' => null,
            ]],
        ]);
    }

    public function getAccount(?string $username = null): array {
        $this->ensureInitializedFromEnvIfEmpty();
        $data = $this->readAccount();
        $admins = $this->normalizeAdmins($data);
        if ($username === null || $username === '') {
            return $admins[0] ?? [];
        }
        foreach ($admins as $admin) {
            if (hash_equals((string)$admin['username'], (string)$username)) {
                return $admin;
            }
        }
        return [];
    }

    /**
     * @return array<int, array>
     */
    public function getAdmins(): array {
        $this->ensureInitializedFromEnvIfEmpty();
        return $this->normalizeAdmins($this->readAccount());
    }

    public function getSecurityRolesForAdmin(array $admin): array {
        $role = $this->normalizeRole((string)($admin['role'] ?? self::DEFAULT_ROLE));
        $roles = ['ROLE_ADMIN_PANEL'];
        if ($role === 'superadmin') {
            $roles[] = 'ROLE_ADMIN_SUPER';
            $roles[] = 'ROLE_ADMIN_OPERATOR';
            $roles[] = 'ROLE_ADMIN_READONLY';
        } elseif ($role === 'operator') {
            $roles[] = 'ROLE_ADMIN_OPERATOR';
            $roles[] = 'ROLE_ADMIN_READONLY';
        } else {
            $roles[] = 'ROLE_ADMIN_READONLY';
        }
        return $roles;
    }

    public function verifyPassword(string $plainPassword, ?string $username = null): bool {
        $hash = $this->getAccount($username)['passwordHash'] ?? '';
        return is_string($hash) && $hash !== '' && password_verify($plainPassword, $hash);
    }

    public function setUsername(string $newUsername, ?string $username = null): void {
        $newUsername = trim($newUsername);
        if ($newUsername === '') {
            throw new RuntimeException('Username cannot be empty.');
        }
        $data = $this->readAccount();
        $admins = $this->normalizeAdmins($data);
        foreach ($admins as $admin) {
            if (hash_equals((string)$admin['username'], $newUsername) && !hash_equals((string)$admin['username'], (string)($username ?: $admins[0]['username'] ?? ''))) {
                throw new RuntimeException('Username already exists.');
            }
        }
        $admins = $this->mapAdmins($admins, $username, function (array $admin) use ($newUsername) {
            $admin['username'] = $newUsername;
            return $admin;
        });
        $data['admins'] = $admins;
        $this->writeAccount($data);
    }

    public function setPassword(string $newPassword, ?string $username = null): void {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hash failed.');
        }
        $data = $this->readAccount();
        $admins = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) use ($hash) {
            $admin['passwordHash'] = $hash;
            $admin['passwordResetToken'] = null;
            $admin['passwordResetExpiresAt'] = null;
            return $admin;
        });
        $data['admins'] = $admins;
        $this->writeAccount($data);
    }

    public function setEmail(string $email, ?string $username = null): void {
        $email = trim($email);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address.');
        }
        $data = $this->readAccount();
        $data['admins'] = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) use ($email) {
            $admin['email'] = $email;
            return $admin;
        });
        $this->writeAccount($data);
    }

    public function beginTwoFactorSetup(?string $username = null): array {
        $data = $this->readAccount();
        $secret = AdminPanelTotp::generateSecret();
        $admins = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) use ($secret) {
            $admin['twoFactorPendingSecret'] = $secret;
            return $admin;
        });
        $data['admins'] = $admins;
        $this->writeAccount($data);
        return ['secret' => $secret];
    }

    public function confirmTwoFactorSetup(string $code, ?string $username = null): array {
        $data = $this->readAccount();
        $account = $this->getAccount($username);
        if (!$account) {
            throw new RuntimeException('Admin account not found.');
        }
        $pending = (string)($account['twoFactorPendingSecret'] ?? '');
        if ($pending === '') {
            throw new RuntimeException('Two-factor setup not started.');
        }
        if (!AdminPanelTotp::verifyCode($pending, $code)) {
            throw new RuntimeException('Invalid two-factor code.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $hashes = array_map(static fn(string $c): string => password_hash($c, PASSWORD_DEFAULT), $recoveryCodes);

        $admins = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) use ($pending, $hashes) {
            $admin['twoFactorEnabled'] = true;
            $admin['twoFactorSecret'] = $pending;
            $admin['twoFactorPendingSecret'] = null;
            $admin['twoFactorRecoveryCodes'] = $hashes;
            return $admin;
        });
        $data['admins'] = $admins;
        $this->writeAccount($data);

        return ['recoveryCodes' => $recoveryCodes];
    }

    public function cancelTwoFactorSetup(?string $username = null): void {
        $data = $this->readAccount();
        $data['admins'] = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) {
            $admin['twoFactorPendingSecret'] = null;
            return $admin;
        });
        $this->writeAccount($data);
    }

    public function disableTwoFactor(?string $username = null): void {
        $data = $this->readAccount();
        $data['admins'] = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) {
            $admin['twoFactorEnabled'] = false;
            $admin['twoFactorSecret'] = null;
            $admin['twoFactorPendingSecret'] = null;
            $admin['twoFactorRecoveryCodes'] = [];
            return $admin;
        });
        $this->writeAccount($data);
    }

    public function isTwoFactorEnabled(?string $username = null): bool {
        $data = $this->getAccount($username);
        return (bool)($data['twoFactorEnabled'] ?? false) && is_string($data['twoFactorSecret'] ?? null) && $data['twoFactorSecret'] !== '';
    }

    public function verifyTwoFactorCodeOrRecovery(string $codeOrRecovery, ?string $username = null): bool {
        $data = $this->getAccount($username);
        $secret = is_string($data['twoFactorSecret'] ?? null) ? $data['twoFactorSecret'] : '';
        if ($secret !== '' && AdminPanelTotp::verifyCode($secret, $codeOrRecovery)) {
            return true;
        }
        $codeOrRecovery = strtoupper(trim($codeOrRecovery));
        if ($codeOrRecovery === '') {
            return false;
        }
        $hashes = is_array($data['twoFactorRecoveryCodes'] ?? null) ? $data['twoFactorRecoveryCodes'] : [];
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify($codeOrRecovery, $hash)) {
                unset($hashes[$index]);
                $data['twoFactorRecoveryCodes'] = array_values($hashes);
                $all = $this->readAccount();
                $all['admins'] = $this->mapAdmins($this->normalizeAdmins($all), $username, function (array $admin) use ($data) {
                    $admin['twoFactorRecoveryCodes'] = $data['twoFactorRecoveryCodes'];
                    return $admin;
                });
                $this->writeAccount($all);
                return true;
            }
        }
        return false;
    }

    public function addAdmin(string $username, string $password, string $role, bool $active = true): void {
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException('Username cannot be empty.');
        }
        if ($this->getAccount($username)) {
            throw new RuntimeException('Username already exists.');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hash failed.');
        }
        $data = $this->readAccount();
        $admins = $this->normalizeAdmins($data);
        $admins[] = [
            'username' => $username,
            'passwordHash' => $hash,
            'email' => '',
            'role' => $this->normalizeRole($role),
            'active' => $active,
            'twoFactorEnabled' => false,
            'twoFactorSecret' => null,
            'twoFactorPendingSecret' => null,
            'twoFactorRecoveryCodes' => [],
            'passwordResetToken' => null,
            'passwordResetExpiresAt' => null,
        ];
        $data['admins'] = $admins;
        $this->writeAccount($data);
    }

    public function createPasswordResetToken(string $username, string $email): string {
        $account = $this->getAccount($username);
        if (!$account) {
            throw new RuntimeException('Admin account not found.');
        }
        if (!hash_equals(strtolower((string)($account['email'] ?? '')), strtolower(trim($email)))) {
            throw new RuntimeException('Admin e-mail does not match.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = time() + 3600;
        $data = $this->readAccount();
        $data['admins'] = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) use ($token, $expiresAt) {
            $admin['passwordResetToken'] = $token;
            $admin['passwordResetExpiresAt'] = $expiresAt;
            return $admin;
        });
        $this->writeAccount($data);
        return $token;
    }

    public function getAccountByPasswordResetToken(string $token): array {
        foreach ($this->getAdmins() as $admin) {
            if (hash_equals((string)($admin['passwordResetToken'] ?? ''), $token) && (int)($admin['passwordResetExpiresAt'] ?? 0) > time()) {
                return $admin;
            }
        }
        return [];
    }

    public function resetPasswordWithToken(string $token, string $newPassword): string {
        $account = $this->getAccountByPasswordResetToken($token);
        if (!$account) {
            throw new RuntimeException('Reset token is invalid or expired.');
        }
        $this->setPassword($newPassword, (string)$account['username']);
        return (string)$account['username'];
    }

    public function updateAdminRole(string $username, string $role): void {
        $current = $this->getAccount($username);
        if (!$current) {
            throw new RuntimeException('Admin account not found.');
        }
        $newRole = $this->normalizeRole($role);
        if (($current['role'] ?? self::DEFAULT_ROLE) === 'superadmin' && $newRole !== 'superadmin' && $this->countActiveSuperadmins() <= 1 && (bool)($current['active'] ?? true)) {
            throw new RuntimeException('At least one active superadmin must remain.');
        }
        $data = $this->readAccount();
        $data['admins'] = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) use ($newRole) {
            $admin['role'] = $newRole;
            return $admin;
        });
        $this->writeAccount($data);
    }

    public function setAdminActive(string $username, bool $active): void {
        $current = $this->getAccount($username);
        if (!$current) {
            throw new RuntimeException('Admin account not found.');
        }
        if (!$active && ($current['role'] ?? self::DEFAULT_ROLE) === 'superadmin' && (bool)($current['active'] ?? true) && $this->countActiveSuperadmins() <= 1) {
            throw new RuntimeException('At least one active superadmin must remain.');
        }
        $data = $this->readAccount();
        $data['admins'] = $this->mapAdmins($this->normalizeAdmins($data), $username, function (array $admin) use ($active) {
            $admin['active'] = $active;
            return $admin;
        });
        $this->writeAccount($data);
    }

    public function deleteAdmin(string $username): void {
        $admins = $this->getAdmins();
        if (count($admins) <= 1) {
            throw new RuntimeException('At least one admin account must remain.');
        }
        $current = $this->getAccount($username);
        if (!$current) {
            throw new RuntimeException('Admin account not found.');
        }
        if (($current['role'] ?? self::DEFAULT_ROLE) === 'superadmin' && (bool)($current['active'] ?? true) && $this->countActiveSuperadmins() <= 1) {
            throw new RuntimeException('At least one active superadmin must remain.');
        }
        $filtered = array_values(array_filter($admins, static fn(array $admin): bool => !hash_equals((string)($admin['username'] ?? ''), $username)));
        if (count($filtered) === count($admins)) {
            throw new RuntimeException('Admin account not found.');
        }
        $data = $this->readAccount();
        $data['admins'] = $filtered;
        $this->writeAccount($data);
    }

    public function audit(string $event, array $meta = []): void {
        $dir = dirname($this->auditLogFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $line = json_encode([
            'ts' => date(DATE_ATOM),
            'event' => $event,
            'meta' => $meta,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }
        if (@file_put_contents($this->auditLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
            @chmod($this->auditLogFile, 0600);
        }
    }

    public function registerFailedLoginAttempt(?string $ipAddress): int {
        $key = $this->normalizeIpKey($ipAddress);
        $attempts = $this->readAttempts();
        $entry = is_array($attempts[$key] ?? null) ? $attempts[$key] : [];
        $entry['count'] = (int)($entry['count'] ?? 0) + 1;
        $entry['blockedUntil'] = 0;
        if ($entry['count'] >= 5) {
            $entry['blockedUntil'] = time() + 900;
        }
        $entry['lastFailedAt'] = time();
        $attempts[$key] = $entry;
        $this->writeAttempts($attempts);
        return (int)$entry['blockedUntil'];
    }

    public function clearFailedLoginAttempts(?string $ipAddress): void {
        $key = $this->normalizeIpKey($ipAddress);
        $attempts = $this->readAttempts();
        unset($attempts[$key]);
        $this->writeAttempts($attempts);
    }

    public function isLoginBlocked(?string $ipAddress): bool {
        return $this->getLoginBlockRemainingSeconds($ipAddress) > 0;
    }

    public function getLoginBlockRemainingSeconds(?string $ipAddress): int {
        $key = $this->normalizeIpKey($ipAddress);
        $attempts = $this->readAttempts();
        $blockedUntil = (int)(is_array($attempts[$key] ?? null) ? ($attempts[$key]['blockedUntil'] ?? 0) : 0);
        $remaining = $blockedUntil - time();
        if ($remaining <= 0) {
            if (isset($attempts[$key])) {
                unset($attempts[$key]);
                $this->writeAttempts($attempts);
            }
            return 0;
        }
        return $remaining;
    }

    public function getAuditTail(int $lines = 60): array {
        $file = $this->auditLogFile;
        if (!is_file($file)) {
            return [];
        }
        $content = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($content)) {
            return [];
        }
        return array_slice($content, max(0, count($content) - $lines));
    }

    /**
     * @return array<int, array{ts?: string, event?: string, meta?: array}>
     */
    public function getAuditEntries(int $lines = 200): array {
        $entries = [];
        foreach ($this->getAuditTail($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    private function generateRecoveryCodes(): array {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
        }
        return $codes;
    }

    private function readAccount(): array {
        $file = $this->storageFile;
        if (!is_file($file) || filesize($file) === 0) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array> $admins
     * @return array<int, array>
     */
    private function mapAdmins(array $admins, ?string $username, callable $updater): array {
        $target = $username ?: (string)($admins[0]['username'] ?? '');
        $updated = false;
        foreach ($admins as $index => $admin) {
            if (hash_equals((string)($admin['username'] ?? ''), $target)) {
                $admins[$index] = $updater($admin);
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            throw new RuntimeException('Admin account not found.');
        }
        return $admins;
    }

    /**
     * @return array<int, array>
     */
    private function normalizeAdmins(array $data): array {
        $admins = [];
        if (is_array($data['admins'] ?? null)) {
            $admins = $data['admins'];
        } elseif (($data['username'] ?? '') !== '' || ($data['passwordHash'] ?? '') !== '') {
            $admins = [[
                'username' => (string)($data['username'] ?? ''),
                'passwordHash' => (string)($data['passwordHash'] ?? ''),
                'email' => (string)($data['email'] ?? ''),
                'role' => (string)($data['role'] ?? self::DEFAULT_ROLE),
                'active' => (bool)($data['active'] ?? true),
                'twoFactorEnabled' => (bool)($data['twoFactorEnabled'] ?? false),
                'twoFactorSecret' => $data['twoFactorSecret'] ?? null,
                'twoFactorPendingSecret' => $data['twoFactorPendingSecret'] ?? null,
                'twoFactorRecoveryCodes' => $data['twoFactorRecoveryCodes'] ?? [],
                'passwordResetToken' => $data['passwordResetToken'] ?? null,
                'passwordResetExpiresAt' => $data['passwordResetExpiresAt'] ?? null,
            ]];
        }

        return array_values(array_map(function ($admin) {
            return [
                'username' => (string)($admin['username'] ?? ''),
                'passwordHash' => (string)($admin['passwordHash'] ?? ''),
                'email' => trim((string)($admin['email'] ?? '')),
                'role' => $this->normalizeRole((string)($admin['role'] ?? self::DEFAULT_ROLE)),
                'active' => (bool)($admin['active'] ?? true),
                'twoFactorEnabled' => (bool)($admin['twoFactorEnabled'] ?? false),
                'twoFactorSecret' => is_string($admin['twoFactorSecret'] ?? null) ? $admin['twoFactorSecret'] : null,
                'twoFactorPendingSecret' => is_string($admin['twoFactorPendingSecret'] ?? null) ? $admin['twoFactorPendingSecret'] : null,
                'twoFactorRecoveryCodes' => is_array($admin['twoFactorRecoveryCodes'] ?? null) ? $admin['twoFactorRecoveryCodes'] : [],
                'passwordResetToken' => is_string($admin['passwordResetToken'] ?? null) ? $admin['passwordResetToken'] : null,
                'passwordResetExpiresAt' => isset($admin['passwordResetExpiresAt']) ? (int)$admin['passwordResetExpiresAt'] : null,
            ];
        }, array_filter($admins, static fn($admin) => is_array($admin))));
    }

    private function normalizeRole(string $role): string {
        $role = strtolower(trim($role));
        return in_array($role, self::ALLOWED_ROLES, true) ? $role : self::DEFAULT_ROLE;
    }

    private function countActiveSuperadmins(): int {
        $count = 0;
        foreach ($this->getAdmins() as $admin) {
            if ((bool)($admin['active'] ?? true) && ($admin['role'] ?? self::DEFAULT_ROLE) === 'superadmin') {
                $count++;
            }
        }
        return $count;
    }

    private function writeAccount(array $data): void {
        $file = $this->storageFile;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode admin store.');
        }
        if (@file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write admin store.');
        }
        @chmod($file, 0600);
    }

    private function readAttempts(): array {
        $file = $this->attemptsFile;
        if (!$file || !is_file($file) || filesize($file) === 0) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeAttempts(array $attempts): void {
        $file = $this->attemptsFile;
        if (!$file) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        if (@file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false) {
            @chmod($file, 0600);
        }
    }

    private function normalizeIpKey(?string $ipAddress): string {
        $ipAddress = trim((string)$ipAddress);
        return $ipAddress !== '' ? $ipAddress : 'unknown';
    }
}
