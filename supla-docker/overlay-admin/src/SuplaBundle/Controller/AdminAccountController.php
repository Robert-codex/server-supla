<?php
namespace SuplaBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\EventListener\AdminPanelTwoFactorEnforcer;
use SuplaBundle\Security\AdminPanelAccountStore;
use SuplaBundle\Security\AdminPanelTotp;
use SuplaBundle\Security\AdminPanelUser;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class AdminAccountController extends Controller {
    use AdminUiTrait;

    private const ISSUER = 'SUPLA Admin';
    private const LOCALE_COOKIE = 'supla_admin_locale';

    /**
     * @Route("/admin/account", name="admin_account", methods={"GET"})
     */
    public function accountAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        if ($response = $this->handleLocaleSwitch($request, '/admin/account')) {
            return $response;
        }
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $currentAdmin = $this->currentAdminUsername();
        $account = $store->getAccount($currentAdmin);
        $twoFactorEnabled = $store->isTwoFactorEnabled($currentAdmin);
        $pending = (string)($account['twoFactorPendingSecret'] ?? '');

        $msg = (string)$request->query->get('msg', '');
        $err = (string)$request->query->get('err', '');

        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $twoFactorStatus = $twoFactorEnabled ? 'ON' : 'OFF';
        $html = $this->adminUiLayoutOpen($escape($tr('account_title')), 'account', false, 'body{overflow-x:hidden;}');
        $html .= '<script src="/dist/qrcode-local.js?v=edgefix-v30"></script>';
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted"><a href="/admin/users">← ' . $escape($tr('users')) . '</a>' . $this->adminLinks($locale) . '</div>'
            . '<div class="ui-page-actions"><a href="/admin/account?lang=pl" style="' . ($locale === 'pl' ? 'font-weight:700;' : '') . '">Polski</a><a href="/admin/account?lang=en" style="' . ($locale === 'en' ? 'font-weight:700;' : '') . '">English</a><a href="/admin/security-log">' . $escape($tr('security_log')) . '</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>';
        $html .= '<h1>' . $escape($tr('account_title')) . '</h1>';

        if ($msg !== '') {
            $html .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $html .= '<div class="notice bad">' . $escape($err) . '</div>';
        }

        $html .= '<div class="card"><b>' . $escape($tr('login')) . ':</b> ' . $escape((string)$account['username']) . '<br/><b>' . $escape($tr('email')) . ':</b> ' . $escape((string)($account['email'] ?? '')) . '<br/><b>2FA:</b> ' . $escape($twoFactorStatus) . '</div>';

        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;">' . $escape($tr('change_credentials')) . '</h3>'
            . '<form method="post" action="/admin/account/change-credentials">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_account_change_credentials')) . '" />'
            . '<label>' . $escape($tr('current_password')) . '</label><input type="password" name="currentPassword" autocomplete="current-password" required />'
            . '<label>' . $escape($tr('new_login_optional')) . '</label><input type="text" name="newUsername" value="" />'
            . '<label>' . $escape($tr('new_recovery_email_optional')) . '</label><input type="email" name="newEmail" value="' . $escape((string)($account['email'] ?? '')) . '" />'
            . '<label>' . $escape($tr('new_password_optional')) . '</label><input type="password" name="newPassword" autocomplete="new-password" />'
            . '<label>' . $escape($tr('repeat_new_password')) . '</label><input type="password" name="newPassword2" autocomplete="new-password" />'
            . '<button type="submit">' . $escape($tr('save')) . '</button>'
            . '</form>'
            . '<div style="margin-top:10px;color:#666;font-size:12px;line-height:1.4;">' . $escape($tr('password_help')) . '</div>'
            . '</div>';

        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;">2FA</h3>';
        if ($twoFactorEnabled) {
            $html .= '<div style="color:#666;font-size:12px;line-height:1.4;margin-bottom:10px;">' . $escape($tr('two_factor_login_status')) . ': <b>' . $escape($tr('enabled_upper')) . '</b></div>';
            $html .= '<form method="post" action="/admin/account/2fa/disable">'
                . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_account_2fa_disable')) . '" />'
                . '<label>' . $escape($tr('two_factor_code_or_recovery')) . '</label><input name="code" autocomplete="one-time-code" required />'
                . '<button type="submit" class="danger">' . $escape($tr('disable_2fa')) . '</button>'
                . '</form>';
        } else {
            if ($pending === '') {
                $html .= '<div style="color:#666;font-size:12px;line-height:1.4;margin-bottom:10px;">' . $escape($tr('two_factor_login_status')) . ': <b>' . $escape($tr('disabled_upper')) . '</b></div>';
                $html .= '<form method="post" action="/admin/account/2fa/begin">'
                    . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_account_2fa_begin')) . '" />'
                    . '<button type="submit">' . $escape($tr('enable_2fa_start')) . '</button>'
                    . '</form>';
            } else {
                $uri = AdminPanelTotp::buildOtpAuthUri(self::ISSUER, (string)$account['username'], $pending);
                $html .= '<div style="color:#666;font-size:12px;line-height:1.4;margin-bottom:10px;">' . $escape($tr('two_factor_login_status')) . ': <b>' . $escape($tr('setup_in_progress_upper')) . '</b> (' . $escape($tr('setup_not_enforced')) . ').</div>'
                    . '<form method="post" action="/admin/account/2fa/cancel" style="margin:0 0 10px 0;">'
                    . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_account_2fa_cancel')) . '" />'
                    . '<button type="submit" class="gray">' . $escape($tr('cancel_2fa_setup')) . '</button>'
                    . '</form>'
                    . '<div style="color:#666;font-size:12px;line-height:1.4;margin-bottom:10px;">' . $escape($tr('add_totp_app_hint')) . '</div>'
                    . '<div style="margin:10px 0;"><div id="admin-2fa-qr" style="width:220px;min-height:220px;padding:8px;border:1px solid #eee;border-radius:12px;background:#fff;"></div></div>'
                    . '<div><b>' . $escape($tr('manual_key')) . ':</b> <span style="font-family:ui-monospace,monospace;">' . $escape($pending) . '</span></div>'
                    . '<div style="margin-top:8px;"><b>otpauth:</b></div><pre>' . $escape($uri) . '</pre>'
                    . '<script>(function(){var uri=' . json_encode($uri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';if(window.SuplaLocalQr){window.SuplaLocalQr.renderImg(document.getElementById("admin-2fa-qr"),uri,220,' . json_encode($tr('two_factor_qr_alt'), JSON_UNESCAPED_UNICODE) . ');}})();</script>'
                    . '<form method="post" action="/admin/account/2fa/confirm">'
                    . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_account_2fa_confirm')) . '" />'
                    . '<label>' . $escape($tr('two_factor_code')) . '</label><input name="code" autocomplete="one-time-code" required />'
                    . '<button type="submit">' . $escape($tr('confirm_2fa')) . '</button>'
                    . '</form>';
            }
        }
        $html .= '</div>';

        $html .= $this->adminUiLayoutClose();
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/security-log", name="admin_security_log", methods={"GET"})
     */
    public function securityLogAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        if ($response = $this->handleLocaleSwitch($request, '/admin/security-log')) {
            return $response;
        }
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $audit = $store->getAuditTail(200);

        $html = $this->adminUiLayoutOpen($escape($tr('security_log_title')), 'security-log', false);
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted"><a href="/admin/users">← ' . $escape($tr('users')) . '</a> | <a href="/admin/account">' . $escape($tr('account')) . '</a>' . $this->adminLinks($locale) . '</div>'
            . '<div class="ui-page-actions"><a href="/admin/security-log?lang=pl" style="' . ($locale === 'pl' ? 'font-weight:700;' : '') . '">Polski</a><a href="/admin/security-log?lang=en" style="' . ($locale === 'en' ? 'font-weight:700;' : '') . '">English</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>';
        $html .= '<h1>' . $escape($tr('security_log_title')) . '</h1>';
        $html .= '<div class="card"><table><thead><tr><th>' . $escape($tr('entry')) . '</th></tr></thead><tbody>';
        if (!$audit) {
            $html .= '<tr><td style="color:#666;">' . $escape($tr('no_entries')) . '</td></tr>';
        } else {
            foreach ($audit as $line) {
                $html .= '<tr><td><span style="font-family:ui-monospace,monospace;">' . $escape($line) . '</span></td></tr>';
            }
        }
        $html .= '</tbody></table></div>';
        $html .= $this->adminUiLayoutClose();
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/admin-history", name="admin_admin_history", methods={"GET"})
     */
    public function adminHistoryAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_SUPER');
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $allowedEvents = [
            'admin_created',
            'admin_deleted',
            'admin_role_changed',
            'admin_active_changed',
            'admin_2fa_reset_by_superadmin',
            'admin_password_changed',
            'admin_username_changed',
        ];
        $eventFilter = trim((string)$request->query->get('event', ''));
        $adminFilter = trim((string)$request->query->get('admin', ''));
        $dateFromFilter = trim((string)$request->query->get('dateFrom', ''));
        $dateToFilter = trim((string)$request->query->get('dateTo', ''));
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 25;
        $export = strtolower(trim((string)$request->query->get('export', '')));
        $admins = array_map(static fn(array $admin): string => (string)($admin['username'] ?? ''), $store->getAdmins());
        $dateFrom = $this->parseDateFilter($dateFromFilter, false);
        $dateTo = $this->parseDateFilter($dateToFilter, true);
        $events = array_values(array_filter(array_reverse($store->getAuditEntries(400)), function (array $entry) use ($allowedEvents, $eventFilter, $adminFilter, $dateFrom, $dateTo): bool {
            $event = (string)($entry['event'] ?? '');
            if (!in_array($event, $allowedEvents, true)) {
                return false;
            }
            if ($eventFilter !== '' && !hash_equals($event, $eventFilter)) {
                return false;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $actor = (string)($meta['admin'] ?? '');
            if ($adminFilter !== '' && !hash_equals($actor, $adminFilter)) {
                return false;
            }
            $ts = (string)($entry['ts'] ?? '');
            if ($ts !== '') {
                try {
                    $dt = new \DateTimeImmutable($ts);
                    if ($dateFrom && $dt < $dateFrom) {
                        return false;
                    }
                    if ($dateTo && $dt > $dateTo) {
                        return false;
                    }
                } catch (\Throwable $e) {
                }
            }
            return true;
        }));

        $eventOptions = '<option value="">' . $escape($tr('all_events')) . '</option>';
        foreach ($allowedEvents as $eventName) {
            $eventOptions .= '<option value="' . $escape($eventName) . '"' . ($eventFilter === $eventName ? ' selected' : '') . '>' . $escape($this->adminHistoryEventLabel($eventName, $tr)) . '</option>';
        }
        $adminOptions = '<option value="">' . $escape($tr('all_admins')) . '</option>';
        foreach ($admins as $adminName) {
            if ($adminName === '') {
                continue;
            }
            $adminOptions .= '<option value="' . $escape($adminName) . '"' . ($adminFilter === $adminName ? ' selected' : '') . '>' . $escape($adminName) . '</option>';
        }

        if ($export === 'csv') {
            return $this->exportAdminHistoryCsv($events);
        }

        $totalEvents = count($events);
        $pagedEvents = array_slice($events, ($page - 1) * $perPage, $perPage);
        $rows = '';
        foreach ($pagedEvents as $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $eventName = (string)($entry['event'] ?? '');
            $rows .= '<tr>'
                . '<td class="mono">' . $escape((string)($entry['ts'] ?? '')) . '</td>'
                . '<td>' . $escape($this->adminHistoryEventLabel($eventName, $tr)) . '</td>'
                . '<td>' . $escape((string)($meta['admin'] ?? '-')) . '</td>'
                . '<td>' . $escape((string)($meta['target'] ?? $meta['createdUsername'] ?? $meta['newUsername'] ?? '-')) . '</td>'
                . '<td class="mono">' . $escape(json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" style="color:#666;">' . $escape($tr('no_admin_history')) . '</td></tr>';
        }

        $html = $this->adminUiLayoutOpen($escape($tr('admin_history_title')), 'admin-history', true);
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted"><a href="/admin/users">' . $escape($tr('users')) . '</a> | <a href="/admin/admins">' . $escape($tr('admins_menu')) . '</a> | <a href="/admin/backup">' . $escape($tr('backup_restore')) . '</a> | <a href="/admin/account">' . $escape($tr('account')) . '</a></div>'
            . '<div class="ui-page-actions"><a href="/admin/security-log">' . $escape($tr('security_log')) . '</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>';
        $html .= '<h1>' . $escape($tr('admin_history_title')) . '</h1>';
        $html .= '<div class="card"><form method="get" action="/admin/admin-history" class="filters">'
            . '<div><label>' . $escape($tr('event')) . '</label><select name="event">' . $eventOptions . '</select></div>'
            . '<div><label>' . $escape($tr('admin_actor')) . '</label><select name="admin">' . $adminOptions . '</select></div>'
            . '<div><label>' . $escape($tr('date_from')) . '</label><input type="date" name="dateFrom" value="' . $escape($dateFromFilter) . '" /></div>'
            . '<div><label>' . $escape($tr('date_to')) . '</label><input type="date" name="dateTo" value="' . $escape($dateToFilter) . '" /></div>'
            . '<div><button type="submit">' . $escape($tr('filter')) . '</button></div>'
            . '<div><a href="/admin/admin-history">' . $escape($tr('clear_filters')) . '</a></div>'
            . '<div><a href="/admin/admin-history?' . $escape(http_build_query(array_filter([
                'event' => $eventFilter,
                'admin' => $adminFilter,
                'dateFrom' => $dateFromFilter,
                'dateTo' => $dateToFilter,
                'export' => 'csv',
            ], static fn($value) => $value !== ''))) . '">' . $escape($tr('export_csv')) . '</a></div>'
            . '</form></div>';
        $html .= '<div class="card"><table><thead><tr><th>' . $escape($tr('when')) . '</th><th>' . $escape($tr('event')) . '</th><th>' . $escape($tr('admin_actor')) . '</th><th>' . $escape($tr('target')) . '</th><th>' . $escape($tr('details')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $html .= $this->renderHistoryPager($page, $perPage, $totalEvents, [
            'event' => $eventFilter,
            'admin' => $adminFilter,
            'dateFrom' => $dateFromFilter,
            'dateTo' => $dateToFilter,
        ], $tr, $escape);
        $html .= '</div>';
        $html .= $this->adminUiLayoutClose();
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/account/change-credentials", name="admin_account_change_credentials", methods={"POST"})
     */
    public function changeCredentialsAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $currentAdmin = $this->currentAdminUsername();
        if ($response = $this->rejectInvalidCsrf($request, 'admin_account_change_credentials')) {
            return $response;
        }
        $currentPassword = (string)$request->request->get('currentPassword', '');
        if (!$store->verifyPassword($currentPassword, $currentAdmin)) {
            $store->audit('admin_account_change_denied', ['admin' => $currentAdmin, 'reason' => 'bad_current_password', 'ip' => $request->getClientIp()]);
            return $this->redirectWith('err', $tr('err_current_password_invalid'));
        }

        $newUsername = trim((string)$request->request->get('newUsername', ''));
        $newEmail = trim((string)$request->request->get('newEmail', ''));
        $newPassword = (string)$request->request->get('newPassword', '');
        $newPassword2 = (string)$request->request->get('newPassword2', '');

        if ($newPassword !== '' || $newPassword2 !== '') {
            if (!hash_equals($newPassword, $newPassword2)) {
                return $this->redirectWith('err', $tr('err_passwords_mismatch'));
            }
            $err = $this->validatePassword($newPassword, $locale);
            if ($err !== null) {
                return $this->redirectWith('err', $err);
            }
            $store->setPassword($newPassword, $currentAdmin);
            $store->audit('admin_password_changed', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
        }

        if ($newUsername !== '') {
            $store->setUsername($newUsername, $currentAdmin);
            $store->audit('admin_username_changed', ['admin' => $currentAdmin, 'newUsername' => $newUsername, 'ip' => $request->getClientIp()]);
        }
        $account = $store->getAccount($newUsername !== '' ? $newUsername : $currentAdmin);
        if ($newEmail !== (string)($account['email'] ?? '')) {
            $store->setEmail($newEmail, $newUsername !== '' ? $newUsername : $currentAdmin);
            $store->audit('admin_email_changed', ['admin' => $newUsername !== '' ? $newUsername : $currentAdmin, 'email' => $newEmail, 'ip' => $request->getClientIp()]);
        }

        // Require re-2FA after changing credentials.
        AdminPanelTwoFactorEnforcer::clear($request->getSession());
        return $this->redirectWith('msg', $tr('msg_credentials_saved'));
    }

    /**
     * @Route("/admin/account/2fa/begin", name="admin_account_2fa_begin", methods={"POST"})
     */
    public function begin2faAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        $currentAdmin = $this->currentAdminUsername();
        if ($response = $this->rejectInvalidCsrf($request, 'admin_account_2fa_begin')) {
            return $response;
        }
        $store->beginTwoFactorSetup($currentAdmin);
        $store->audit('admin_2fa_setup_begin', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
        AdminPanelTwoFactorEnforcer::clear($request->getSession());
        return new RedirectResponse('/admin/account');
    }

    /**
     * @Route("/admin/account/2fa/cancel", name="admin_account_2fa_cancel", methods={"POST"})
     */
    public function cancel2faAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        $currentAdmin = $this->currentAdminUsername();
        if ($response = $this->rejectInvalidCsrf($request, 'admin_account_2fa_cancel')) {
            return $response;
        }
        $store->cancelTwoFactorSetup($currentAdmin);
        $store->audit('admin_2fa_setup_cancel', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
        AdminPanelTwoFactorEnforcer::clear($request->getSession());
        return new RedirectResponse('/admin/account');
    }

    /**
     * @Route("/admin/account/2fa/confirm", name="admin_account_2fa_confirm", methods={"POST"})
     */
    public function confirm2faAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $currentAdmin = $this->currentAdminUsername();
        if ($response = $this->rejectInvalidCsrf($request, 'admin_account_2fa_confirm')) {
            return $response;
        }
        $code = (string)$request->request->get('code', '');
        try {
            $result = $store->confirmTwoFactorSetup($code, $currentAdmin);
        } catch (\Throwable $e) {
            $store->audit('admin_2fa_setup_failed', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
            return $this->redirectWith('err', $tr('err_invalid_2fa_code'));
        }
        $store->audit('admin_2fa_enabled', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
        AdminPanelTwoFactorEnforcer::clear($request->getSession());
        $codes = $result['recoveryCodes'] ?? [];
        $html = '<!doctype html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>' . htmlspecialchars($tr('recovery_codes_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:18px;background:#f7f7f8;}'
            . '.card{background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:14px;}'
            . 'pre{background:#fafafa;border:1px solid #eee;border-radius:10px;padding:10px;overflow:auto;font-size:12px;}a{color:#0b7a3a;text-decoration:none;}a:hover{text-decoration:underline;}'
            . '</style></head><body><div class="card"><h1 style="margin:0 0 10px 0;font-size:18px;">' . htmlspecialchars($tr('two_factor_enabled_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<div style="color:#666;font-size:12px;line-height:1.4;margin-bottom:10px;">' . htmlspecialchars($tr('save_recovery_codes_hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
            . '<pre>' . htmlspecialchars(implode("\n", array_map('strval', $codes)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
            . '<a href="/admin/account">' . htmlspecialchars($tr('back'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div></body></html>';
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/account/2fa/disable", name="admin_account_2fa_disable", methods={"POST"})
     */
    public function disable2faAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $currentAdmin = $this->currentAdminUsername();
        if ($response = $this->rejectInvalidCsrf($request, 'admin_account_2fa_disable')) {
            return $response;
        }
        $code = (string)$request->request->get('code', '');
        if (!$store->verifyTwoFactorCodeOrRecovery($code, $currentAdmin)) {
            $store->audit('admin_2fa_disable_failed', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
            return $this->redirectWith('err', $tr('err_invalid_code'));
        }
        $store->disableTwoFactor($currentAdmin);
        $store->audit('admin_2fa_disabled', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
        AdminPanelTwoFactorEnforcer::clear($request->getSession());
        return $this->redirectWith('msg', $tr('msg_2fa_disabled'));
    }

    /**
     * @Route("/admin/2fa", name="admin_2fa", methods={"GET","POST"})
     */
    public function admin2faGateAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_PANEL');
        if ($response = $this->handleLocaleSwitch($request, '/admin/2fa')) {
            return $response;
        }
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $currentAdmin = $this->currentAdminUsername();
        if (!$store->isTwoFactorEnabled($currentAdmin)) {
            AdminPanelTwoFactorEnforcer::markPassed($request->getSession());
            return new RedirectResponse('/admin/dashboard');
        }

        $error = '';
        $remaining = $store->getLoginBlockRemainingSeconds($request->getClientIp());
        if ($remaining > 0) {
            $error = sprintf($tr('too_many_attempts'), $remaining);
        }
        if ($request->isMethod('POST')) {
            if ($remaining > 0) {
                return new Response($error, 429, ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => (string)$remaining]);
            }
            if (!$this->isCsrfTokenValid('admin_2fa_gate', (string)$request->request->get('_token', ''))) {
                $error = $tr('err_session_expired');
            } else {
                $code = (string)$request->request->get('code', '');
                if ($store->verifyTwoFactorCodeOrRecovery($code, $currentAdmin)) {
                    $store->clearFailedLoginAttempts($request->getClientIp());
                    AdminPanelTwoFactorEnforcer::markPassed($request->getSession());
                    $store->audit('admin_2fa_passed', ['admin' => $currentAdmin, 'ip' => $request->getClientIp()]);
                    return new RedirectResponse('/admin/dashboard');
                }
                $blockedUntil = $store->registerFailedLoginAttempt($request->getClientIp());
                $error = $tr('err_invalid_code');
                $store->audit('admin_2fa_failed', [
                    'admin' => $currentAdmin,
                    'ip' => $request->getClientIp(),
                    'blockedUntil' => $blockedUntil > 0 ? date(DATE_ATOM, $blockedUntil) : null,
                ]);
            }
        }

        $html = '<!doctype html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>' . htmlspecialchars($tr('two_factor_page_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f7f7f8;min-height:100vh;display:flex;align-items:center;justify-content:center;}'
            . '.card{width:min(420px,92vw);background:#fff;border:1px solid #e2e2e2;border-radius:14px;padding:18px 18px 16px 18px;box-shadow:0 6px 20px rgba(0,0,0,.06);}'
            . 'h1{font-size:18px;margin:0 0 12px 0;}'
            . 'label{display:block;font-size:12px;color:#444;margin:10px 0 6px 0;}'
            . 'input,button{font:inherit;padding:10px 12px;border:1px solid #ccc;border-radius:10px;width:100%;box-sizing:border-box;}'
            . 'button{margin-top:14px;background:#0b7a3a;border-color:#0b7a3a;color:#fff;cursor:pointer;}'
            . '.err{background:#fdecee;color:#b00020;border:1px solid #f2b8bf;padding:10px 12px;border-radius:10px;margin:10px 0 0 0;font-size:13px;}'
            . '.muted{margin-top:12px;color:#666;font-size:12px;line-height:1.4;}'
            . '</style></head><body><div class="card">'
            . '<div style="display:flex;justify-content:flex-end;gap:8px;font-size:12px;margin-bottom:8px;"><a href="/admin/2fa?lang=pl"' . ($locale === 'pl' ? ' style="font-weight:700;"' : '') . '>PL</a> | <a href="/admin/2fa?lang=en"' . ($locale === 'en' ? ' style="font-weight:700;"' : '') . '>EN</a></div>'
            . '<h1>' . htmlspecialchars($tr('two_factor_page_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<form method="post" action="/admin/2fa">'
            . '<input type="hidden" name="_token" value="' . htmlspecialchars($this->csrfToken('admin_2fa_gate'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />'
            . '<label>' . htmlspecialchars($tr('two_factor_code_or_recovery'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</label><input name="code" autocomplete="one-time-code" required />'
            . '<button type="submit">' . htmlspecialchars($tr('confirm'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</button>'
            . '</form>';
        if ($error !== '') {
            $html .= '<div class="err">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }
        $html .= '<div class="muted"><a href="/admin/logout">' . htmlspecialchars($tr('logout'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div></div></body></html>';
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/admins", name="admin_admins", methods={"GET"})
     */
    public function adminsAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_SUPER');
        if ($response = $this->handleLocaleSwitch($request, '/admin/admins')) {
            return $response;
        }
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $msg = (string)$request->query->get('msg', '');
        $err = (string)$request->query->get('err', '');
        $search = trim((string)$request->query->get('q', ''));
        $roleFilter = (string)$request->query->get('role', 'all');
        $statusFilter = (string)$request->query->get('status', 'all');
        $twoFactorFilter = (string)$request->query->get('twofa', 'all');
        $sort = (string)$request->query->get('sort', 'last_login_desc');
        $admins = $store->getAdmins();
        $currentAdmin = $this->currentAdminUsername();
        $totalAdmins = count($admins);
        $activeAdmins = count(array_filter($admins, static fn(array $admin): bool => (bool)($admin['active'] ?? true)));
        $superAdmins = count(array_filter($admins, static fn(array $admin): bool => (string)($admin['role'] ?? '') === 'superadmin'));
        $twoFactorAdmins = count(array_filter($admins, static fn(array $admin): bool => (bool)($admin['twoFactorEnabled'] ?? false)));
        $filteredAdmins = array_values(array_filter($admins, function (array $admin) use ($search, $roleFilter, $statusFilter, $twoFactorFilter): bool {
            $username = strtolower((string)($admin['username'] ?? ''));
            $email = strtolower((string)($admin['email'] ?? ''));
            $role = (string)($admin['role'] ?? 'superadmin');
            $active = (bool)($admin['active'] ?? true);
            $twoFactor = (bool)($admin['twoFactorEnabled'] ?? false);
            if ($search !== '') {
                $needle = strtolower($search);
                if (strpos($username, $needle) === false && strpos($email, $needle) === false) {
                    return false;
                }
            }
            if ($roleFilter !== 'all' && $role !== $roleFilter) {
                return false;
            }
            if ($statusFilter === 'active' && !$active) {
                return false;
            }
            if ($statusFilter === 'inactive' && $active) {
                return false;
            }
            if ($twoFactorFilter === 'enabled' && !$twoFactor) {
                return false;
            }
            if ($twoFactorFilter === 'disabled' && $twoFactor) {
                return false;
            }
            return true;
        }));
        $lastLoginByUsername = [];
        foreach ($filteredAdmins as $admin) {
            $username = (string)($admin['username'] ?? '');
            $lastLogin = $this->findLastSuccessfulLogin($store, $username);
            $lastLoginByUsername[$username] = $lastLogin !== '' ? (strtotime($lastLogin) ?: 0) : 0;
        }
        usort($filteredAdmins, function (array $left, array $right) use ($sort, $lastLoginByUsername): int {
            $leftUsername = (string)($left['username'] ?? '');
            $rightUsername = (string)($right['username'] ?? '');
            $leftRole = (string)($left['role'] ?? 'superadmin');
            $rightRole = (string)($right['role'] ?? 'superadmin');
            $leftActive = (bool)($left['active'] ?? true);
            $rightActive = (bool)($right['active'] ?? true);
            $leftTwoFactor = (bool)($left['twoFactorEnabled'] ?? false);
            $rightTwoFactor = (bool)($right['twoFactorEnabled'] ?? false);
            $leftLastLogin = (int)($lastLoginByUsername[$leftUsername] ?? 0);
            $rightLastLogin = (int)($lastLoginByUsername[$rightUsername] ?? 0);
            return match ($sort) {
                'last_login_asc' => [$leftLastLogin, strtolower($leftUsername)] <=> [$rightLastLogin, strtolower($rightUsername)],
                'username_desc' => [strtolower($rightUsername), $rightLastLogin] <=> [strtolower($leftUsername), $leftLastLogin],
                'role_asc' => [$leftRole, $leftLastLogin, strtolower($leftUsername)] <=> [$rightRole, $rightLastLogin, strtolower($rightUsername)],
                'role_desc' => [$rightRole, $rightLastLogin, strtolower($rightUsername)] <=> [$leftRole, $leftLastLogin, strtolower($leftUsername)],
                'active_first' => [!(int)$leftActive, $leftTwoFactor ? 0 : 1, $leftLastLogin, strtolower($leftUsername)] <=> [!(int)$rightActive, $rightTwoFactor ? 0 : 1, $rightLastLogin, strtolower($rightUsername)],
                default => [$rightLastLogin, strtolower($leftUsername)] <=> [$leftLastLogin, strtolower($rightUsername)],
            };
        });

        $notice = '';
        if ($msg !== '') {
            $notice .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $notice .= '<div class="notice bad">' . $escape($err) . '</div>';
        }

        $rows = '';
        foreach ($filteredAdmins as $admin) {
            $username = (string)($admin['username'] ?? '');
            $role = (string)($admin['role'] ?? 'superadmin');
            $active = (bool)($admin['active'] ?? true);
            $twoFactor = (bool)($admin['twoFactorEnabled'] ?? false);
            $lastLogin = $this->findLastSuccessfulLogin($store, $username);
            $sameAdmin = hash_equals($username, $currentAdmin);
            $disabledButton = $sameAdmin ? ' disabled' : '';
            $roleBadge = '<span class="admin-badge ' . $escape($role) . '">' . $escape($role) . '</span>';
            $activeBadge = '<span class="admin-badge ' . ($active ? 'active' : 'inactive') . '">' . ($active ? $escape($tr('yes')) : $escape($tr('no'))) . '</span>';
            $twoFactorBadge = '<span class="admin-badge ' . ($twoFactor ? 'twofa' : 'no2fa') . '">' . ($twoFactor ? $escape($tr('yes')) : $escape($tr('no'))) . '</span>';
            $toggleConfirm = $active ? sprintf($tr('deactivate_admin_confirm'), $username) : sprintf($tr('activate_admin_confirm'), $username);
            $reset2faConfirm = sprintf($tr('reset_2fa_confirm'), $username);
            $rows .= '<tr>'
                . '<td>' . $escape($username) . '</td>'
                . '<td>' . $roleBadge . '</td>'
                . '<td>' . $activeBadge . '</td>'
                . '<td>' . $twoFactorBadge . '</td>'
                . '<td class="mono">' . $escape($lastLogin !== '' ? $lastLogin : '—') . '</td>'
                . '<td class="admin-table-actions">'
                . '<form method="post" action="/admin/admins/' . rawurlencode($username) . '/role">'
                . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_manage_role_' . $username)) . '" />'
                . '<select name="role">'
                . '<option value="superadmin"' . ($role === 'superadmin' ? ' selected' : '') . '>superadmin</option>'
                . '<option value="operator"' . ($role === 'operator' ? ' selected' : '') . '>operator</option>'
                . '<option value="readonly"' . ($role === 'readonly' ? ' selected' : '') . '>readonly</option>'
                . '</select><button type="submit">' . $escape($tr('change_role')) . '</button></form>'
                . '<form method="post" action="/admin/admins/' . rawurlencode($username) . '/toggle-active">'
                . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_manage_toggle_' . $username)) . '" />'
                . '<button type="submit"' . $disabledButton . ' onclick="return confirm(' . json_encode($toggleConfirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');">' . ($active ? $escape($tr('deactivate')) : $escape($tr('activate'))) . '</button></form>'
                . '<form method="post" action="/admin/admins/' . rawurlencode($username) . '/reset-2fa">'
                . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_manage_reset_2fa_' . $username)) . '" />'
                . '<button type="submit"' . $disabledButton . ' onclick="return confirm(' . json_encode($reset2faConfirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');">' . $escape($tr('reset_2fa')) . '</button></form>'
                . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" style="color:#666;">' . $escape($tr('no_admins')) . '</td></tr>';
        }

        $html = $this->adminUiLayoutOpen($escape($tr('admins_title')), 'admins', true, '
            .admin-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:14px 0 8px 0;}
            .admin-summary-card{background:#fff;border:1px solid #e1e7ec;border-radius:16px;padding:14px;box-shadow:0 1px 1px rgba(16,24,40,.03);}
            .admin-summary-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5b6570;font-weight:700;margin-bottom:6px;}
            .admin-summary-value{font-size:26px;line-height:1;font-weight:800;letter-spacing:-0.03em;color:#18212a;margin-bottom:5px;}
            .admin-summary-desc{font-size:12px;color:#5b6570;line-height:1.35;}
            .admin-summary-card.ok{border-color:#bfe8cf;background:linear-gradient(180deg,#fff 0,#f3fbf6 100%);}
            .admin-summary-card.warn{border-color:#f2d6a5;background:linear-gradient(180deg,#fff 0,#fff9ee 100%);}
            .admin-summary-card.info{border-color:#cfe0ff;background:linear-gradient(180deg,#fff 0,#f7f9ff 100%);}
            .admin-summary-card.neutral{border-color:#dde3e8;background:linear-gradient(180deg,#fff 0,#fbfcfd 100%);}
            .admin-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700;line-height:1;white-space:nowrap;}
            .admin-badge::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.7;}
            .admin-badge.superadmin{background:#e7f6ee;color:#0b7a3a;}
            .admin-badge.operator{background:#f0f5ff;color:#3151a7;}
            .admin-badge.readonly{background:#f4f5f7;color:#515b66;}
            .admin-badge.active{background:#e7f6ee;color:#0b7a3a;}
            .admin-badge.inactive{background:#fdecee;color:#b00020;}
            .admin-badge.twofa{background:#edf7ff;color:#1767aa;}
            .admin-badge.no2fa{background:#f4f5f7;color:#515b66;}
            .admin-table-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;}
            .admin-table-actions form{display:inline-flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin:0;}
            .admin-table-actions select{min-width:126px;}
            .admin-filters-shell{display:flex;flex-direction:column;gap:10px;}
            .admin-filters-top{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(220px,.9fr);gap:10px;align-items:end;}
            .admin-filters-top .field{display:flex;flex-direction:column;gap:5px;}
            .admin-filters-top label,.admin-advanced-filters label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5b6570;font-weight:700;}
            .admin-filters-top input,.admin-filters-top select,.admin-advanced-filters input,.admin-advanced-filters select{width:100%;}
            .admin-advanced-filters{border:1px solid #dde3e8;border-radius:14px;padding:10px 12px;background:#fbfcfd;}
            .admin-advanced-filters summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13px;font-weight:700;color:#18212a;}
            .admin-advanced-filters summary::-webkit-details-marker{display:none;}
            .admin-advanced-filters summary::after{content:"▾";font-size:12px;color:#5b6570;transition:transform .15s ease;}
            .admin-advanced-filters[open] summary::after{transform:rotate(180deg);}
            .admin-advanced-filters-body{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px;}
            .admin-advanced-filters .field{display:flex;flex-direction:column;gap:5px;}
            .admin-filters-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;grid-column:1/-1;}
            .admin-shell-tools{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 10px 0;}
            .admin-shell-tools .ui-muted{font-size:13px;line-height:1.5;}
            @media (max-width:1100px){.admin-summary{grid-template-columns:repeat(2,minmax(0,1fr));}}
            @media (max-width:900px){.admin-filters-top{grid-template-columns:1fr;}.admin-advanced-filters-body{grid-template-columns:repeat(2,minmax(0,1fr));}}
            @media (max-width:640px){.admin-summary{grid-template-columns:1fr;}.admin-advanced-filters-body{grid-template-columns:1fr;}.admin-table-actions{flex-direction:column;align-items:stretch;}.admin-table-actions form{width:100%;}.admin-table-actions select,.admin-table-actions button{width:100%;}.admin-shell-tools{align-items:flex-start;}}'
        );
        $html .= '<div class="admin-shell-tools">'
            . '<div class="ui-muted">' . $escape($tr('admins_manage_hint')) . '</div>'
            . '<div class="ui-muted"><a href="/admin/admins?lang=pl" style="' . ($locale === 'pl' ? 'font-weight:700;' : '') . '">PL</a> | <a href="/admin/admins?lang=en" style="' . ($locale === 'en' ? 'font-weight:700;' : '') . '">EN</a></div>'
            . '</div>';
        $html .= '<div class="admin-summary">'
            . $this->renderAdminSummaryCard($escape($tr('summary_total_admins')), (string)$totalAdmins, $escape($tr('summary_total_admins_desc')), 'neutral')
            . $this->renderAdminSummaryCard($escape($tr('summary_active_admins')), (string)$activeAdmins, $escape($tr('summary_active_admins_desc')), 'ok')
            . $this->renderAdminSummaryCard($escape($tr('summary_superadmins')), (string)$superAdmins, $escape($tr('summary_superadmins_desc')), 'warn')
            . $this->renderAdminSummaryCard($escape($tr('summary_2fa_admins')), (string)$twoFactorAdmins, $escape($tr('summary_2fa_admins_desc')), 'info')
            . '</div>';
        $html .= $notice;
        $advancedOpen = $roleFilter !== 'all' || $statusFilter !== 'all' || $twoFactorFilter !== 'all';
        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;">' . $escape($tr('filters')) . '</h3>'
            . '<form method="get" action="/admin/admins" class="admin-filters-shell">'
            . '<input type="hidden" name="lang" value="' . $escape($locale) . '" />'
            . '<div class="admin-filters-top">'
            . '<div class="field"><label>' . $escape($tr('search')) . '</label><input type="search" name="q" value="' . $escape($search) . '" placeholder="' . $escape($tr('search_placeholder')) . '" /></div>'
            . '<div class="field"><label>' . $escape($tr('sort_by')) . '</label><select name="sort"><option value="last_login_desc"' . ($sort === 'last_login_desc' ? ' selected' : '') . '>' . $escape($tr('sort_last_login_desc')) . '</option><option value="last_login_asc"' . ($sort === 'last_login_asc' ? ' selected' : '') . '>' . $escape($tr('sort_last_login_asc')) . '</option><option value="username_asc"' . ($sort === 'username_asc' ? ' selected' : '') . '>' . $escape($tr('sort_username_asc')) . '</option><option value="username_desc"' . ($sort === 'username_desc' ? ' selected' : '') . '>' . $escape($tr('sort_username_desc')) . '</option><option value="role_asc"' . ($sort === 'role_asc' ? ' selected' : '') . '>' . $escape($tr('sort_role_asc')) . '</option><option value="role_desc"' . ($sort === 'role_desc' ? ' selected' : '') . '>' . $escape($tr('sort_role_desc')) . '</option><option value="active_first"' . ($sort === 'active_first' ? ' selected' : '') . '>' . $escape($tr('sort_active_first')) . '</option></select></div>'
            . '</div>'
            . '<details class="admin-advanced-filters"' . ($advancedOpen ? ' open' : '') . '>'
            . '<summary>' . $escape($tr('advanced_filters')) . '</summary>'
            . '<div class="admin-advanced-filters-body">'
            . '<div class="field"><label>' . $escape($tr('role')) . '</label><select name="role"><option value="all"' . ($roleFilter === 'all' ? ' selected' : '') . '>' . $escape($tr('all_roles')) . '</option><option value="superadmin"' . ($roleFilter === 'superadmin' ? ' selected' : '') . '>superadmin</option><option value="operator"' . ($roleFilter === 'operator' ? ' selected' : '') . '>operator</option><option value="readonly"' . ($roleFilter === 'readonly' ? ' selected' : '') . '>readonly</option></select></div>'
            . '<div class="field"><label>' . $escape($tr('status')) . '</label><select name="status"><option value="all"' . ($statusFilter === 'all' ? ' selected' : '') . '>' . $escape($tr('all_statuses')) . '</option><option value="active"' . ($statusFilter === 'active' ? ' selected' : '') . '>' . $escape($tr('active')) . '</option><option value="inactive"' . ($statusFilter === 'inactive' ? ' selected' : '') . '>' . $escape($tr('inactive')) . '</option></select></div>'
            . '<div class="field"><label>2FA</label><select name="twofa"><option value="all"' . ($twoFactorFilter === 'all' ? ' selected' : '') . '>' . $escape($tr('all_2fa')) . '</option><option value="enabled"' . ($twoFactorFilter === 'enabled' ? ' selected' : '') . '>' . $escape($tr('enabled_upper')) . '</option><option value="disabled"' . ($twoFactorFilter === 'disabled' ? ' selected' : '') . '>' . $escape($tr('disabled_upper')) . '</option></select></div>'
            . '<div class="admin-filters-actions">'
            . '<button type="submit">' . $escape($tr('filter')) . '</button>'
            . '<a class="ui-button gray" href="/admin/admins?lang=' . $escape($locale) . '">' . $escape($tr('clear_filters')) . '</a>'
            . '</div></div></details></form></div>';
        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;">' . $escape($tr('new_admin')) . '</h3>'
            . '<form method="post" action="/admin/admins/create">'
            . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_manage_create')) . '" />'
            . '<label>' . $escape($tr('login')) . '</label><input type="text" name="username" required />'
            . '<label>' . $escape($tr('password')) . '</label><input type="password" name="password" autocomplete="new-password" required />'
            . '<label>' . $escape($tr('repeat_new_password')) . '</label><input type="password" name="password2" autocomplete="new-password" required />'
            . '<label>' . $escape($tr('role')) . '</label><select name="role"><option value="operator">operator</option><option value="readonly">readonly</option><option value="superadmin">superadmin</option></select>'
            . '<button type="submit" style="margin-top:12px;">' . $escape($tr('add_admin')) . '</button>'
            . '</form>'
            . '<div style="margin-top:10px;color:#666;font-size:12px;line-height:1.4;">' . $escape($tr('password_help')) . '</div>'
            . '</div>';
        $html .= '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;">' . $escape($tr('existing_admins')) . '</h3><div class="ui-muted" style="margin-bottom:10px;">' . $escape(sprintf($tr('admins_list_count'), count($filteredAdmins), $totalAdmins)) . '</div><table><thead><tr><th>' . $escape($tr('login')) . '</th><th>' . $escape($tr('role')) . '</th><th>' . $escape($tr('active')) . '</th><th>2FA</th><th>' . $escape($tr('last_login_header')) . '</th><th>' . $escape($tr('actions')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $dangerRows = '';
        foreach ($admins as $admin) {
            $username = (string)($admin['username'] ?? '');
            $sameAdmin = hash_equals($username, $currentAdmin);
            $disabledButton = $sameAdmin ? ' disabled' : '';
            $confirmMessage = $sameAdmin ? $tr('delete_admin_self_blocked') : sprintf($tr('delete_admin_confirm'), $username);
            $dangerRows .= '<form method="post" action="/admin/admins/' . rawurlencode($username) . '/delete" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 10px 0;">'
                . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_manage_delete_' . $username)) . '" />'
                . '<div style="min-width:220px;"><b>' . $escape($username) . '</b>' . ($sameAdmin ? ' <span class="admin-badge inactive" style="margin-left:8px;">' . $escape($tr('logged_in')) . '</span>' : '') . '</div>'
                . '<button type="submit" class="danger"' . $disabledButton . ' onclick="return confirm(' . json_encode($confirmMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');">' . $escape($sameAdmin ? $tr('delete_admin_self_blocked') : $tr('delete_admin')) . '</button>'
                . '</form>';
        }
        if ($dangerRows === '') {
            $dangerRows = '<div class="ui-muted">' . $escape($tr('no_admins')) . '</div>';
        }
        $html .= '<div class="card ui-danger-zone" style="margin-top:14px;"><h3 style="margin:0 0 10px 0;font-size:15px;">' . $escape($tr('danger_zone')) . '</h3><div class="ui-muted" style="margin-bottom:10px;">' . $escape($tr('danger_zone_desc')) . '</div>' . $dangerRows . '</div>';
        $html .= $this->adminUiLayoutClose();
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/admins/create", name="admin_admins_create", methods={"POST"})
     */
    public function createAdminAction(Request $request, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_SUPER');
        if ($response = $this->rejectInvalidCsrfTo($request, 'admin_manage_create', '/admin/admins')) {
            return $response;
        }
        $username = trim((string)$request->request->get('username', ''));
        $password = (string)$request->request->get('password', '');
        $password2 = (string)$request->request->get('password2', '');
        $role = (string)$request->request->get('role', 'operator');
        if ($username === '') {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translator($this->getAdminLocale($request))('err_login_empty'));
        }
        if (!hash_equals($password, $password2)) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translator($this->getAdminLocale($request))('err_passwords_mismatch'));
        }
        if ($err = $this->validatePassword($password, $this->getAdminLocale($request))) {
            return $this->redirectWithTo('/admin/admins', 'err', $err);
        }
        try {
            $store->addAdmin($username, $password, $role, true);
            $store->audit('admin_created', ['admin' => $this->currentAdminUsername(), 'createdUsername' => $username, 'role' => $role, 'ip' => $request->getClientIp()]);
        } catch (\Throwable $e) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translateStoreError($e->getMessage(), $this->getAdminLocale($request)));
        }
        return $this->redirectWithTo('/admin/admins', 'msg', $this->translator($this->getAdminLocale($request))('msg_admin_added'));
    }

    /**
     * @Route("/admin/admins/{username}/role", name="admin_admins_role", methods={"POST"})
     */
    public function updateAdminRoleAction(Request $request, string $username, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_SUPER');
        if ($response = $this->rejectInvalidCsrfTo($request, 'admin_manage_role_' . $username, '/admin/admins')) {
            return $response;
        }
        $role = (string)$request->request->get('role', 'operator');
        try {
            $store->updateAdminRole($username, $role);
            $store->audit('admin_role_changed', ['admin' => $this->currentAdminUsername(), 'target' => $username, 'role' => $role, 'ip' => $request->getClientIp()]);
        } catch (\Throwable $e) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translateStoreError($e->getMessage(), $this->getAdminLocale($request)));
        }
        return $this->redirectWithTo('/admin/admins', 'msg', $this->translator($this->getAdminLocale($request))('msg_role_updated'));
    }

    /**
     * @Route("/admin/admins/{username}/toggle-active", name="admin_admins_toggle_active", methods={"POST"})
     */
    public function toggleAdminActiveAction(Request $request, string $username, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_SUPER');
        if ($response = $this->rejectInvalidCsrfTo($request, 'admin_manage_toggle_' . $username, '/admin/admins')) {
            return $response;
        }
        if (hash_equals($username, $this->currentAdminUsername())) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translator($this->getAdminLocale($request))('err_modify_self_active'));
        }
        $account = $store->getAccount($username);
        if (!$account) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translator($this->getAdminLocale($request))('err_admin_missing'));
        }
        $newState = !((bool)($account['active'] ?? true));
        try {
            $store->setAdminActive($username, $newState);
            $store->audit('admin_active_changed', ['admin' => $this->currentAdminUsername(), 'target' => $username, 'active' => $newState, 'ip' => $request->getClientIp()]);
        } catch (\Throwable $e) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translateStoreError($e->getMessage(), $this->getAdminLocale($request)));
        }
        return $this->redirectWithTo('/admin/admins', 'msg', $this->translator($this->getAdminLocale($request))($newState ? 'msg_admin_activated' : 'msg_admin_deactivated'));
    }

    /**
     * @Route("/admin/admins/{username}/reset-2fa", name="admin_admins_reset_2fa", methods={"POST"})
     */
    public function resetAdminTwoFactorAction(Request $request, string $username, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_SUPER');
        if ($response = $this->rejectInvalidCsrfTo($request, 'admin_manage_reset_2fa_' . $username, '/admin/admins')) {
            return $response;
        }
        if (hash_equals($username, $this->currentAdminUsername())) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translator($this->getAdminLocale($request))('err_modify_self_2fa'));
        }
        if (!$store->getAccount($username)) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translator($this->getAdminLocale($request))('err_admin_missing'));
        }
        $store->disableTwoFactor($username);
        $store->audit('admin_2fa_reset_by_superadmin', ['admin' => $this->currentAdminUsername(), 'target' => $username, 'ip' => $request->getClientIp()]);
        return $this->redirectWithTo('/admin/admins', 'msg', $this->translator($this->getAdminLocale($request))('msg_2fa_reset'));
    }

    /**
     * @Route("/admin/admins/{username}/delete", name="admin_admins_delete", methods={"POST"})
     */
    public function deleteAdminAction(Request $request, string $username, AdminPanelAccountStore $store): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_SUPER');
        if ($response = $this->rejectInvalidCsrfTo($request, 'admin_manage_delete_' . $username, '/admin/admins')) {
            return $response;
        }
        if (hash_equals($username, $this->currentAdminUsername())) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translator($this->getAdminLocale($request))('err_modify_self_delete'));
        }
        try {
            $store->deleteAdmin($username);
            $store->audit('admin_deleted', ['admin' => $this->currentAdminUsername(), 'target' => $username, 'ip' => $request->getClientIp()]);
        } catch (\Throwable $e) {
            return $this->redirectWithTo('/admin/admins', 'err', $this->translateStoreError($e->getMessage(), $this->getAdminLocale($request)));
        }
        return $this->redirectWithTo('/admin/admins', 'msg', $this->translator($this->getAdminLocale($request))('msg_admin_deleted'));
    }

    private function redirectWith(string $key, string $value): RedirectResponse {
        return $this->redirectWithTo('/admin/account', $key, $value);
    }

    private function redirectWithTo(string $path, string $key, string $value): RedirectResponse {
        $qs = http_build_query([$key => $value]);
        return new RedirectResponse($path . ($qs ? ('?' . $qs) : ''));
    }

    private function renderAdminSummaryCard(string $title, string $value, string $description, string $variant): string {
        $variant = in_array($variant, ['ok', 'warn', 'info', 'neutral'], true) ? $variant : 'neutral';
        return '<div class="admin-summary-card ' . $variant . '">'
            . '<div class="admin-summary-kicker">' . $title . '</div>'
            . '<div class="admin-summary-value">' . $value . '</div>'
            . '<div class="admin-summary-desc">' . $description . '</div>'
            . '</div>';
    }

    private function rejectInvalidCsrf(Request $request, string $tokenId): ?RedirectResponse {
        return $this->rejectInvalidCsrfTo($request, $tokenId, '/admin/account');
    }

    private function rejectInvalidCsrfTo(Request $request, string $tokenId, string $path): ?RedirectResponse {
        if (!$this->isCsrfTokenValid($tokenId, (string)$request->request->get('_token', ''))) {
            return $this->redirectWithTo($path, 'err', $this->translator($this->getAdminLocale($request))('err_session_expired'));
        }
        return null;
    }

    private function csrfToken(string $tokenId): string {
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = $this->get('security.csrf.token_manager');
        return $csrfTokenManager->getToken($tokenId)->getValue();
    }

    private function validatePassword(string $password, string $locale = 'pl'): ?string {
        $tr = $this->translator($locale);
        if (strlen($password) < 12) {
            return $tr('err_password_len');
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return $tr('err_password_upper');
        }
        if (!preg_match('/[a-z]/', $password)) {
            return $tr('err_password_lower');
        }
        if (!preg_match('/\\d/', $password)) {
            return $tr('err_password_digit');
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return $tr('err_password_special');
        }
        return null;
    }

    private function currentAdminUsername(): string {
        $user = $this->getUser();
        return $user instanceof AdminPanelUser ? (string)$user->getUsername() : '';
    }

    private function findLastSuccessfulLogin(AdminPanelAccountStore $store, string $username): string {
        foreach (array_reverse($store->getAuditEntries(300)) as $entry) {
            if (($entry['event'] ?? '') !== 'admin_login_success') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if ($username !== '' && ($meta['admin'] ?? '') !== '' && !hash_equals((string)$meta['admin'], $username)) {
                continue;
            }
            return (string)($entry['ts'] ?? '');
        }
        return '';
    }

    private function adminLinks(string $locale = 'pl'): string {
        $tr = $this->translator($locale);
        return $this->isGranted('ROLE_ADMIN_SUPER')
            ? ' | <a href="/admin/admins">' . htmlspecialchars($tr('admins_menu'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a> | <a href="/admin/admin-history">' . htmlspecialchars($tr('admin_history_menu'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a> | <a href="/admin/health">' . htmlspecialchars($tr('system_health'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a> | <a href="/admin/backup">' . htmlspecialchars($tr('backup_restore'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>'
            : ' | <a href="/admin/health">' . htmlspecialchars($tr('system_health'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
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

    private function parseDateFilter(string $value, bool $endOfDay): ?\DateTimeImmutable {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
            return $date;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function renderHistoryPager(int $page, int $perPage, int $totalItems, array $query, callable $tr, callable $escape): string {
        $totalPages = max(1, (int)ceil($totalItems / max(1, $perPage)));
        $base = array_filter($query, static fn($value) => $value !== '');
        $prevUrl = '/admin/admin-history?' . http_build_query(array_merge($base, ['page' => max(1, $page - 1)]));
        $nextUrl = '/admin/admin-history?' . http_build_query(array_merge($base, ['page' => min($totalPages, $page + 1)]));
        return '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;font-size:12px;">'
            . '<div>' . $escape($tr('page')) . ': <span class="mono">' . $page . '/' . $totalPages . '</span> | ' . $escape($tr('items_total')) . ': <span class="mono">' . $totalItems . '</span></div>'
            . '<div style="display:flex;gap:10px;">'
            . ($page > 1 ? '<a href="' . $escape($prevUrl) . '">' . $escape($tr('prev')) . '</a>' : '<span style="opacity:.5;">' . $escape($tr('prev')) . '</span>')
            . ($page < $totalPages ? '<a href="' . $escape($nextUrl) . '">' . $escape($tr('next')) . '</a>' : '<span style="opacity:.5;">' . $escape($tr('next')) . '</span>')
            . '</div></div>';
    }

    private function exportAdminHistoryCsv(array $events): Response {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return new Response('CSV export failed', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        fputcsv($handle, ['ts', 'event', 'admin', 'target', 'details']);
        foreach ($events as $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            fputcsv($handle, [
                (string)($entry['ts'] ?? ''),
                (string)($entry['event'] ?? ''),
                (string)($meta['admin'] ?? ''),
                (string)($meta['target'] ?? $meta['createdUsername'] ?? $meta['newUsername'] ?? ''),
                json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="admin-history.csv"',
        ]);
    }

    private function adminHistoryEventLabel(string $eventName, callable $tr): string {
        return match ($eventName) {
            'admin_created' => $tr('event_admin_created'),
            'admin_deleted' => $tr('event_admin_deleted'),
            'admin_role_changed' => $tr('event_admin_role_changed'),
            'admin_active_changed' => $tr('event_admin_active_changed'),
            'admin_2fa_reset_by_superadmin' => $tr('event_admin_2fa_reset'),
            'admin_password_changed' => $tr('event_admin_password_changed'),
            'admin_username_changed' => $tr('event_admin_username_changed'),
            default => $eventName,
        };
    }

    private function translator(string $locale): callable {
        $dict = [
            'pl' => [
                'admins_title' => 'SUPLA Admin - Admini',
                'admins_menu' => 'Admini',
                'admin_history_menu' => 'Historia adminów',
                'system_health' => 'Stan systemu',
                'backup_restore' => 'Backup / Restore',
                'admins_manage_hint' => 'Zarządzaj kontami, rolami, aktywnością i 2FA z jednego miejsca.',
                'filters' => 'Filtry',
                'advanced_filters' => 'Filtry zaawansowane',
                'search' => 'Szukaj',
                'search_placeholder' => 'Login lub e-mail',
                'all_roles' => 'Wszystkie role',
                'all_statuses' => 'Wszystkie statusy',
                'all_2fa' => 'Wszystkie',
                'admins_list_count' => 'Pokazuję %1$d z %2$d kont.',
                'danger_zone' => 'Strefa ryzyka',
                'danger_zone_desc' => 'Operacje tutaj są nieodwracalne lub wymagają szczególnej ostrożności.',
                'last_login_header' => 'Ostatnie logowanie',
                'sort_by' => 'Sortuj',
                'sort_last_login_desc' => 'Ostatnie logowanie: najnowsze',
                'sort_last_login_asc' => 'Ostatnie logowanie: najstarsze',
                'sort_username_asc' => 'Login: A-Z',
                'sort_username_desc' => 'Login: Z-A',
                'sort_role_asc' => 'Rola: A-Z',
                'sort_role_desc' => 'Rola: Z-A',
                'sort_active_first' => 'Aktywne najpierw',
                'summary_total_admins' => 'Wszystkich adminów',
                'summary_total_admins_desc' => 'Konta widoczne w panelu administracyjnym.',
                'summary_active_admins' => 'Aktywnych',
                'summary_active_admins_desc' => 'Konta, które mogą się zalogować.',
                'summary_superadmins' => 'Superadminów',
                'summary_superadmins_desc' => 'Konta z pełnymi uprawnieniami.',
                'summary_2fa_admins' => 'Z 2FA',
                'summary_2fa_admins_desc' => 'Konta chronione dwuskładnikowo.',
                'admin_history_title' => 'SUPLA Admin - Historia adminów',
                'dashboard' => 'Dashboard',
                'users' => 'Użytkownicy',
                'account' => 'Konto',
                'account_title' => 'SUPLA Admin - Konto',
                'security_log' => 'Log bezpieczeństwa',
                'security_log_title' => 'SUPLA Admin - Log bezpieczeństwa',
                'logout' => 'Wyloguj',
                'entry' => 'Wpis',
                'no_entries' => 'Brak wpisów.',
                'email' => 'E-mail',
                'change_credentials' => 'Zmiana loginu/hasła',
                'current_password' => 'Aktualne hasło',
                'new_login_optional' => 'Nowy login (opcjonalnie)',
                'new_recovery_email_optional' => 'Nowy e-mail do odzyskiwania hasła (opcjonalnie)',
                'new_password_optional' => 'Nowe hasło (opcjonalnie)',
                'repeat_new_password' => 'Powtórz nowe hasło',
                'save' => 'Zapisz',
                'two_factor_login_status' => 'Logowanie 2FA',
                'enabled_upper' => 'WŁĄCZONE',
                'disabled_upper' => 'WYŁĄCZONE',
                'setup_in_progress_upper' => 'W TRAKCIE KONFIGURACJI',
                'setup_not_enforced' => 'jeszcze nie wymuszane',
                'two_factor_code_or_recovery' => 'Kod 2FA lub kod odzyskiwania',
                'disable_2fa' => 'Wyłącz 2FA',
                'enable_2fa_start' => 'Włącz 2FA (start)',
                'cancel_2fa_setup' => 'Anuluj konfigurację 2FA',
                'add_totp_app_hint' => 'Dodaj konto w aplikacji (TOTP) i wpisz kod aby potwierdzić.',
                'manual_key' => 'Klucz ręczny',
                'two_factor_qr_alt' => 'Kod QR 2FA',
                'two_factor_code' => 'Kod 2FA',
                'confirm_2fa' => 'Potwierdź 2FA',
                'confirm' => 'Potwierdź',
                'back' => 'Wróć',
                'recovery_codes_title' => 'SUPLA Admin - Kody odzyskiwania',
                'two_factor_enabled_title' => '2FA włączone',
                'save_recovery_codes_hint' => 'Zapisz kody odzyskiwania. Pokażę je tylko raz.',
                'two_factor_page_title' => 'SUPLA Admin - 2FA',
                'new_admin' => 'Nowe konto admina',
                'existing_admins' => 'Istniejące konta',
                'login' => 'Login',
                'password' => 'Hasło',
                'role' => 'Rola',
                'active' => 'Aktywne',
                'actions' => 'Akcje',
                'when' => 'Kiedy',
                'event' => 'Zdarzenie',
                'admin_actor' => 'Admin',
                'target' => 'Cel',
                'details' => 'Szczegóły',
                'all_events' => 'Wszystkie zdarzenia',
                'event_admin_created' => 'Utworzenie konta admina',
                'event_admin_deleted' => 'Usunięcie konta admina',
                'event_admin_role_changed' => 'Zmiana roli admina',
                'event_admin_active_changed' => 'Zmiana aktywności admina',
                'event_admin_2fa_reset' => 'Reset 2FA admina',
                'event_admin_password_changed' => 'Zmiana hasła admina',
                'event_admin_username_changed' => 'Zmiana loginu admina',
                'all_admins' => 'Wszyscy admini',
                'date_from' => 'Data od',
                'date_to' => 'Data do',
                'filter' => 'Filtruj',
                'clear_filters' => 'Wyczyść filtry',
                'export_csv' => 'Eksport CSV',
                'items_total' => 'Wszystkich wpisów',
                'page' => 'Strona',
                'prev' => 'Wstecz',
                'next' => 'Dalej',
                'add_admin' => 'Dodaj admina',
                'password_help' => 'Hasło: min 12 znaków, duża i mała litera, cyfra, znak specjalny.',
                'change_role' => 'Zmień rolę',
                'activate' => 'Aktywuj',
                'deactivate' => 'Dezaktywuj',
                'reset_2fa' => 'Resetuj 2FA',
                'delete_admin' => 'Usuń admina',
                'logged_in' => 'zalogowany',
                'delete_admin_confirm' => 'Na pewno usunąć konto administratora %s?',
                'delete_admin_self_blocked' => 'Nie można usunąć własnego konta',
                'deactivate_admin_confirm' => 'Na pewno dezaktywować konto administratora %s?',
                'activate_admin_confirm' => 'Na pewno aktywować konto administratora %s?',
                'reset_2fa_confirm' => 'Na pewno zresetować 2FA dla administratora %s?',
                'yes' => 'tak',
                'no' => 'nie',
                'no_admins' => 'Brak kont admina.',
                'no_admin_history' => 'Brak zmian adminów.',
                'err_login_empty' => 'Login nie może być pusty.',
                'err_modify_self_active' => 'Nie możesz dezaktywować własnego konta.',
                'err_modify_self_2fa' => 'Nie możesz resetować 2FA własnego konta.',
                'err_modify_self_delete' => 'Nie możesz usunąć własnego konta.',
                'err_admin_missing' => 'Konto admina nie istnieje.',
                'msg_admin_added' => 'Konto admina dodane.',
                'msg_role_updated' => 'Rola admina zaktualizowana.',
                'msg_admin_activated' => 'Konto admina aktywowane.',
                'msg_admin_deactivated' => 'Konto admina dezaktywowane.',
                'msg_2fa_reset' => '2FA wyzerowane dla wskazanego admina.',
                'msg_admin_deleted' => 'Konto admina usunięte.',
                'msg_2fa_disabled' => '2FA wyłączone.',
                'msg_credentials_saved' => 'Zapisano. Jeżeli zmieniłeś login, zaloguj się ponownie.',
                'err_current_password_invalid' => 'Nieprawidłowe aktualne hasło.',
                'err_passwords_mismatch' => 'Hasła nie są takie same.',
                'err_invalid_2fa_code' => 'Nieprawidłowy kod 2FA.',
                'err_invalid_code' => 'Nieprawidłowy kod.',
                'err_session_expired' => 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.',
                'too_many_attempts' => 'Zbyt wiele nieudanych prób. Spróbuj ponownie za %d s.',
                'err_password_len' => 'Hasło musi mieć min. 12 znaków.',
                'err_password_upper' => 'Hasło musi zawierać dużą literę.',
                'err_password_lower' => 'Hasło musi zawierać małą literę.',
                'err_password_digit' => 'Hasło musi zawierać cyfrę.',
                'err_password_special' => 'Hasło musi zawierać znak specjalny.',
                'err_username_exists' => 'Login już istnieje.',
                'err_keep_superadmin' => 'Musi pozostać co najmniej jeden aktywny superadmin.',
                'err_keep_admin' => 'Musi pozostać co najmniej jedno konto admina.',
            ],
            'en' => [
                'admins_title' => 'SUPLA Admin - Admins',
                'admins_menu' => 'Admins',
                'admin_history_menu' => 'Admin history',
                'system_health' => 'System health',
                'backup_restore' => 'Backup / Restore',
                'admins_manage_hint' => 'Manage accounts, roles, activity and 2FA in one place.',
                'filters' => 'Filters',
                'advanced_filters' => 'Advanced filters',
                'search' => 'Search',
                'search_placeholder' => 'Login or e-mail',
                'all_roles' => 'All roles',
                'all_statuses' => 'All statuses',
                'all_2fa' => 'All',
                'admins_list_count' => 'Showing %1$d of %2$d accounts.',
                'danger_zone' => 'Danger zone',
                'danger_zone_desc' => 'Operations here are irreversible or require extra caution.',
                'last_login_header' => 'Last login',
                'sort_by' => 'Sort by',
                'sort_last_login_desc' => 'Last login: newest first',
                'sort_last_login_asc' => 'Last login: oldest first',
                'sort_username_asc' => 'Login: A-Z',
                'sort_username_desc' => 'Login: Z-A',
                'sort_role_asc' => 'Role: A-Z',
                'sort_role_desc' => 'Role: Z-A',
                'sort_active_first' => 'Active first',
                'summary_total_admins' => 'Total admins',
                'summary_total_admins_desc' => 'Accounts visible in the admin panel.',
                'summary_active_admins' => 'Active',
                'summary_active_admins_desc' => 'Accounts allowed to sign in.',
                'summary_superadmins' => 'Superadmins',
                'summary_superadmins_desc' => 'Accounts with full privileges.',
                'summary_2fa_admins' => 'With 2FA',
                'summary_2fa_admins_desc' => 'Accounts protected with two-factor auth.',
                'admin_history_title' => 'SUPLA Admin - Admin history',
                'dashboard' => 'Dashboard',
                'users' => 'Users',
                'account' => 'Account',
                'account_title' => 'SUPLA Admin - Account',
                'security_log' => 'Security log',
                'security_log_title' => 'SUPLA Admin - Security log',
                'logout' => 'Logout',
                'entry' => 'Entry',
                'no_entries' => 'No entries.',
                'email' => 'E-mail',
                'change_credentials' => 'Change login/password',
                'current_password' => 'Current password',
                'new_login_optional' => 'New login (optional)',
                'new_recovery_email_optional' => 'New password recovery e-mail (optional)',
                'new_password_optional' => 'New password (optional)',
                'repeat_new_password' => 'Repeat new password',
                'save' => 'Save',
                'two_factor_login_status' => '2FA sign-in',
                'enabled_upper' => 'ENABLED',
                'disabled_upper' => 'DISABLED',
                'setup_in_progress_upper' => 'SETUP IN PROGRESS',
                'setup_not_enforced' => 'not enforced yet',
                'two_factor_code_or_recovery' => '2FA code or recovery code',
                'disable_2fa' => 'Disable 2FA',
                'enable_2fa_start' => 'Enable 2FA (start)',
                'cancel_2fa_setup' => 'Cancel 2FA setup',
                'add_totp_app_hint' => 'Add the account in your TOTP app and enter the code to confirm.',
                'manual_key' => 'Manual key',
                'two_factor_qr_alt' => '2FA QR code',
                'two_factor_code' => '2FA code',
                'confirm_2fa' => 'Confirm 2FA',
                'confirm' => 'Confirm',
                'back' => 'Back',
                'recovery_codes_title' => 'SUPLA Admin - Recovery codes',
                'two_factor_enabled_title' => '2FA enabled',
                'save_recovery_codes_hint' => 'Save the recovery codes. They are shown only once.',
                'two_factor_page_title' => 'SUPLA Admin - 2FA',
                'new_admin' => 'New admin account',
                'existing_admins' => 'Existing accounts',
                'login' => 'Login',
                'password' => 'Password',
                'role' => 'Role',
                'active' => 'Active',
                'actions' => 'Actions',
                'when' => 'When',
                'event' => 'Event',
                'admin_actor' => 'Admin',
                'target' => 'Target',
                'details' => 'Details',
                'all_events' => 'All events',
                'event_admin_created' => 'Admin account created',
                'event_admin_deleted' => 'Admin account deleted',
                'event_admin_role_changed' => 'Admin role changed',
                'event_admin_active_changed' => 'Admin activation changed',
                'event_admin_2fa_reset' => 'Admin 2FA reset',
                'event_admin_password_changed' => 'Admin password changed',
                'event_admin_username_changed' => 'Admin login changed',
                'all_admins' => 'All admins',
                'date_from' => 'Date from',
                'date_to' => 'Date to',
                'filter' => 'Filter',
                'clear_filters' => 'Clear filters',
                'export_csv' => 'Export CSV',
                'items_total' => 'Total items',
                'page' => 'Page',
                'prev' => 'Prev',
                'next' => 'Next',
                'add_admin' => 'Add admin',
                'password_help' => 'Password: at least 12 chars, uppercase, lowercase, number and special char.',
                'change_role' => 'Change role',
                'activate' => 'Activate',
                'deactivate' => 'Deactivate',
                'reset_2fa' => 'Reset 2FA',
                'delete_admin' => 'Delete admin',
                'logged_in' => 'logged in',
                'delete_admin_confirm' => 'Are you sure you want to delete admin account %s?',
                'delete_admin_self_blocked' => 'Cannot delete your own account',
                'deactivate_admin_confirm' => 'Are you sure you want to deactivate admin account %s?',
                'activate_admin_confirm' => 'Are you sure you want to activate admin account %s?',
                'reset_2fa_confirm' => 'Are you sure you want to reset 2FA for admin account %s?',
                'yes' => 'yes',
                'no' => 'no',
                'no_admins' => 'No admin accounts.',
                'no_admin_history' => 'No admin changes.',
                'err_login_empty' => 'Login cannot be empty.',
                'err_modify_self_active' => 'You cannot deactivate your own account.',
                'err_modify_self_2fa' => 'You cannot reset 2FA on your own account.',
                'err_modify_self_delete' => 'You cannot delete your own account.',
                'err_admin_missing' => 'Admin account does not exist.',
                'msg_admin_added' => 'Admin account added.',
                'msg_role_updated' => 'Admin role updated.',
                'msg_admin_activated' => 'Admin account activated.',
                'msg_admin_deactivated' => 'Admin account deactivated.',
                'msg_2fa_reset' => '2FA has been reset for the selected admin.',
                'msg_admin_deleted' => 'Admin account deleted.',
                'msg_2fa_disabled' => '2FA disabled.',
                'msg_credentials_saved' => 'Saved. If you changed the login, sign in again.',
                'err_current_password_invalid' => 'Invalid current password.',
                'err_passwords_mismatch' => 'Passwords do not match.',
                'err_invalid_2fa_code' => 'Invalid 2FA code.',
                'err_invalid_code' => 'Invalid code.',
                'err_session_expired' => 'The session expired. Refresh the page and try again.',
                'too_many_attempts' => 'Too many failed attempts. Try again in %d s.',
                'err_password_len' => 'Password must be at least 12 characters long.',
                'err_password_upper' => 'Password must contain an uppercase letter.',
                'err_password_lower' => 'Password must contain a lowercase letter.',
                'err_password_digit' => 'Password must contain a digit.',
                'err_password_special' => 'Password must contain a special character.',
                'err_username_exists' => 'Login already exists.',
                'err_keep_superadmin' => 'At least one active superadmin must remain.',
                'err_keep_admin' => 'At least one admin account must remain.',
            ],
        ];
        $lang = isset($dict[$locale]) ? $locale : 'pl';
        return static fn(string $key): string => $dict[$lang][$key] ?? $key;
    }

    private function translateStoreError(string $message, string $locale): string {
        $tr = $this->translator($locale);
        return match ($message) {
            'Username already exists.' => $tr('err_username_exists'),
            'At least one active superadmin must remain.' => $tr('err_keep_superadmin'),
            'At least one admin account must remain.' => $tr('err_keep_admin'),
            default => $message,
        };
    }
}
