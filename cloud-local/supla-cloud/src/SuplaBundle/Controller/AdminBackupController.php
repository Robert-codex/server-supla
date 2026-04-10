<?php
namespace SuplaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\Entity\Main\AccessID;
use SuplaBundle\Entity\Main\Location;
use SuplaBundle\Entity\Main\User;
use SuplaBundle\Security\AdminPanelAccountStore;
use SuplaBundle\Security\AdminPanelUser;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class AdminBackupController extends Controller {
    use AdminUiTrait;

    private const LOCALE_COOKIE = 'supla_admin_locale';
    private const BACKUP_TOKEN = 'admin_backup_download';
    private const RESTORE_TOKEN = 'admin_backup_restore';
    private const USERS_EXPORT_TOKEN = 'admin_backup_users_export';
    private const USERS_IMPORT_TOKEN = 'admin_backup_users_import';
    private const RESTORE_CONFIRMATION = 'RESTORE';
    private const IMPORT_CONFIRMATION = 'IMPORT';

    /**
     * @Route("/admin/backup", name="admin_backup", methods={"GET"})
     */
    public function backupAction(Request $request, EntityManagerInterface $em, ParameterBagInterface $params, AdminPanelAccountStore $store): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($response = $this->handleLocaleSwitch($request, '/admin/backup')) {
            return $response;
        }

        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $msg = (string)$request->query->get('msg', '');
        $err = (string)$request->query->get('err', '');
        $db = $this->getDatabaseConfig($params);
        $dbStats = $this->getDatabaseStats($em);
        $history = $this->getBackupHistoryRows($store);
        $html = $this->adminUiLayoutOpen($escape($tr('title')), 'backup', true, '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:14px;}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}.ui-page-tools{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px 0;flex-wrap:wrap;}.ui-page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}.ui-page-actions a{padding:6px 10px;border-radius:999px;background:#f6f8f9;border:1px solid #dfe5ea;text-decoration:none !important;}');
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted">' . $escape($tr('title')) . '</div>'
            . '<div class="ui-page-actions"><a href="/admin/backup?lang=pl" style="' . ($locale === 'pl' ? 'font-weight:700;' : '') . '">Polski</a><a href="/admin/backup?lang=en" style="' . ($locale === 'en' ? 'font-weight:700;' : '') . '">English</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>'
            . '<h1>' . $escape($tr('title')) . '</h1>';
        $html .= '<div class="sub">' . $escape($tr('subtitle')) . '</div>';
        if ($msg !== '') {
            $html .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $html .= '<div class="notice bad">' . $escape($err) . '</div>';
        }

        $html .= '<div class="grid">'
            . '<div class="card"><h3><span>' . $escape($tr('database_card')) . '</span><span class="badge ok">' . $escape($tr('ready')) . '</span></h3>'
            . '<div class="hint">'
            . '<div><b>' . $escape($tr('database_host')) . ':</b> <span class="mono">' . $escape((string)$db['host']) . '</span></div>'
            . '<div><b>' . $escape($tr('database_name')) . ':</b> <span class="mono">' . $escape((string)$db['name']) . '</span></div>'
            . '<div><b>' . $escape($tr('database_user')) . ':</b> <span class="mono">' . $escape((string)$db['user']) . '</span></div>'
            . '<div><b>' . $escape($tr('database_version')) . ':</b> <span class="mono">' . $escape((string)$dbStats['version']) . '</span></div>'
            . '<div><b>' . $escape($tr('database_tables')) . ':</b> <span class="mono">' . $escape((string)$dbStats['tables']) . '</span></div>'
            . '<div><b>' . $escape($tr('database_migrations')) . ':</b> <span class="mono">' . $escape((string)$dbStats['migrations']) . '</span></div>'
            . '</div></div>'
            . '<div class="card"><h3><span>' . $escape($tr('backup_card')) . '</span><span class="badge warn">' . $escape($tr('dangerous')) . '</span></h3>'
            . '<div class="hint">' . $escape($tr('backup_help')) . '</div>'
            . '<form method="post" action="/admin/backup/download">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken(self::BACKUP_TOKEN)) . '" />'
            . '<button type="submit">' . $escape($tr('download_backup')) . '</button>'
            . '</form>'
            . '</div>'
            . '</div>';

        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('restore_card')) . '</h3>'
            . '<div class="hint" style="margin-bottom:10px;">' . $escape($tr('restore_help')) . '</div>'
            . '<form method="post" action="/admin/backup/restore" enctype="multipart/form-data">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken(self::RESTORE_TOKEN)) . '" />'
            . '<label>' . $escape($tr('backup_file')) . '</label><input type="file" name="backupFile" accept=".sql,.gz,.sql.gz" required />'
            . '<label>' . $escape($tr('restore_confirmation')) . '</label><input type="text" name="confirmation" placeholder="RESTORE" required />'
            . '<button type="submit" class="gray">' . $escape($tr('restore_button')) . '</button>'
            . '</form>'
            . '<div class="hint" style="margin-top:10px;">' . $escape($tr('restore_warning')) . '</div>'
            . '</div>';

        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('users_card')) . '</h3>'
            . '<div class="hint" style="margin-bottom:10px;">' . $escape($tr('users_help')) . '</div>'
            . '<form method="post" action="/admin/backup/users/export" style="margin-bottom:16px;">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken(self::USERS_EXPORT_TOKEN)) . '" />'
            . '<button type="submit">' . $escape($tr('download_users_export')) . '</button>'
            . '</form>'
            . '<form method="post" action="/admin/backup/users/import" enctype="multipart/form-data">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken(self::USERS_IMPORT_TOKEN)) . '" />'
            . '<label>' . $escape($tr('users_file')) . '</label><input type="file" name="usersFile" accept=".json,.json.gz,application/json" required />'
            . '<label>' . $escape($tr('users_confirmation')) . '</label><input type="text" name="confirmation" placeholder="IMPORT" required />'
            . '<button type="submit" class="gray">' . $escape($tr('restore_button')) . '</button>'
            . '</form>'
            . '<div class="hint" style="margin-top:10px;">' . $escape($tr('users_warning')) . '</div>'
            . '</div>';

        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('backup_schedule')) . '</h3>'
            . '<div class="hint">' . $escape($tr('backup_schedule_help')) . '</div>'
            . '<div style="margin-top:10px;"><a href="/admin/backup/scheduler">' . $escape($tr('open_scheduler')) . '</a></div>'
            . '</div>';

        $historyRows = '';
        foreach ($history as $row) {
            $historyRows .= '<tr>'
                . '<td class="mono">' . $escape((string)($row['ts'] ?? '')) . '</td>'
                . '<td>' . $escape((string)($row['event'] ?? '')) . '</td>'
                . '<td>' . $escape((string)($row['admin'] ?? '-')) . '</td>'
                . '<td>' . $escape((string)($row['details'] ?? '')) . '</td>'
                . '</tr>';
        }
        if ($historyRows === '') {
            $historyRows = '<tr><td colspan="4" style="color:#666;">' . $escape($tr('no_history')) . '</td></tr>';
        }

        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('history_card')) . '</h3>'
            . '<div class="hint" style="margin-bottom:10px;">' . $escape($tr('history_help')) . '</div>'
            . '<table><thead><tr><th>' . $escape($tr('when')) . '</th><th>' . $escape($tr('event')) . '</th><th>' . $escape($tr('admin_actor')) . '</th><th>' . $escape($tr('details')) . '</th></tr></thead><tbody>' . $historyRows . '</tbody></table>'
            . '</div>';

        $html .= $this->adminUiLayoutClose();
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/backup/download", name="admin_backup_download", methods={"POST"})
     */
    public function downloadAction(Request $request, ParameterBagInterface $params, AdminPanelAccountStore $store): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($response = $this->handleLocaleSwitch($request, '/admin/backup')) {
            return $response;
        }
        if (!$this->isValidCsrf($request, self::BACKUP_TOKEN)) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Invalid CSRF token.'));
        }

        $db = $this->getDatabaseConfig($params);
        $backupFile = tempnam(sys_get_temp_dir(), 'supla-backup-');
        if ($backupFile === false) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Unable to create backup file.'));
        }

        $command = $this->buildDumpCommand($db);
        $result = $this->runCommandToFile($command, $backupFile, (string)$db['password']);
        if (($result['code'] ?? 1) !== 0) {
            @unlink($backupFile);
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Backup failed: ' . trim((string)($result['stderr'] ?? 'unknown error'))));
        }

        $response = new BinaryFileResponse($backupFile);
        $response->deleteFileAfterSend(true);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'supla-backup-' . date('Ymd-His') . '.sql')
        );
        $store->audit('admin_backup_downloaded', [
            'admin' => $this->currentAdminUsername(),
            'file' => 'supla-backup-' . date('Ymd-His') . '.sql',
            'size' => @filesize($backupFile) ?: null,
        ]);
        return $response;
    }

    /**
     * @Route("/admin/backup/restore", name="admin_backup_restore", methods={"POST"})
     */
    public function restoreAction(Request $request, ParameterBagInterface $params, AdminPanelAccountStore $store): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($response = $this->handleLocaleSwitch($request, '/admin/backup')) {
            return $response;
        }
        if (!$this->isValidCsrf($request, self::RESTORE_TOKEN)) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Invalid CSRF token.'));
        }

        $confirmation = strtoupper(trim((string)$request->request->get('confirmation', '')));
        if ($confirmation !== self::RESTORE_CONFIRMATION) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Type RESTORE to confirm the restore.'));
        }

        $file = $request->files->get('backupFile');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Please select a valid backup file.'));
        }

        $db = $this->getDatabaseConfig($params);
        $command = $this->buildRestoreCommand($db);
        $result = $this->runCommandFromFile($command, $file->getPathname(), (string)$db['password'], $this->isGzFile($file));
        if (($result['code'] ?? 1) !== 0) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Restore failed: ' . trim((string)($result['stderr'] ?? 'unknown error'))));
        }

        $store->audit('admin_backup_restored', [
            'admin' => $this->currentAdminUsername(),
            'file' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);
        return new RedirectResponse('/admin/backup?msg=' . rawurlencode('Backup restored successfully.'));
    }

    /**
     * @Route("/admin/backup/users/export", name="admin_backup_users_export", methods={"POST"})
     */
    public function exportUsersAction(Request $request, EntityManagerInterface $em, AdminPanelAccountStore $store): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($response = $this->handleLocaleSwitch($request, '/admin/backup')) {
            return $response;
        }
        if (!$this->isValidCsrf($request, self::USERS_EXPORT_TOKEN)) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Invalid CSRF token.'));
        }

        $payload = $this->buildUsersExportPayload($em);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Users export failed.'));
        }

        $response = new Response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="supla-users-' . date('Ymd-His') . '.json"'
        );
        $store->audit('admin_backup_users_exported', [
            'admin' => $this->currentAdminUsername(),
            'users' => count($payload['users'] ?? []),
        ]);
        return $response;
    }

    /**
     * @Route("/admin/backup/users/import", name="admin_backup_users_import", methods={"POST"})
     */
    public function importUsersAction(Request $request, EntityManagerInterface $em, AdminPanelAccountStore $store): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($response = $this->handleLocaleSwitch($request, '/admin/backup')) {
            return $response;
        }
        if (!$this->isValidCsrf($request, self::USERS_IMPORT_TOKEN)) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Invalid CSRF token.'));
        }

        $confirmation = strtoupper(trim((string)$request->request->get('confirmation', '')));
        if ($confirmation !== self::IMPORT_CONFIRMATION) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Type IMPORT to confirm the users import.'));
        }

        $file = $request->files->get('usersFile');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Please select a valid JSON file.'));
        }

        $payload = $this->readUsersPayload($file->getPathname(), $this->isGzFile($file));
        if (!is_array($payload)) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Users import failed: invalid JSON payload.'));
        }

        try {
            $summary = $this->importUsersPayload($em, $payload);
        } catch (\Throwable $e) {
            return new RedirectResponse('/admin/backup?err=' . rawurlencode('Users import failed: ' . $e->getMessage()));
        }

        $store->audit('admin_backup_users_imported', [
            'admin' => $this->currentAdminUsername(),
            'users' => $summary['users'],
            'locations' => $summary['locations'],
            'accessIds' => $summary['accessIds'],
        ]);
        return new RedirectResponse('/admin/backup?msg=' . rawurlencode(sprintf('Imported %d users, %d locations and %d access IDs.', $summary['users'], $summary['locations'], $summary['accessIds'])));
    }

    private function getDatabaseConfig(ParameterBagInterface $params): array {
        $port = $params->has('database_port') ? (int)$params->get('database_port') : 0;
        return [
            'host' => (string)$params->get('database_host'),
            'name' => (string)$params->get('database_name'),
            'user' => (string)$params->get('database_user'),
            'password' => (string)$params->get('database_password'),
            'port' => $port > 0 ? $port : null,
        ];
    }

    private function getDatabaseStats(EntityManagerInterface $em): array {
        try {
            $conn = $em->getConnection();
            return [
                'version' => (string)$conn->fetchOne('SELECT VERSION()'),
                'tables' => (int)$conn->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'),
                'migrations' => (int)$conn->fetchOne('SELECT COUNT(*) FROM migration_versions'),
            ];
        } catch (\Throwable $e) {
            return [
                'version' => 'n/a',
                'tables' => 0,
                'migrations' => 0,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUsersExportPayload(EntityManagerInterface $em): array {
        /** @var User[] $users */
        $users = $em->getRepository(User::class)->createQueryBuilder('u')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        $payloadUsers = [];
        foreach ($users as $user) {
            $payloadUsers[] = $this->exportUserRecord($user);
        }

        return [
            'version' => 1,
            'exportedAt' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'users' => $payloadUsers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportUserRecord(User $user): array {
        $locations = [];
        foreach ($user->getLocations() as $location) {
            $locationKey = 'loc-' . (int)$location->getId();
            $locations[$locationKey] = [
                'key' => $locationKey,
                'id' => (int)$location->getId(),
                'caption' => $location->getCaption(),
                'enabled' => (bool)$location->getEnabled(),
                'password' => (string)$location->getPassword(),
            ];
        }

        $accessIds = [];
        foreach ($user->getAccessIDS() as $accessId) {
            $locationKeys = [];
            foreach ($accessId->getLocations() as $location) {
                $locationKeys[] = 'loc-' . (int)$location->getId();
            }
            $accessIds[] = [
                'id' => (int)$accessId->getId(),
                'caption' => $accessId->getCaption(),
                'enabled' => (bool)$accessId->getEnabled(),
                'password' => $accessId->getPassword(),
                'activeFrom' => $this->formatExportDateTime($accessId->getActiveFrom()),
                'activeTo' => $this->formatExportDateTime($accessId->getActiveTo()),
                'activeHours' => $accessId->getActiveHours(),
                'locationKeys' => $locationKeys,
            ];
        }

        return [
            'email' => (string)$user->getEmail(),
            'enabled' => (bool)$user->isEnabled(),
            'passwordHash' => $user->getPassword(),
            'shortUniqueId' => $this->readObjectProperty($user, 'shortUniqueId'),
            'longUniqueId' => $this->readObjectProperty($user, 'longUniqueId'),
            'salt' => $user->getSalt(),
            'regDate' => $this->formatExportDateTime($user->getRegDate()),
            'timezone' => (string)$user->getTimezone(),
            'locale' => $user->getLocale(),
            'mqttBrokerEnabled' => (bool)$user->isMqttBrokerEnabled(),
            'mqttBrokerAuthPassword' => $this->readObjectProperty($user, 'mqttBrokerAuthPassword'),
            'apiRateLimit' => $user->getApiRateLimit() ? (string)$user->getApiRateLimit() : null,
            'preferences' => $user->getPreferences(),
            'agreements' => $user->getAgreements(),
            'limits' => $user->getLimits(),
            'locations' => array_values($locations),
            'accessIds' => $accessIds,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{users:int, locations:int, accessIds:int}
     */
    private function importUsersPayload(EntityManagerInterface $em, array $payload): array {
        if ((int)($payload['version'] ?? 0) !== 1) {
            throw new \RuntimeException('Unsupported users export version.');
        }
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        $createdUsers = 0;
        $createdLocations = 0;
        $createdAccessIds = 0;
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            foreach ($users as $userData) {
                if (!is_array($userData)) {
                    continue;
                }
                [$user, $locationMap] = $this->createUserFromExport($em, $userData);
                $createdUsers++;
                $createdLocations += count($locationMap);
                $createdAccessIds += $this->createAccessIdsFromExport($em, $user, $userData, $locationMap);
            }
            $em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return [
            'users' => $createdUsers,
            'locations' => $createdLocations,
            'accessIds' => $createdAccessIds,
        ];
    }

    /**
     * @param array<string, mixed> $userData
     * @return array{0: User, 1: array<string, Location>}
     */
    private function createUserFromExport(EntityManagerInterface $em, array $userData): array {
        $email = trim((string)($userData['email'] ?? ''));
        if ($email === '') {
            throw new \RuntimeException('User email is required.');
        }
        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            throw new \RuntimeException('User "' . $email . '" already exists.');
        }

        $user = new User();
        $user->setEmail($email);
        $user->setEnabled((bool)($userData['enabled'] ?? false));
        if (array_key_exists('passwordHash', $userData) && $userData['passwordHash'] !== null) {
            $user->setPassword((string)$userData['passwordHash']);
        }
        if (array_key_exists('salt', $userData) && $userData['salt'] !== null) {
            $this->setObjectProperty($user, 'salt', (string)$userData['salt']);
        }
        if (array_key_exists('shortUniqueId', $userData) && $userData['shortUniqueId'] !== null) {
            $this->setObjectProperty($user, 'shortUniqueId', (string)$userData['shortUniqueId']);
        }
        if (array_key_exists('longUniqueId', $userData) && $userData['longUniqueId'] !== null) {
            $this->setObjectProperty($user, 'longUniqueId', (string)$userData['longUniqueId']);
        }
        if (array_key_exists('regDate', $userData) && $userData['regDate']) {
            $this->setObjectProperty($user, 'regDate', new \DateTime((string)$userData['regDate']));
        }
        $timezone = trim((string)($userData['timezone'] ?? ''));
        if ($timezone !== '') {
            $user->setTimezone($timezone);
        }
        $locale = trim((string)($userData['locale'] ?? ''));
        if ($locale !== '') {
            $user->setLocale($locale);
        }
        $user->setMqttBrokerEnabled((bool)($userData['mqttBrokerEnabled'] ?? false));
        $mqttAuthPassword = $userData['mqttBrokerAuthPassword'] ?? null;
        if (is_string($mqttAuthPassword) && $mqttAuthPassword !== '') {
            $user->setMqttBrokerAuthPassword($mqttAuthPassword);
        }
        if (array_key_exists('apiRateLimit', $userData)) {
            $user->setApiRateLimit($userData['apiRateLimit']);
        }
        if (is_array($userData['limits'] ?? null)) {
            $this->applyUserLimitsFromExport($user, $userData['limits']);
        }
        if (is_array($userData['preferences'] ?? null)) {
            foreach ($userData['preferences'] as $name => $value) {
                $user->setPreference((string)$name, $value);
            }
        }
        $agreements = is_array($userData['agreements'] ?? null) ? $userData['agreements'] : [];
        if (!empty($agreements['rules'])) {
            $user->agreeOnRules();
        }
        if (!empty($agreements['cookies'])) {
            $user->agreeOnCookies();
        }

        $em->persist($user);

        $locationMap = [];
        $locations = is_array($userData['locations'] ?? null) ? $userData['locations'] : [];
        foreach ($locations as $locationData) {
            if (!is_array($locationData)) {
                continue;
            }
            $location = $this->createLocationFromExport($user, $locationData);
            $key = (string)($locationData['key'] ?? ('loc-' . count($locationMap)));
            $locationMap[$key] = $location;
            $em->persist($location);
        }

        return [$user, $locationMap];
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function applyUserLimitsFromExport(User $user, array $limits): void {
        $map = [
            'accessId' => 'limitAid',
            'channelGroup' => 'limitChannelGroup',
            'channelPerGroup' => 'limitChannelPerGroup',
            'clientApp' => 'limitClientApp',
            'directLink' => 'limitDirectLink',
            'ioDevice' => 'limitIoDev',
            'location' => 'limitLoc',
            'oauthClient' => 'limitOAuthClient',
            'operationsPerScene' => 'limitOperationsPerScene',
            'pushNotifications' => 'limitPushNotifications',
            'pushNotificationsPerHour' => 'limitPushNotificationsPerHour',
            'scene' => 'limitScene',
            'schedule' => 'limitSchedule',
            'valueBasedTriggers' => 'limitValueBasedTriggers',
            'virtualChannels' => 'limitVirtualChannels',
        ];
        foreach ($map as $publicName => $property) {
            if (!array_key_exists($publicName, $limits)) {
                continue;
            }
            $this->setObjectProperty($user, $property, (int)$limits[$publicName]);
        }
        if (array_key_exists('actionsPerSchedule', $limits)) {
            $this->setObjectProperty($user, 'limitActionsPerSchedule', (int)$limits['actionsPerSchedule']);
        }
    }

    /**
     * @param array<string, mixed> $locationData
     */
    private function createLocationFromExport(User $user, array $locationData): Location {
        $location = new Location();
        $this->setObjectProperty($location, 'user', $user);
        $location->setCaption(array_key_exists('caption', $locationData) ? $locationData['caption'] : null);
        $location->setEnabled((bool)($locationData['enabled'] ?? true));
        $password = $locationData['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $location->setPassword($password);
        } else {
            $location->generatePassword();
        }
        $user->getLocations()->add($location);
        return $location;
    }

    /**
     * @param array<string, mixed> $userData
     * @param array<string, Location> $locationMap
     */
    private function createAccessIdsFromExport(EntityManagerInterface $em, User $user, array $userData, array $locationMap): int {
        $count = 0;
        $accessIds = is_array($userData['accessIds'] ?? null) ? $userData['accessIds'] : [];
        foreach ($accessIds as $accessIdData) {
            if (!is_array($accessIdData)) {
                continue;
            }
            $accessId = new AccessID($user);
            $accessId->setCaption(array_key_exists('caption', $accessIdData) ? $accessIdData['caption'] : null);
            $accessId->setEnabled((bool)($accessIdData['enabled'] ?? true));
            $password = $accessIdData['password'] ?? null;
            if (is_string($password) && $password !== '') {
                $accessId->setPassword($password);
            }
            if (!empty($accessIdData['activeFrom'])) {
                $accessId->setActiveFrom(new \DateTime((string)$accessIdData['activeFrom']));
            }
            if (!empty($accessIdData['activeTo'])) {
                $accessId->setActiveTo(new \DateTime((string)$accessIdData['activeTo']));
            }
            $activeHours = $accessIdData['activeHours'] ?? null;
            if ($activeHours === null || is_array($activeHours)) {
                $accessId->setActiveHours($activeHours);
            }

            $locations = [];
            foreach ((array)($accessIdData['locationKeys'] ?? []) as $locationKey) {
                $locationKey = (string)$locationKey;
                if (isset($locationMap[$locationKey])) {
                    $locations[] = $locationMap[$locationKey];
                }
            }
            if ($locations) {
                $accessId->updateLocations($locations);
            }

            $em->persist($accessId);
            $count++;
        }
        return $count;
    }

    private function readUsersPayload(string $path, bool $gzipped): ?array {
        $content = $gzipped ? @gzdecode((string)@file_get_contents($path)) : @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, array{ts:string,event:string,admin:string,details:string}>
     */
    private function getBackupHistoryRows(AdminPanelAccountStore $store): array {
        $rows = [];
        foreach (array_reverse($store->getAuditEntries(100)) as $entry) {
            $event = (string)($entry['event'] ?? '');
            if (!str_starts_with($event, 'admin_backup_')) {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $rows[] = [
                'ts' => (string)($entry['ts'] ?? ''),
                'event' => $this->formatBackupAuditEvent($event),
                'admin' => (string)($meta['admin'] ?? ''),
                'details' => $this->formatBackupAuditDetails($event, $meta),
            ];
            if (count($rows) >= 20) {
                break;
            }
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function formatBackupAuditDetails(string $event, array $meta): string {
        return match ($event) {
            'admin_backup_downloaded', 'admin_backup_restored', 'admin_backup_scheduled_run', 'admin_backup_scheduled_run_now' => trim((string)($meta['file'] ?? '-')) . (($meta['size'] ?? null) !== null ? ' · ' . (string)$meta['size'] . ' B' : ''),
            'admin_backup_users_exported' => 'users=' . (int)($meta['users'] ?? 0),
            'admin_backup_users_imported' => 'users=' . (int)($meta['users'] ?? 0) . ', loc=' . (int)($meta['locations'] ?? 0) . ', aids=' . (int)($meta['accessIds'] ?? 0),
            'admin_backup_scheduled_failed' => (string)($meta['message'] ?? ''),
            default => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        };
    }

    private function formatBackupAuditEvent(string $event): string {
        return match ($event) {
            'admin_backup_downloaded' => 'backup downloaded',
            'admin_backup_restored' => 'backup restored',
            'admin_backup_users_exported' => 'users exported',
            'admin_backup_users_imported' => 'users imported',
            'admin_backup_scheduled_run' => 'scheduled backup',
            'admin_backup_scheduled_run_now' => 'manual backup run',
            'admin_backup_scheduled_failed' => 'scheduled backup failed',
            default => $event,
        };
    }

    private function currentAdminUsername(): string {
        $user = $this->getUser();
        return $user instanceof AdminPanelUser ? (string)$user->getUsername() : '';
    }

    private function formatExportDateTime(?\DateTimeInterface $dateTime): ?string {
        return $dateTime ? $dateTime->format(DATE_ATOM) : null;
    }

    private function readObjectProperty(object $object, string $property) {
        try {
            $ref = new \ReflectionProperty($object, $property);
            $ref->setAccessible(true);
            return $ref->getValue($object);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function setObjectProperty(object $object, string $property, $value): void {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

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

    private function buildRestoreCommand(array $db): string {
        $parts = [
            '/usr/bin/mysql',
            '--host=' . escapeshellarg((string)$db['host']),
            '--user=' . escapeshellarg((string)$db['user']),
            '--protocol=tcp',
            '--default-character-set=utf8mb4',
            escapeshellarg((string)$db['name']),
        ];
        if (!empty($db['port'])) {
            array_splice($parts, 3, 0, '--port=' . (int)$db['port']);
        }
        return implode(' ', $parts);
    }

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

    private function runCommandFromFile(string $command, string $inputFile, string $password, bool $gzipped): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, ['MYSQL_PWD' => $password]);
        if (!is_resource($process)) {
            return ['code' => 1, 'stderr' => 'Unable to start restore process.'];
        }

        $source = $gzipped ? @gzopen($inputFile, 'rb') : @fopen($inputFile, 'rb');
        if ($source === false) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            return ['code' => 1, 'stderr' => 'Unable to read uploaded backup file.'];
        }

        if ($gzipped) {
            while (!gzeof($source)) {
                $chunk = gzread($source, 1048576);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($pipes[0], $chunk);
            }
            gzclose($source);
        } else {
            while (!feof($source)) {
                $chunk = fread($source, 1048576);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($pipes[0], $chunk);
            }
            fclose($source);
        }
        fclose($pipes[0]);

        $stdout = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        $stderr = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }
        $code = proc_close($process);
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function isGzFile(UploadedFile $file): bool {
        $name = strtolower($file->getClientOriginalName() ?: $file->getFilename());
        return str_ends_with($name, '.gz');
    }

    private function isValidCsrf(Request $request, string $tokenId): bool {
        $token = (string)$request->request->get('_token', '');
        return $token !== '' && $this->get('security.csrf.token_manager')->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken($tokenId, $token));
    }

    private function csrfToken(string $tokenId): string {
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = $this->get('security.csrf.token_manager');
        return $csrfTokenManager->getToken($tokenId)->getValue();
    }

    private function requireAllowedAdminUser(): ?Response {
        $user = $this->getUser();
        if (!$user) {
            return new RedirectResponse('/admin/login');
        }
        if (!$user instanceof AdminPanelUser) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return null;
    }

    private function handleLocaleSwitch(Request $request, string $path): ?RedirectResponse {
        if (!$request->query->has('lang')) {
            return null;
        }
        $lang = strtolower(substr((string)$request->query->get('lang'), 0, 2));
        if (!in_array($lang, ['pl', 'en'], true)) {
            $lang = 'pl';
        }
        $params = $request->query->all();
        unset($params['lang']);
        $qs = http_build_query($params);
        $resp = new RedirectResponse($path . ($qs ? ('?' . $qs) : ''));
        $resp->headers->setCookie(new Cookie(self::LOCALE_COOKIE, $lang, time() + 3600 * 24 * 365, '/', null, $request->isSecure(), true, false, 'Lax'));
        return $resp;
    }

    private function getAdminLocale(Request $request): string {
        $cookie = strtolower(substr((string)$request->cookies->get(self::LOCALE_COOKIE, ''), 0, 2));
        return in_array($cookie, ['pl', 'en'], true) ? $cookie : 'pl';
    }

    private function translator(string $locale): callable {
        $dict = [
            'pl' => [
                'title' => 'SUPLA Admin - Backup / Restore',
                'subtitle' => 'Pełny backup i restore bazy danych przez GUI. To obejmuje użytkowników, limity, Access ID, lokalizacje, urządzenia i resztę danych zapisanych w DB.',
                'dashboard' => 'Dashboard',
                'users' => 'Użytkownicy',
                'account' => 'Konto',
                'security_log' => 'Log bezpieczeństwa',
                'system_health' => 'Stan systemu',
                'backup_restore' => 'Backup / Restore',
                'logout' => 'Wyloguj',
                'database_card' => 'Baza danych',
                'backup_card' => 'Wykonaj backup',
                'restore_card' => 'Przywróć backup',
                'ready' => 'Gotowe',
                'dangerous' => 'Uwaga',
                'database_host' => 'Host',
                'database_name' => 'Baza',
                'database_user' => 'Użytkownik',
                'database_version' => 'Wersja',
                'database_tables' => 'Tabele',
                'database_migrations' => 'Migracje',
                'backup_help' => 'Pobiera pełny zrzut SQL aktualnej bazy. Plik można później odtworzyć na tym samym środowisku lub po migracji.',
                'download_backup' => 'Pobierz pełny backup SQL',
                'restore_help' => 'Restore nadpisuje aktualną zawartość bazy. Wgraj plik SQL lub SQL.GZ i wpisz RESTORE, aby potwierdzić.',
                'backup_file' => 'Plik backupu',
                'restore_confirmation' => 'Wpisz RESTORE, aby potwierdzić',
                'restore_button' => 'Przywróć backup',
                'restore_warning' => 'To jest operacja destrukcyjna. Wykonaj ją tylko, jeśli rozumiesz skutki dla bieżących danych.',
                'users_card' => 'Eksport / import użytkowników',
                'users_help' => 'Eksport obejmuje użytkowników, limity, Access ID, lokacje, hasła, ustawienia i relacje między lokacjami oraz Access ID.',
                'download_users_export' => 'Pobierz eksport JSON użytkowników',
                'users_file' => 'Plik eksportu JSON',
                'users_confirmation' => 'Wpisz IMPORT, aby potwierdzić',
                'users_warning' => 'Import zakłada docelowe środowisko bez konfliktów adresów e-mail. Duplikaty są odrzucane.',
                'users_import_button' => 'Importuj użytkowników',
                'backup_schedule' => 'Harmonogram backupów',
                'backup_schedule_help' => 'Automatyczne backupy są konfigurowane na osobnej stronie i uruchamiane przez cron.',
                'open_scheduler' => 'Otwórz harmonogram backupów',
                'history_card' => 'Historia backupów',
                'history_help' => 'Ostatnie operacje backup/restore/import/export zapisane w logu admina.',
                'no_history' => 'Brak wpisów historii backupu.',
                'when' => 'Kiedy',
                'event' => 'Zdarzenie',
                'admin_actor' => 'Admin',
                'details' => 'Szczegóły',
            ],
            'en' => [
                'title' => 'SUPLA Admin - Backup / Restore',
                'subtitle' => 'Full database backup and restore through the GUI. This includes users, limits, Access IDs, locations, devices and the rest of the data stored in DB.',
                'dashboard' => 'Dashboard',
                'users' => 'Users',
                'account' => 'Account',
                'security_log' => 'Security log',
                'system_health' => 'System health',
                'backup_restore' => 'Backup / Restore',
                'logout' => 'Logout',
                'database_card' => 'Database',
                'backup_card' => 'Create backup',
                'restore_card' => 'Restore backup',
                'ready' => 'Ready',
                'dangerous' => 'Warning',
                'database_host' => 'Host',
                'database_name' => 'Database',
                'database_user' => 'User',
                'database_version' => 'Version',
                'database_tables' => 'Tables',
                'database_migrations' => 'Migrations',
                'backup_help' => 'Downloads a full SQL dump of the current database. The file can later be restored on the same environment or after migration.',
                'download_backup' => 'Download full SQL backup',
                'restore_help' => 'Restore overwrites the current database content. Upload an SQL or SQL.GZ file and type RESTORE to confirm.',
                'backup_file' => 'Backup file',
                'restore_confirmation' => 'Type RESTORE to confirm',
                'restore_button' => 'Restore backup',
                'restore_warning' => 'This is a destructive operation. Use it only if you understand the impact on current data.',
                'users_card' => 'Users export / import',
                'users_help' => 'The export includes users, limits, Access IDs, locations, passwords, settings and relations between locations and Access IDs.',
                'download_users_export' => 'Download users JSON export',
                'users_file' => 'JSON export file',
                'users_confirmation' => 'Type IMPORT to confirm',
                'users_warning' => 'Import assumes the target environment has no email conflicts. Duplicate emails are rejected.',
                'users_import_button' => 'Import users',
                'backup_schedule' => 'Backup schedule',
                'backup_schedule_help' => 'Automatic backups are configured on a separate page and executed by cron.',
                'open_scheduler' => 'Open backup scheduler',
                'history_card' => 'Backup history',
                'history_help' => 'Recent backup/restore/import/export operations recorded in the admin audit log.',
                'no_history' => 'No backup history entries.',
                'when' => 'When',
                'event' => 'Event',
                'admin_actor' => 'Admin',
                'details' => 'Details',
            ],
        ];
        $lang = isset($dict[$locale]) ? $locale : 'pl';
        return static fn(string $key): string => $dict[$lang][$key] ?? $key;
    }
}
