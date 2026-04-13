<?php

namespace SuplaBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\Model\AdminScheduledBackupManager;
use SuplaBundle\Security\AdminBackupScheduleStore;
use SuplaBundle\Security\AdminPanelAccountStore;
use SuplaBundle\Security\AdminPanelUser;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminBackupSchedulerController extends Controller {
    use AdminUiTrait;

    private const LOCALE_COOKIE = 'supla_admin_locale';

    /**
     * @Route("/admin/backup/scheduler", name="admin_backup_scheduler", methods={"GET"})
     */
    public function schedulerAction(Request $request, AdminBackupScheduleStore $scheduleStore, AdminPanelAccountStore $auditStore): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($response = $this->handleLocaleSwitch($request, '/admin/backup/scheduler')) {
            return $response;
        }

        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $schedule = $scheduleStore->getSchedule();
        $nextRun = $scheduleStore->describeNextRun($schedule, new \DateTimeImmutable('now'));
        $history = $this->getScheduleHistoryRows($auditStore);

        $days = [
            1 => $tr('mon'),
            2 => $tr('tue'),
            3 => $tr('wed'),
            4 => $tr('thu'),
            5 => $tr('fri'),
            6 => $tr('sat'),
            7 => $tr('sun'),
        ];
        $selectedDays = (array)($schedule['days'] ?? [1, 2, 3, 4, 5]);
        $html = $this->adminUiLayoutOpen($escape($tr('title')), 'scheduler', true, '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-bottom:14px;}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}.days{display:flex;gap:10px;flex-wrap:wrap;}.days label{margin:0;font-size:13px;}.ui-page-tools{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px 0;flex-wrap:wrap;}.ui-page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}.ui-page-actions a{padding:6px 10px;border-radius:999px;background:#f6f8f9;border:1px solid #dfe5ea;text-decoration:none !important;}');
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted">' . $escape($tr('title')) . '</div>'
            . '<div class="ui-page-actions"><a href="/admin/backup/scheduler?lang=pl" style="' . ($locale === 'pl' ? 'font-weight:700;' : '') . '">Polski</a><a href="/admin/backup/scheduler?lang=en" style="' . ($locale === 'en' ? 'font-weight:700;' : '') . '">English</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>'
            . '<h1>' . $escape($tr('title')) . '</h1>';
        $html .= '<div class="sub">' . $escape($tr('subtitle')) . '</div>';
        if (($msg = (string)$request->query->get('msg', '')) !== '') {
            $html .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if (($err = (string)$request->query->get('err', '')) !== '') {
            $html .= '<div class="notice bad">' . $escape($err) . '</div>';
        }

        $html .= '<div class="grid">'
            . '<div class="card"><h3>' . $escape($tr('current_state')) . '</h3>'
            . '<div><b>' . $escape($tr('enabled')) . ':</b> <span class="badge ' . (!empty($schedule['enabled']) ? 'ok' : 'warn') . '">' . $escape(!empty($schedule['enabled']) ? $tr('yes') : $tr('no')) . '</span></div>'
            . '<div class="hint" style="margin-top:10px;">'
            . '<div><b>' . $escape($tr('mode')) . ':</b> ' . $escape($tr((string)($schedule['mode'] ?? 'daily'))) . '</div>'
            . '<div><b>' . $escape($tr('time')) . ':</b> <span class="mono">' . $escape((string)($schedule['time'] ?? '03:00')) . '</span></div>'
            . '<div><b>' . $escape($tr('retention')) . ':</b> <span class="mono">' . $escape((string)($schedule['retention'] ?? 7)) . '</span></div>'
            . '<div><b>' . $escape($tr('prefix')) . ':</b> <span class="mono">' . $escape((string)($schedule['prefix'] ?? 'supla-auto-backup')) . '</span></div>'
            . '<div><b>' . $escape($tr('next_run')) . ':</b> <span class="mono">' . $escape($nextRun ? $nextRun->format(DATE_ATOM) : '-') . '</span></div>'
            . '<div><b>' . $escape($tr('last_run')) . ':</b> <span class="mono">' . $escape((string)($schedule['lastRunAt'] ?? '-')) . '</span></div>'
            . '<div><b>' . $escape($tr('last_status')) . ':</b> <span class="mono">' . $escape((string)($schedule['lastStatus'] ?? '-')) . '</span></div>'
            . '</div></div>'
            . '<div class="card"><h3>' . $escape($tr('save_schedule')) . '</h3>'
            . '<form method="post" action="/admin/backup/scheduler/save">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_backup_schedule_save')) . '" />'
            . '<label><input type="checkbox" name="enabled" value="1"' . (!empty($schedule['enabled']) ? ' checked' : '') . ' /> ' . $escape($tr('enabled')) . '</label>'
            . '<label>' . $escape($tr('mode')) . '</label><select name="mode" class="mode-select">'
            . '<option value="daily"' . (($schedule['mode'] ?? 'daily') === 'daily' ? ' selected' : '') . '>' . $escape($tr('daily')) . '</option>'
            . '<option value="weekly"' . (($schedule['mode'] ?? 'daily') === 'weekly' ? ' selected' : '') . '>' . $escape($tr('weekly')) . '</option>'
            . '</select>'
            . '<div class="row">'
            . '<div><label>' . $escape($tr('time')) . '</label><input type="time" name="time" value="' . $escape((string)($schedule['time'] ?? '03:00')) . '" /></div>'
            . '<div><label>' . $escape($tr('retention')) . '</label><input type="number" name="retention" min="1" max="90" value="' . $escape((string)($schedule['retention'] ?? 7)) . '" /></div>'
            . '</div>'
            . '<label>' . $escape($tr('prefix')) . '</label><input type="text" name="prefix" value="' . $escape((string)($schedule['prefix'] ?? 'supla-auto-backup')) . '" />'
            . '<div class="hint" style="margin-top:10px;">' . $escape($tr('weekly_days')) . '</div>'
            . '<div class="days">';
        foreach ($days as $dayNumber => $label) {
            $checked = in_array($dayNumber, $selectedDays, true) ? ' checked' : '';
            $html .= '<label><input type="checkbox" name="days[]" value="' . $dayNumber . '"' . $checked . ' /> ' . $escape($label) . '</label>';
        }
        $html .= '</div>'
            . '<button type="submit">' . $escape($tr('save_button')) . '</button>'
            . '</form>'
            . '<form method="post" action="/admin/backup/scheduler/run-now" style="margin-top:10px;">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_backup_schedule_run_now')) . '" />'
            . '<button type="submit" class="gray">' . $escape($tr('run_now')) . '</button>'
            . '</form>'
            . '<div class="hint" style="margin-top:10px;">' . $escape($tr('cron_help')) . '</div>'
            . '</div>'
            . '</div>';

        $historyRows = '';
        foreach ($history as $row) {
            $historyRows .= '<tr><td class="mono">' . $escape((string)$row['ts']) . '</td><td>' . $escape((string)$row['event']) . '</td><td>' . $escape((string)$row['details']) . '</td></tr>';
        }
        if ($historyRows === '') {
            $historyRows = '<tr><td colspan="3" style="color:#666;">' . $escape($tr('no_history')) . '</td></tr>';
        }
        $html .= '<div class="card"><h3>' . $escape($tr('history_title')) . '</h3><table><thead><tr><th>' . $escape($tr('when')) . '</th><th>' . $escape($tr('event')) . '</th><th>' . $escape($tr('details')) . '</th></tr></thead><tbody>' . $historyRows . '</tbody></table></div>';
        $html .= $this->adminUiLayoutClose();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/backup/scheduler/save", name="admin_backup_scheduler_save", methods={"POST"})
     */
    public function saveAction(Request $request, AdminBackupScheduleStore $scheduleStore): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if (!$this->isValidCsrf($request, 'admin_backup_schedule_save')) {
            return new RedirectResponse('/admin/backup/scheduler?err=' . rawurlencode('Invalid CSRF token.'));
        }

        try {
            $schedule = $this->normalizeScheduleRequest($request);
            $scheduleStore->saveSchedule($schedule);
        } catch (\Throwable $e) {
            return new RedirectResponse('/admin/backup/scheduler?err=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse('/admin/backup/scheduler?msg=' . rawurlencode('Backup schedule saved.'));
    }

    /**
     * @Route("/admin/backup/scheduler/run-now", name="admin_backup_scheduler_run_now", methods={"POST"})
     */
    public function runNowAction(Request $request, AdminBackupScheduleStore $scheduleStore, AdminScheduledBackupManager $manager): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if (!$this->isValidCsrf($request, 'admin_backup_schedule_run_now')) {
            return new RedirectResponse('/admin/backup/scheduler?err=' . rawurlencode('Invalid CSRF token.'));
        }

        $schedule = $scheduleStore->getSchedule();
        $result = $manager->runImmediateBackup($schedule, $this->currentAdminUsername() ?: 'admin');
        if (($result['status'] ?? 'failed') !== 'ok') {
            return new RedirectResponse('/admin/backup/scheduler?err=' . rawurlencode((string)($result['message'] ?? 'Backup failed.')));
        }

        return new RedirectResponse('/admin/backup/scheduler?msg=' . rawurlencode((string)($result['message'] ?? 'Backup created.')));
    }

    /**
     * @return array<int, array{ts:string,event:string,details:string}>
     */
    private function getScheduleHistoryRows(AdminPanelAccountStore $store): array {
        $rows = [];
        foreach (array_reverse($store->getAuditEntries(100)) as $entry) {
            $event = (string)($entry['event'] ?? '');
            if (!str_starts_with($event, 'admin_backup_')) {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $rows[] = [
                'ts' => (string)($entry['ts'] ?? ''),
                'event' => $this->formatHistoryEvent($event),
                'details' => $this->formatHistoryDetails($event, $meta),
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
    private function formatHistoryDetails(string $event, array $meta): string {
        return match ($event) {
            'admin_backup_downloaded', 'admin_backup_restored', 'admin_backup_scheduled_run', 'admin_backup_scheduled_run_now' => trim((string)($meta['file'] ?? '-')) . (($meta['size'] ?? null) !== null ? ' · ' . (string)$meta['size'] . ' B' : ''),
            'admin_backup_users_exported' => 'users=' . (int)($meta['users'] ?? 0),
            'admin_backup_users_imported' => 'users=' . (int)($meta['users'] ?? 0) . ', loc=' . (int)($meta['locations'] ?? 0) . ', aids=' . (int)($meta['accessIds'] ?? 0),
            'admin_backup_scheduled_failed' => (string)($meta['message'] ?? ''),
            default => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        };
    }

    private function formatHistoryEvent(string $event): string {
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

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    private function normalizeScheduleRequest(Request $request): array {
        $mode = (string)$request->request->get('mode', 'daily');
        if (!in_array($mode, ['daily', 'weekly'], true)) {
            throw new \RuntimeException('Unsupported schedule mode.');
        }
        $time = trim((string)$request->request->get('time', '03:00'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new \RuntimeException('Invalid backup time.');
        }
        $daysInput = (array)($request->request->get('days', []));
        $days = array_values(array_unique(array_filter(array_map('intval', $daysInput), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        if ($mode === 'weekly' && !$days) {
            throw new \RuntimeException('Please choose at least one weekday.');
        }
        $retention = (int)$request->request->get('retention', 7);
        $prefix = trim((string)$request->request->get('prefix', 'supla-auto-backup'));
        if ($prefix === '') {
            $prefix = 'supla-auto-backup';
        }
        return [
            'enabled' => $request->request->has('enabled'),
            'mode' => $mode,
            'time' => $time,
            'days' => $days ?: [1, 2, 3, 4, 5],
            'retention' => $retention,
            'prefix' => $prefix,
        ];
    }

    private function currentAdminUsername(): string {
        $user = $this->getUser();
        return $user instanceof AdminPanelUser ? (string)$user->getUsername() : '';
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

    private function isValidCsrf(Request $request, string $tokenId): bool {
        $token = (string)$request->request->get('_token', '');
        return $token !== '' && $this->get('security.csrf.token_manager')->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken($tokenId, $token));
    }

    private function csrfToken(string $tokenId): string {
        return $this->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
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

    /**
     * @return callable(string): string
     */
    private function translator(string $locale): callable {
        $dict = [
            'pl' => [
                'title' => 'SUPLA Admin - Harmonogram backupów',
                'subtitle' => 'Automatyczne kopie bazy uruchamiane przez cron. Możesz ustawić dni, godzinę i retencję starych plików.',
                'dashboard' => 'Dashboard',
                'users' => 'Użytkownicy',
                'account' => 'Konto',
                'security_log' => 'Log bezpieczeństwa',
                'system_health' => 'Stan systemu',
                'backup_restore' => 'Backup / Restore',
                'backup_schedule' => 'Harmonogram backupów',
                'logout' => 'Wyloguj',
                'current_state' => 'Stan aktualny',
                'enabled' => 'Włączone',
                'mode' => 'Tryb',
                'time' => 'Godzina',
                'retention' => 'Retencja',
                'prefix' => 'Prefiks pliku',
                'next_run' => 'Następne uruchomienie',
                'last_run' => 'Ostatnie uruchomienie',
                'last_status' => 'Status ostatniego uruchomienia',
                'save_schedule' => 'Zapisz harmonogram',
                'run_now' => 'Uruchom teraz',
                'cron_help' => 'Cron uruchamia komendę co minutę, a system wykona backup tylko wtedy, gdy harmonogram jest aktywny i osiągnięto ustawioną godzinę.',
                'daily' => 'Codziennie',
                'weekly' => 'Tygodniowo',
                'weekly_days' => 'Dni tygodnia dla trybu tygodniowego',
                'mon' => 'Pon',
                'tue' => 'Wt',
                'wed' => 'Śr',
                'thu' => 'Czw',
                'fri' => 'Pt',
                'sat' => 'Sob',
                'sun' => 'Ndz',
                'history_title' => 'Historia uruchomień',
                'no_history' => 'Brak wpisów historii backupów.',
                'when' => 'Kiedy',
                'event' => 'Zdarzenie',
                'details' => 'Szczegóły',
                'yes' => 'Tak',
                'no' => 'Nie',
            ],
            'en' => [
                'title' => 'SUPLA Admin - Backup schedule',
                'subtitle' => 'Automatic database backups triggered by cron. You can set days, time and retention for old files.',
                'dashboard' => 'Dashboard',
                'users' => 'Users',
                'account' => 'Account',
                'security_log' => 'Security log',
                'system_health' => 'System health',
                'backup_restore' => 'Backup / Restore',
                'backup_schedule' => 'Backup schedule',
                'logout' => 'Logout',
                'current_state' => 'Current state',
                'enabled' => 'Enabled',
                'mode' => 'Mode',
                'time' => 'Time',
                'retention' => 'Retention',
                'prefix' => 'File prefix',
                'next_run' => 'Next run',
                'last_run' => 'Last run',
                'last_status' => 'Last run status',
                'save_schedule' => 'Save schedule',
                'run_now' => 'Run now',
                'cron_help' => 'Cron triggers the command every minute, and the system performs a backup only when the schedule is enabled and the configured time is reached.',
                'daily' => 'Daily',
                'weekly' => 'Weekly',
                'weekly_days' => 'Weekdays for weekly mode',
                'mon' => 'Mon',
                'tue' => 'Tue',
                'wed' => 'Wed',
                'thu' => 'Thu',
                'fri' => 'Fri',
                'sat' => 'Sat',
                'sun' => 'Sun',
                'history_title' => 'Run history',
                'no_history' => 'No backup history entries.',
                'when' => 'When',
                'event' => 'Event',
                'details' => 'Details',
                'yes' => 'Yes',
                'no' => 'No',
            ],
        ];
        return static function (string $key) use ($dict, $locale): string {
            return $dict[$locale][$key] ?? $dict['pl'][$key] ?? $key;
        };
    }
}
