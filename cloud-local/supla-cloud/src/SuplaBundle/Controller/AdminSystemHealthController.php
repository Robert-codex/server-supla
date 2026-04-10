<?php
namespace SuplaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\Entity\Main\User;
use SuplaBundle\Security\AdminPanelUser;
use SuplaBundle\Security\AdminBackupScheduleStore;
use SuplaBundle\Security\AdminPanelAccountStore;
use SuplaBundle\Security\RegistrationBlockStore;
use SuplaBundle\Supla\SuplaServerAware;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminSystemHealthController extends Controller {
    use AdminUiTrait;
    use SuplaServerAware;

    private const LOCALE_COOKIE = 'supla_admin_locale';
    private const CERT_PATH = '/etc/apache2/ssl/server.crt';
    private const KEY_PATH = '/etc/apache2/ssl/server.key';

    /**
     * @Route("/admin/health", name="admin_health", methods={"GET"})
     * @Route("/admin/system-health", name="admin_system_health", methods={"GET"})
     */
    public function systemHealthAction(Request $request, EntityManagerInterface $em, ParameterBagInterface $params, AdminPanelAccountStore $store, AdminBackupScheduleStore $scheduleStore, RegistrationBlockStore $registrationBlockStore): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($response = $this->handleLocaleSwitch($request, '/admin/system-health')) {
            return $response;
        }

        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $db = $this->checkDatabase($em);
        $mqtt = $this->checkMqtt($params);
        $cron = $this->checkCron();
        $disk = $this->checkDisk('/');
        $ssl = $this->checkSsl();
        $backupSchedule = $scheduleStore->getSchedule();
        $backupNextRun = $scheduleStore->describeNextRun($backupSchedule, new \DateTimeImmutable('now'));
        $backupState = $this->summarizeBackupSchedule($backupSchedule, $backupNextRun, $tr);
        $registrationState = $registrationBlockStore->getState();
        $blockedUsers = $this->collectBlockedUsers($em);
        $resetLogRange = (string)$request->query->get('resetLogRange', 'all');
        if (!in_array($resetLogRange, ['all', '24h', '7d', '30d'], true)) {
            $resetLogRange = 'all';
        }
        $resetLogSince = match ($resetLogRange) {
            '24h' => time() - 86400,
            '7d' => time() - 7 * 86400,
            '30d' => time() - 30 * 86400,
            default => null,
        };
        $passwordResetRequests = $this->collectPasswordResetRequests($store, 15, $resetLogSince);

        $alerts = [];
        foreach (['db' => $db, 'mqtt' => $mqtt, 'cron' => $cron, 'disk' => $disk, 'ssl' => $ssl] as $name => $check) {
            if ($check['status'] !== 'ok') {
                $alerts[] = [
                    'level' => $check['status'] === 'error' ? 'bad' : 'warn',
                    'title' => $tr($name),
                    'message' => $check['summary'],
                ];
            }
        }
        if ($backupState['status'] !== 'ok') {
            $alerts[] = [
                'level' => $backupState['status'] === 'bad' ? 'bad' : 'warn',
                'title' => $tr('backup_schedule'),
                'message' => $backupState['summary'],
            ];
        }
        if (!empty($registrationState['blocked'])) {
            $alerts[] = [
                'level' => 'warn',
                'title' => $tr('registration_block'),
                'message' => $registrationState['message'],
            ];
        }
        foreach ($blockedUsers as $blockedUser) {
            $alerts[] = [
                'level' => 'warn',
                'title' => $tr('blocked_user'),
                'message' => $blockedUser['email'] . ' - ' . $blockedUser['summary'],
            ];
        }

        $overallStatus = 'ok';
        foreach ($alerts as $alert) {
            if (($alert['level'] ?? '') === 'bad') {
                $overallStatus = 'bad';
                break;
            }
            if (($alert['level'] ?? '') === 'warn') {
                $overallStatus = 'warn';
            }
        }

        $lastChecked = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $cards = [
            $this->renderHealthCard($tr('db'), $db, $escape),
            $this->renderHealthCard($tr('mqtt'), $mqtt, $escape),
            $this->renderHealthCard($tr('cron'), $cron, $escape),
            $this->renderHealthCard($tr('disk'), $disk, $escape),
            $this->renderHealthCard($tr('ssl'), $ssl, $escape),
            $this->renderHealthCard($tr('backup_schedule'), $backupState, $escape),
            $this->renderHealthCard($tr('alerts'), [
                'status' => empty($alerts) ? 'ok' : 'warn',
                'summary' => empty($alerts) ? $tr('no_alerts') : $tr('alerts_found') . ': ' . count($alerts),
                'details' => [
                    $tr('blocked_users') => (string)count($blockedUsers),
                    $tr('active_blocks') => (string)count($blockedUsers),
                ],
            ], $escape),
        ];

        $alertsHtml = '';
        foreach ($alerts as $alert) {
            $alertsHtml .= '<div class="alert ' . $escape((string)$alert['level']) . '"><b>' . $escape((string)$alert['title']) . ':</b> ' . $escape((string)$alert['message']) . '</div>';
        }
        if ($alertsHtml === '') {
            $alertsHtml = '<div class="alert ok">' . $escape($tr('no_alerts')) . '</div>';
        }

        $blockedRows = '';
        foreach ($blockedUsers as $item) {
            $blockedRows .= '<tr>'
                . '<td><a href="/admin/users/' . (int)$item['id'] . '">' . $escape((string)$item['email']) . '</a></td>'
                . '<td class="mono">' . $escape((string)$item['summary']) . '</td>'
                . '</tr>';
        }
        if ($blockedRows === '') {
            $blockedRows = '<tr><td colspan="2" style="color:#666;">' . $escape($tr('no_blocked_users')) . '</td></tr>';
        }

        $html = $this->adminUiLayoutOpen(
            $escape($tr('title')),
            'system-health',
            $this->isGranted('ROLE_ADMIN_SUPER'),
            '.health-hero{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,.9fr);gap:12px;align-items:stretch;margin-bottom:14px;}.health-panel{border:1px solid #dfe5ea;border-radius:18px;background:#fff;box-shadow:0 1px 1px rgba(16,24,40,.03);padding:14px 16px;}.health-state{display:flex;flex-direction:column;gap:10px;}.health-state-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}.health-state-title{font-size:13px;font-weight:700;color:#51606d;text-transform:uppercase;letter-spacing:.04em;}.health-state-value{font-size:24px;line-height:1.1;font-weight:900;letter-spacing:-0.03em;margin-top:3px;}.health-state-value.ok{color:#0b7a3a;}.health-state-value.warn{color:#8a5a00;}.health-state-value.bad{color:#b00020;}.health-state-desc{color:#44505c;font-size:13px;line-height:1.5;}.health-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:4px;}.health-kpi{padding:10px 12px;border-radius:14px;background:#f8fafb;border:1px solid #e3e9ee;}.health-kpi span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5b6570;font-weight:700;}.health-kpi b{display:block;font-size:18px;line-height:1.1;margin-top:4px;color:#18212a;}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:14px;}.summary{color:#202b33;font-size:13px;line-height:1.5;margin-bottom:10px;min-height:38px;}.summary-list{margin:0;padding-left:18px;color:#40505d;display:grid;gap:6px;}.summary-list li{line-height:1.45;}.alert{padding:12px 14px;border-radius:12px;margin:8px 0;font-size:13px;border:1px solid transparent;line-height:1.45;}.alert.ok{background:#e7f6ee;color:#0b7a3a;border-color:#bfe8cf;}.alert.warn{background:#fff4db;color:#8a5a00;border-color:#f0d18a;}.alert.bad{background:#fdecee;color:#b00020;border-color:#f2b8bf;}.section{margin-top:14px;}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;}.ui-page-tools{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px 0;flex-wrap:wrap;}.ui-page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}.ui-page-actions a{padding:6px 10px;border-radius:999px;background:#f6f8f9;border:1px solid #dfe5ea;text-decoration:none !important;}.health-sections{display:grid;grid-template-columns:1.1fr .9fr;gap:14px;align-items:start;}.health-table-wrap{overflow:auto;border:1px solid #e7edf2;border-radius:14px;}.health-table{margin:0;}.health-table th{top:0;background:#f7fafc;}.health-empty{padding:12px 14px;border-radius:12px;background:#f7fbf8;border:1px solid #d7eadf;color:#2b3a32;font-size:13px;}.health-log-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;}.health-filter{display:flex;gap:6px;flex-wrap:wrap;font-size:12px;}.health-filter a{padding:4px 8px;border-radius:999px;border:1px solid #d0d7de;background:#fff;color:#445;text-decoration:none;}.health-filter a.active{border-color:#0b7a3a;background:#e7f6ee;color:#0b7a3a;font-weight:700;}.health-badge-ok{background:#e7f6ee;color:#0b7a3a;}.health-badge-warn{background:#fff4db;color:#8a5a00;}.health-badge-bad{background:#fdecee;color:#b00020;}.health-badge-unknown{background:#eef2f5;color:#51606d;}@media (max-width: 1050px){.health-hero,.health-sections{grid-template-columns:1fr;}.health-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}}@media (max-width: 640px){.health-kpis{grid-template-columns:1fr;}.health-state-value{font-size:21px;}.health-panel{padding:12px 13px;}}'
        );
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted">' . $escape($tr('last_checked')) . ': <span class="mono">' . $escape($lastChecked) . '</span></div>'
            . '<div class="ui-page-actions"><a href="/admin/system-health?lang=pl" style="' . ($locale === 'pl' ? 'font-weight:700;' : '') . '">Polski</a><a href="/admin/system-health?lang=en" style="' . ($locale === 'en' ? 'font-weight:700;' : '') . '">English</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>'
            . '<h1>' . $escape($tr('title')) . '</h1>'
            . '<div class="health-hero">'
            . '<div class="health-panel health-state">'
            . '<div class="health-state-top">'
            . '<div><div class="health-state-title">' . $escape($tr('current_state')) . '</div><div class="health-state-value ' . $escape($overallStatus) . '">' . $escape($tr('overall_' . $overallStatus)) . '</div></div>'
            . '<span class="badge ' . $escape($overallStatus === 'bad' ? 'bad' : ($overallStatus === 'warn' ? 'warn' : 'ok')) . '">' . $escape(strtoupper($overallStatus)) . '</span>'
            . '</div>'
            . '<div class="health-state-desc">' . $escape($tr('health_intro')) . '</div>'
            . '<div class="health-kpis">'
            . '<div class="health-kpi"><span>' . $escape($tr('alerts_found_label')) . '</span><b>' . (string)count($alerts) . '</b></div>'
            . '<div class="health-kpi"><span>' . $escape($tr('blocked_users')) . '</span><b>' . (string)count($blockedUsers) . '</b></div>'
            . '<div class="health-kpi"><span>' . $escape($tr('password_reset_log_title')) . '</span><b>' . (string)count($passwordResetRequests) . '</b></div>'
            . '<div class="health-kpi"><span>' . $escape($tr('backup_schedule')) . '</span><b>' . $escape($backupState['status'] === 'ok' ? $tr('ok') : $tr('attention')) . '</b></div>'
            . '</div>'
            . '</div>'
            . '<div class="health-panel">'
            . '<h3>' . $escape($tr('quick_summary')) . '</h3>'
            . '<ul class="summary-list">'
            . '<li>' . $escape($tr('db')) . ': <span class="mono">' . $escape((string)($db['summary'] ?? '')) . '</span></li>'
            . '<li>' . $escape($tr('mqtt')) . ': <span class="mono">' . $escape((string)($mqtt['summary'] ?? '')) . '</span></li>'
            . '<li>' . $escape($tr('cron')) . ': <span class="mono">' . $escape((string)($cron['summary'] ?? '')) . '</span></li>'
            . '<li>' . $escape($tr('ssl')) . ': <span class="mono">' . $escape((string)($ssl['summary'] ?? '')) . '</span></li>'
            . '</ul>'
            . '</div>'
            . '</div>'
            . '<div class="grid">' . implode('', $cards) . '</div>'
            . '<div class="health-sections">'
            . '<div class="health-panel"><h3>' . $escape($tr('alerts_title')) . '</h3>' . $alertsHtml . '</div>'
            . '<div class="health-panel"><h3>' . $escape($tr('blocked_users_title')) . '</h3><div class="health-table-wrap"><table class="health-table"><thead><tr><th>' . $escape($tr('user')) . '</th><th>' . $escape($tr('details')) . '</th></tr></thead><tbody>' . $blockedRows . '</tbody></table></div></div>'
            . '</div>'
            . '<div class="health-panel section"><div class="health-log-head"><h3 style="margin:0;">' . $escape($tr('password_reset_log_title')) . '</h3><div class="health-filter">'
            . $this->renderPasswordResetLogFilterLink('/admin/system-health', $resetLogRange, 'all', $tr('password_reset_log_filter_all'), $escape)
            . $this->renderPasswordResetLogFilterLink('/admin/system-health', $resetLogRange, '24h', $tr('password_reset_log_filter_24h'), $escape)
            . $this->renderPasswordResetLogFilterLink('/admin/system-health', $resetLogRange, '7d', $tr('password_reset_log_filter_7d'), $escape)
            . $this->renderPasswordResetLogFilterLink('/admin/system-health', $resetLogRange, '30d', $tr('password_reset_log_filter_30d'), $escape)
            . '</div></div>' . $this->renderPasswordResetLog($passwordResetRequests, $escape, $tr) . '</div>'
            . $this->adminUiLayoutClose();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function renderHealthCard(string $title, array $check, callable $escape): string {
        $status = (string)($check['status'] ?? 'unknown');
        $summary = (string)($check['summary'] ?? '');
        $details = is_array($check['details'] ?? null) ? $check['details'] : [];
        $badgeClass = in_array($status, ['ok', 'warn', 'bad', 'unknown'], true) ? $status : 'unknown';
        $items = '';
        foreach ($details as $label => $value) {
            $items .= '<li><b>' . $escape((string)$label) . ':</b> ' . $escape((string)$value) . '</li>';
        }
        return '<div class="card health-card">'
            . '<h3 style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;"><span>' . $escape($title) . '</span><span class="badge health-badge-' . $escape($badgeClass) . '">' . $escape(strtoupper($status)) . '</span></h3>'
            . '<div class="summary">' . $escape($summary) . '</div>'
            . ($items !== '' ? '<ul>' . $items . '</ul>' : '')
            . '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function renderPasswordResetLog(array $entries, callable $escape, callable $tr): string {
        if (!$entries) {
            return '<div class="alert ok">' . $escape($tr('password_reset_log_empty')) . '</div>';
        }

        $rows = '';
        foreach ($entries as $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $userId = (int)($meta['userId'] ?? 0);
            $rows .= '<tr>'
                . '<td class="mono">' . $escape((string)($entry['ts'] ?? '')) . '</td>'
                . '<td>' . $escape((string)($meta['admin'] ?? '')) . '</td>'
                . '<td>' . $escape((string)($meta['email'] ?? '')) . '</td>'
                . '<td class="mono"><a href="/admin/users/' . $userId . '">' . $escape('#' . $userId) . '</a></td>'
                . '<td class="mono">' . $escape((string)($meta['ip'] ?? '')) . '</td>'
                . '</tr>';
        }

        return '<table><thead><tr><th>' . $escape($tr('time')) . '</th><th>' . $escape($tr('admin')) . '</th><th>' . $escape($tr('email')) . '</th><th>' . $escape($tr('user')) . '</th><th>' . $escape($tr('ip')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function renderPasswordResetLogFilterLink(string $basePath, string $current, string $value, string $label, callable $escape): string {
        $active = $current === $value;
        $href = $basePath . '?resetLogRange=' . rawurlencode($value);
        return '<a class="' . ($active ? 'active' : '') . '" href="' . $escape($href) . '">' . $escape($label) . '</a>';
    }

    /**
     * @param array<string, mixed> $backupSchedule
     * @return array<string, mixed>
     */
    private function summarizeBackupSchedule(array $backupSchedule, ?\DateTimeImmutable $nextRun, callable $tr): array {
        $enabled = (bool)($backupSchedule['enabled'] ?? false);
        $lastStatus = (string)($backupSchedule['lastStatus'] ?? '');
        $lastMessage = trim((string)($backupSchedule['lastMessage'] ?? ''));
        $summary = $enabled ? $tr('backup_schedule_ok') : $tr('backup_schedule_disabled');
        $status = $enabled ? 'ok' : 'warn';

        if (!$enabled) {
            $summary = $tr('backup_schedule_disabled');
            $status = 'warn';
        } elseif ($lastStatus === 'failed') {
            $summary = $lastMessage !== '' ? $lastMessage : $tr('backup_schedule_failed');
            $status = 'bad';
        } elseif ($lastStatus === 'ok') {
            $summary = $tr('backup_schedule_ok');
            $status = 'ok';
        } elseif ($lastMessage !== '') {
            $summary = $lastMessage;
            $status = 'warn';
        }

        return [
            'status' => $status,
            'summary' => $summary,
            'details' => [
                'Enabled' => $enabled ? 'yes' : 'no',
                'Mode' => (string)($backupSchedule['mode'] ?? 'daily'),
                'Time' => (string)($backupSchedule['time'] ?? '03:00'),
                'Next run' => $nextRun ? $nextRun->format(DATE_ATOM) : '-',
                'Last run' => (string)($backupSchedule['lastRunAt'] ?? '-'),
                'Last status' => $lastStatus !== '' ? $lastStatus : '-',
                'Last message' => $lastMessage !== '' ? $lastMessage : '-',
            ],
        ];
    }

    private function checkDatabase(EntityManagerInterface $em): array {
        try {
            $conn = $em->getConnection();
            $conn->fetchOne('SELECT 1');
            $dbVersion = (string)$conn->fetchOne('SELECT VERSION()');
            $latestMigration = (string)$conn->fetchOne("SELECT COALESCE(MAX(SUBSTRING(version, POSITION('Version' IN version) + 7)), '') FROM migration_versions");
            $migrationCount = (int)$conn->fetchOne('SELECT COUNT(*) FROM migration_versions');
            return [
                'status' => 'ok',
                'summary' => 'Connection OK',
                'details' => [
                    'MariaDB' => $dbVersion,
                    'Migrations' => $migrationCount,
                    'Latest migration' => $latestMigration !== '' ? $latestMigration : 'n/a',
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'bad',
                'summary' => 'DB error: ' . $e->getMessage(),
                'details' => [],
            ];
        }
    }

    private function checkMqtt(ParameterBagInterface $params): array {
        $enabled = (bool)$params->get('supla.mqtt_broker.enabled');
        $host = trim((string)$params->get('supla.mqtt_broker.host'));
        $protocol = strtolower(trim((string)$params->get('supla.mqtt_broker.protocol')));
        $port = (int)$params->get('supla.mqtt_broker.port');
        $tls = (bool)$params->get('supla.mqtt_broker.tls');

        if (!$enabled) {
            return [
                'status' => 'unknown',
                'summary' => 'MQTT broker is disabled in config.',
                'details' => [],
            ];
        }
        if ($host === '' || $port <= 0) {
            return [
                'status' => 'bad',
                'summary' => 'MQTT broker host/port are not configured.',
                'details' => [],
            ];
        }

        $scheme = $tls || $protocol === 'mqtts' ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($scheme . '://' . $host . ':' . $port, $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $context);
        if (is_resource($socket)) {
            fclose($socket);
            return [
                'status' => 'ok',
                'summary' => 'MQTT socket reachable.',
                'details' => [
                    'Host' => $host,
                    'Port' => $port,
                    'Protocol' => $scheme,
                ],
            ];
        }

        return [
            'status' => 'bad',
            'summary' => 'MQTT connect failed: ' . $errstr . ' (' . $errno . ')',
            'details' => [
                'Host' => $host,
                'Port' => $port,
                'Protocol' => $scheme,
            ],
        ];
    }

    private function checkCron(): array {
        $output = $this->runCommand('pgrep -x cron');
        $pid = trim($output);
        if ($pid !== '') {
            return [
                'status' => 'ok',
                'summary' => 'Cron daemon is running.',
                'details' => [
                    'PID' => $pid,
                ],
            ];
        }
        return [
            'status' => 'bad',
            'summary' => 'Cron daemon was not found.',
            'details' => [],
        ];
    }

    private function checkDisk(string $path): array {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return [
                'status' => 'unknown',
                'summary' => 'Disk usage is unavailable for ' . $path,
                'details' => [],
            ];
        }
        $used = $total - $free;
        $usedPercent = (int)round(($used / $total) * 100);
        $status = $usedPercent >= 90 ? 'bad' : ($usedPercent >= 80 ? 'warn' : 'ok');
        return [
            'status' => $status,
            'summary' => sprintf('Used %d%% of %s.', $usedPercent, $path),
            'details' => [
                'Total' => $this->formatBytes($total),
                'Used' => $this->formatBytes($used),
                'Free' => $this->formatBytes($free),
            ],
        ];
    }

    private function checkSsl(): array {
        $certPath = self::CERT_PATH;
        $keyPath = self::KEY_PATH;
        if (!is_file($certPath) || !is_readable($certPath)) {
            return [
                'status' => 'bad',
                'summary' => 'SSL certificate file is missing.',
                'details' => [
                    'Cert' => $certPath,
                    'Key' => is_file($keyPath) ? 'present' : 'missing',
                ],
            ];
        }

        $contents = @file_get_contents($certPath);
        $parsed = is_string($contents) ? @openssl_x509_parse($contents) : false;
        if (!is_array($parsed)) {
            return [
                'status' => 'bad',
                'summary' => 'SSL certificate cannot be parsed.',
                'details' => [
                    'Cert' => $certPath,
                    'Key' => is_file($keyPath) ? 'present' : 'missing',
                ],
            ];
        }

        $validTo = (int)($parsed['validTo_time_t'] ?? 0);
        $daysLeft = $validTo > 0 ? (int)floor(($validTo - time()) / 86400) : null;
        $status = $daysLeft !== null && $daysLeft < 0 ? 'bad' : ($daysLeft !== null && $daysLeft < 30 ? 'warn' : 'ok');

        return [
            'status' => $status,
            'summary' => 'Certificate valid until ' . ($validTo > 0 ? date('Y-m-d H:i', $validTo) : 'unknown'),
            'details' => [
                'Subject' => (string)($parsed['subject']['CN'] ?? 'n/a'),
                'Cert' => $certPath,
                'Key' => is_file($keyPath) ? 'present' : 'missing',
                'Days left' => $daysLeft === null ? 'n/a' : (string)$daysLeft,
            ],
        ];
    }

    /**
     * @return array<int, array{id:int, email:string, summary:string}>
     */
    private function collectBlockedUsers(EntityManagerInterface $em): array {
        /** @var User[] $users */
        $users = $em->getRepository(User::class)->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();

        $blocked = [];
        foreach ($users as $user) {
            try {
                $summary = $this->getUserBlockSummary($user);
            } catch (\Throwable $e) {
                continue;
            }
            if (!(bool)($summary['active'] ?? false)) {
                continue;
            }
            $blocked[] = [
                'id' => (int)$user->getId(),
                'email' => (string)$user->getEmail(),
                'summary' => $this->formatBlockSummary($summary),
            ];
            if (count($blocked) >= 10) {
                break;
            }
        }

        return $blocked;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectPasswordResetRequests(AdminPanelAccountStore $store, int $limit, ?int $sinceTimestamp = null): array {
        $entries = [];
        foreach (array_reverse($store->getAuditEntries(max(1, $limit * 5))) as $entry) {
            if (($entry['event'] ?? '') !== 'admin_user_password_reset_requested') {
                continue;
            }
            if ($sinceTimestamp !== null) {
                $timestamp = strtotime((string)($entry['ts'] ?? '')) ?: 0;
                if ($timestamp < $sinceTimestamp) {
                    continue;
                }
            }
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }
        return $entries;
    }

    private function getUserBlockSummary(User $user): array {
        $profile = $this->getUserBlockProfile($user);
        $schedule = $this->getUserBlockSchedule($user);
        $temporaryActive = $profile['until'] > time() && count($profile['scopes']) > 0;
        $scheduleActive = $this->isScheduleBlockActiveNow($schedule);
        return [
            'active' => $temporaryActive || $scheduleActive,
            'until' => $temporaryActive ? $profile['until'] : ($scheduleActive ? $this->getScheduleActiveUntil($schedule) : 0),
            'scopes' => $temporaryActive ? $profile['scopes'] : ($scheduleActive ? $schedule['scopes'] : []),
            'reason' => $temporaryActive ? $profile['reason'] : ($scheduleActive ? $schedule['reason'] : ''),
            'source' => $temporaryActive ? 'temporary' : ($scheduleActive ? 'schedule' : null),
            'schedule' => $schedule,
        ];
    }

    private function getUserBlockProfile(User $user): array {
        $profile = $user->getPreference('admin.blockProfile', null);
        if (!is_array($profile)) {
            $legacyUntil = (int)($user->getPreference('admin.blockedUntil', 0) ?? 0);
            $profile = $legacyUntil > 0 ? [
                'until' => $legacyUntil,
                'scopes' => ['www', 'api', 'mqtt'],
                'reason' => '',
            ] : [];
        }
        return [
            'until' => max(0, (int)($profile['until'] ?? 0)),
            'scopes' => $this->normalizeBlockScopes($profile['scopes'] ?? []),
            'reason' => trim((string)($profile['reason'] ?? '')),
        ];
    }

    private function getUserBlockSchedule(User $user): array {
        $schedule = $user->getPreference('admin.blockSchedule', null);
        if (!is_array($schedule)) {
            return [];
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($schedule['days'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        $from = trim((string)($schedule['from'] ?? ''));
        $to = trim((string)($schedule['to'] ?? ''));
        if (!$days || !$this->isValidTimeString($from) || !$this->isValidTimeString($to) || $from === $to) {
            return [];
        }
        return [
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'scopes' => $this->normalizeBlockScopes($schedule['scopes'] ?? []),
            'reason' => trim((string)($schedule['reason'] ?? '')),
        ];
    }

    private function normalizeBlockScopes($rawScopes): array {
        if (!is_array($rawScopes)) {
            $rawScopes = [$rawScopes];
        }
        $scopes = [];
        foreach ($rawScopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (in_array($scope, ['www', 'api', 'mqtt'], true) && !in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }
        return $scopes;
    }

    private function isValidTimeString(string $value): bool {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private function isScheduleBlockActiveNow(array $schedule): bool {
        if (!$schedule) {
            return false;
        }
        $day = (int)date('N');
        if (!in_array($day, $schedule['days'], true)) {
            return false;
        }
        $minutes = ((int)date('H')) * 60 + (int)date('i');
        [$fromHour, $fromMinute] = array_map('intval', explode(':', $schedule['from']));
        [$toHour, $toMinute] = array_map('intval', explode(':', $schedule['to']));
        $fromMinutes = $fromHour * 60 + $fromMinute;
        $toMinutes = $toHour * 60 + $toMinute;
        if ($fromMinutes < $toMinutes) {
            return $minutes >= $fromMinutes && $minutes < $toMinutes;
        }
        return $minutes >= $fromMinutes || $minutes < $toMinutes;
    }

    private function getScheduleActiveUntil(array $schedule): int {
        if (!$schedule || !$this->isScheduleBlockActiveNow($schedule)) {
            return 0;
        }
        [$toHour, $toMinute] = array_map('intval', explode(':', $schedule['to']));
        $until = (new \DateTimeImmutable('now'))->setTime($toHour, $toMinute);
        [$fromHour, $fromMinute] = array_map('intval', explode(':', $schedule['from']));
        $fromMinutes = $fromHour * 60 + $fromMinute;
        $toMinutes = $toHour * 60 + $toMinute;
        if ($fromMinutes >= $toMinutes) {
            $minutes = ((int)date('H')) * 60 + (int)date('i');
            if ($minutes >= $fromMinutes) {
                $until = $until->modify('+1 day');
            }
        }
        return $until->getTimestamp();
    }

    private function formatBlockSummary(array $summary): string {
        $until = (int)($summary['until'] ?? 0);
        $parts = [];
        if ($until > 0) {
            $parts[] = 'until ' . date('Y-m-d H:i', $until);
        }
        $scopes = $this->formatBlockScopes((array)($summary['scopes'] ?? []));
        if ($scopes !== '') {
            $parts[] = $scopes;
        }
        $reason = trim((string)($summary['reason'] ?? ''));
        if ($reason !== '') {
            $parts[] = $reason;
        }
        return implode(' · ', $parts);
    }

    private function formatBlockScopes(array $scopes): string {
        $values = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (in_array($scope, ['www', 'api', 'mqtt'], true)) {
                $values[] = strtoupper($scope);
            }
        }
        return implode(', ', array_unique($values));
    }

    private function formatBytes(float|int $bytes): string {
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $i === 0 ? 0 : 1, '.', ' ') . ' ' . $units[$i];
    }

    private function runCommand(string $command): string {
        if (!function_exists('shell_exec')) {
            return '';
        }
        $output = @shell_exec($command . ' 2>/dev/null');
        return is_string($output) ? $output : '';
    }

    private function requireAllowedAdminUser(): ?Response {
        $user = $this->getUser();
        if (!$user) {
            return new RedirectResponse('/admin/login');
        }
        if (!$user instanceof AdminPanelUser) {
            return new RedirectResponse('/admin/login');
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
                'title' => 'SUPLA Admin - Stan systemu',
                'system_health' => 'Stan systemu',
                'dashboard' => 'Dashboard',
                'users' => 'Użytkownicy',
                'account' => 'Konto',
                'security_log' => 'Log bezpieczeństwa',
                'logout' => 'Wyloguj',
                'last_checked' => 'Ostatnie sprawdzenie',
                'backup_restore' => 'Backup / Restore',
                'db' => 'Baza danych',
                'mqtt' => 'MQTT',
                'cron' => 'Cron',
                'disk' => 'Dysk',
                'ssl' => 'SSL',
                'alerts' => 'Alerty',
                'alerts_title' => 'Alerty administratora',
                'alerts_found' => 'Znalezione alerty',
                'current_state' => 'Stan bieżący',
                'overall_ok' => 'Wszystko działa poprawnie',
                'overall_warn' => 'Wymaga uwagi',
                'overall_bad' => 'Występuje awaria',
                'health_intro' => 'Najpierw sprawdzasz ogólny stan, potem szczegóły usług. Problemy są zebrane w jednym miejscu, żeby nie skanować całej strony.',
                'quick_summary' => 'Skrót',
                'alerts_found_label' => 'Alerty',
                'ok' => 'OK',
                'attention' => 'Uwaga',
                'registration_block' => 'Blokada rejestracji',
                'registration_blocked_summary' => 'Rejestracja nowych kont użytkowników jest zablokowana.',
                'registration_allowed_summary' => 'Rejestracja nowych kont użytkowników jest dozwolona.',
                'registration_allow' => 'Odblokuj rejestrację',
                'registration_changed_at' => 'Zmieniono',
                'registration_changed_by' => 'Zmienił',
                'registration_message' => 'Komunikat',
                'yes' => 'Tak',
                'no' => 'Nie',
                'blocked_users' => 'Zablokowani użytkownicy',
                'blocked_users_title' => 'Aktywne blokady',
                'blocked_user' => 'Blokada użytkownika',
                'password_reset_log_title' => 'Wysłane linki resetu hasła',
                'password_reset_log_empty' => 'Nie wysłano jeszcze żadnego linku resetu hasła.',
                'backup_schedule' => 'Harmonogram backupu',
                'backup_schedule_disabled' => 'Automatyczne backupy są wyłączone.',
                'backup_schedule_ok' => 'Backupy są zaplanowane i monitorowane.',
                'backup_schedule_failed' => 'Ostatni backup automatyczny zakończył się błędem.',
                'backup_schedule_last_run' => 'Ostatnie uruchomienie',
                'backup_schedule_last_status' => 'Ostatni status',
                'backup_schedule_last_message' => 'Ostatnia wiadomość',
                'backup_schedule_next_run' => 'Następne uruchomienie',
                'password_reset_log_filter_all' => 'Wszystkie',
                'password_reset_log_filter_24h' => '24h',
                'password_reset_log_filter_7d' => '7 dni',
                'password_reset_log_filter_30d' => '30 dni',
                'time' => 'Czas',
                'admin' => 'Admin',
                'email' => 'E-mail',
                'ip' => 'IP',
                'user' => 'Użytkownik',
                'details' => 'Szczegóły',
                'no_alerts' => 'Brak aktywnych alertów.',
                'no_blocked_users' => 'Brak aktywnych blokad.',
            ],
            'en' => [
                'title' => 'SUPLA Admin - System health',
                'system_health' => 'System health',
                'dashboard' => 'Dashboard',
                'users' => 'Users',
                'account' => 'Account',
                'security_log' => 'Security log',
                'logout' => 'Logout',
                'last_checked' => 'Last checked',
                'backup_restore' => 'Backup / Restore',
                'db' => 'Database',
                'mqtt' => 'MQTT',
                'cron' => 'Cron',
                'disk' => 'Disk',
                'ssl' => 'SSL',
                'alerts' => 'Alerts',
                'alerts_title' => 'Admin alerts',
                'alerts_found' => 'Alerts found',
                'current_state' => 'Current state',
                'overall_ok' => 'Everything is OK',
                'overall_warn' => 'Needs attention',
                'overall_bad' => 'There is an incident',
                'health_intro' => 'Check the overall status first, then drill into individual services. Problems are grouped in one place so you do not need to scan the whole page.',
                'quick_summary' => 'Quick summary',
                'alerts_found_label' => 'Alerts',
                'ok' => 'OK',
                'attention' => 'Attention',
                'registration_block' => 'Registration block',
                'registration_blocked_summary' => 'New user registrations are currently blocked.',
                'registration_allowed_summary' => 'New user registrations are currently allowed.',
                'registration_allow' => 'Allow registration',
                'registration_changed_at' => 'Changed at',
                'registration_changed_by' => 'Changed by',
                'registration_message' => 'Message',
                'yes' => 'Yes',
                'no' => 'No',
                'blocked_users' => 'Blocked users',
                'blocked_users_title' => 'Active blocks',
                'blocked_user' => 'User block',
                'password_reset_log_title' => 'Password reset links',
                'password_reset_log_empty' => 'No password reset links have been sent yet.',
                'backup_schedule' => 'Backup schedule',
                'backup_schedule_disabled' => 'Automatic backups are disabled.',
                'backup_schedule_ok' => 'Backups are scheduled and monitored.',
                'backup_schedule_failed' => 'The last automatic backup failed.',
                'backup_schedule_last_run' => 'Last run',
                'backup_schedule_last_status' => 'Last status',
                'backup_schedule_last_message' => 'Last message',
                'backup_schedule_next_run' => 'Next run',
                'password_reset_log_filter_all' => 'All',
                'password_reset_log_filter_24h' => '24h',
                'password_reset_log_filter_7d' => '7 days',
                'password_reset_log_filter_30d' => '30 days',
                'time' => 'Time',
                'admin' => 'Admin',
                'email' => 'E-mail',
                'ip' => 'IP',
                'user' => 'User',
                'details' => 'Details',
                'no_alerts' => 'No active alerts.',
                'no_blocked_users' => 'No active blocks.',
            ],
        ];
        $lang = isset($dict[$locale]) ? $locale : 'pl';
        return static fn(string $key): string => $dict[$lang][$key] ?? $key;
    }
}
