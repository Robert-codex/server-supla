<?php

namespace SuplaBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\Mailer\SuplaMailer;
use SuplaBundle\Security\AdminPanelAccountStore;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Mime\Email;

class AdminAuthController extends Controller {
    use AdminUiTrait;

    private const LOCALE_COOKIE = 'supla_admin_locale';

    /**
     * @Route("/admin/login", name="admin_login", methods={"GET","POST"})
     */
    public function loginAction(Request $request, AdminPanelAccountStore $store): Response {
        if ($this->getUser()) {
            $session = $request->getSession();
            $targetPath = $session ? (string)$session->get('_security.admin.target_path', '') : '';
            if ($targetPath !== '' && $targetPath !== '/admin/login') {
                return $this->redirect($targetPath);
            }
            $referer = (string)$request->headers->get('referer', '');
            if ($referer !== '') {
                $refererPath = parse_url($referer, PHP_URL_PATH);
                if (is_string($refererPath) && str_starts_with($refererPath, '/admin/') && $refererPath !== '/admin/login') {
                    return $this->redirect($refererPath . (parse_url($referer, PHP_URL_QUERY) ? ('?' . parse_url($referer, PHP_URL_QUERY)) : ''));
                }
            }
            return $this->redirect('/admin/dashboard');
        }
        if ($request->query->has('lang')) {
            return $this->redirectWithLocale($request, '/admin/login');
        }

        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $session = $request->getSession();
        $lastUsername = $session ? (string)$session->get(Security::LAST_USERNAME, '') : '';
        $error = '';
        $authError = null;
        if ($request->attributes->has(Security::AUTHENTICATION_ERROR)) {
            $authError = $request->attributes->get(Security::AUTHENTICATION_ERROR);
        } elseif ($session) {
            $authError = $session->get(Security::AUTHENTICATION_ERROR, '');
            $session->remove(Security::AUTHENTICATION_ERROR);
        }
        if ($authError instanceof AuthenticationException) {
            $error = $this->formatAuthenticationError($authError, $locale);
        } elseif (is_string($authError) && $authError !== '') {
            $error = $tr('login_failed');
        }
        $remaining = $store->getLoginBlockRemainingSeconds($request->getClientIp());
        if ($remaining > 0) {
            $error = sprintf($tr('too_many_attempts'), $remaining);
        }

        $csrfToken = '';
        if ($this->has('security.csrf.token_manager')) {
            /** @var CsrfTokenManagerInterface $csrfTokenManager */
            $csrfTokenManager = $this->get('security.csrf.token_manager');
            $csrfToken = $csrfTokenManager->getToken('authenticate')->getValue();
        }

        $lastLogin = $this->findLastSuccessfulLogin($store, $lastUsername);
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = $this->adminUiPageOpen(
            $escape($tr('login_title')),
            'body.auth-shell{display:flex;align-items:center;justify-content:center;padding:24px;}.auth-shell{background:radial-gradient(circle at top left,rgba(11,122,58,.08) 0,rgba(35,166,91,.05) 30%,transparent 60%),radial-gradient(circle at bottom right,rgba(11,122,58,.06) 0,transparent 48%),linear-gradient(180deg,#f5faf7 0,#eef3f6 100%);}.auth-shell .auth-card{width:min(980px,96vw);display:grid;grid-template-columns:1.05fr .95fr;overflow:hidden;border-radius:24px;border:1px solid rgba(223,229,234,.95);background:rgba(255,255,255,.86);box-shadow:0 20px 60px rgba(16,24,40,.12);backdrop-filter:blur(14px);}.auth-shell .auth-hero{padding:30px;background:linear-gradient(160deg,var(--ui-accent) 0,var(--ui-accent-alt) 100%);color:#fff;display:flex;flex-direction:column;justify-content:space-between;gap:22px;}.auth-shell .auth-hero-top{display:flex;justify-content:space-between;gap:12px;align-items:center;}.auth-shell .auth-hero-logo{display:inline-flex;align-items:center;gap:10px;font-weight:800;letter-spacing:-0.02em;font-size:16px;text-decoration:none;color:#fff;}.auth-shell .auth-hero-mark{width:36px;height:36px;border-radius:12px;background:rgba(255,255,255,.18);display:inline-flex;align-items:center;justify-content:center;font-size:18px;box-shadow:inset 0 0 0 1px rgba(255,255,255,.12);}.auth-shell .auth-hero-copy{max-width:340px;}.auth-shell .auth-hero-copy h1{font-size:34px;line-height:1.02;margin:0 0 12px 0;letter-spacing:-0.04em;color:#fff;}.auth-shell .auth-hero-copy p{margin:0;color:rgba(255,255,255,.88);line-height:1.55;font-size:14px;}.auth-shell .auth-hero-footer{display:grid;gap:10px;}.auth-shell .auth-hero-pill{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:999px;background:rgba(255,255,255,.14);color:#fff;font-size:12px;line-height:1;border:1px solid rgba(255,255,255,.16);}.auth-shell .auth-form{padding:30px 30px 26px 30px;display:flex;flex-direction:column;justify-content:center;}.auth-shell .auth-form h2{margin:0 0 8px 0;font-size:22px;letter-spacing:-0.03em;}.auth-shell .auth-form .muted{margin:0 0 14px 0;}.auth-shell .auth-form form{display:grid;gap:10px;margin-top:10px;}.auth-shell .auth-form label{font-size:12px;font-weight:700;color:#45515c;margin-bottom:-4px;}.auth-shell .auth-form input{width:100%;height:42px;}.auth-shell .password-row{display:flex;gap:8px;align-items:center;}.auth-shell .password-row input{flex:1;}.auth-shell .toggle-pass{white-space:nowrap;background:#f6f8f9;color:#18212a;border-color:#dfe5ea;}.auth-shell .submit{width:100%;height:44px;margin-top:4px;font-size:14px;font-weight:700;}.auth-shell .auth-links{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-top:12px;}.auth-shell .auth-links .muted,.auth-shell .auth-links a{font-size:12px;}.auth-shell .auth-meta{margin-top:14px;padding-top:12px;border-top:1px solid #edf0f2;color:#5b6570;font-size:12px;line-height:1.5;}.auth-shell .auth-alert{margin-top:12px;}.auth-shell .auth-lang{display:flex;gap:8px;align-items:center;}.auth-shell .auth-lang a{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:30px;padding:0 10px;border-radius:999px;background:rgba(255,255,255,.16);color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.18);}.auth-shell .auth-lang a.active{background:#fff;color:var(--ui-accent);font-weight:800;}.auth-shell .auth-lang a:hover{text-decoration:none;filter:brightness(1.03);}.auth-shell .err{margin-top:12px;padding:11px 12px;border-radius:12px;background:var(--ui-danger-soft);border:1px solid var(--ui-danger-border);color:var(--ui-danger);font-size:13px;}.auth-shell .hint{margin-top:10px;color:#5b6570;font-size:12px;}.auth-shell .auth-note{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-radius:12px;background:#f7fbf8;border:1px solid #d7eadf;color:#2b3a32;font-size:12px;line-height:1.45;}.auth-shell .auth-note strong{color:var(--ui-accent);}.auth-shell .auth-hero-stats{display:grid;gap:10px;}.auth-shell .auth-hero-stat{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.12);font-size:12px;}.auth-shell .auth-hero-stat b{font-size:13px;}@media (max-width:900px){.auth-shell .auth-card{grid-template-columns:1fr;}.auth-shell .auth-hero{padding:24px;}.auth-shell .auth-form{padding:24px;}}@media (max-width:640px){body.auth-shell{padding:10px;}.auth-shell .auth-card{border-radius:18px;}.auth-shell .auth-hero{padding:18px;gap:16px;}.auth-shell .auth-hero-top{align-items:flex-start;}.auth-shell .auth-hero-copy h1{font-size:26px;line-height:1.05;margin-bottom:10px;}.auth-shell .auth-hero-copy p{font-size:13px;}.auth-shell .auth-hero-footer{gap:8px;}.auth-shell .auth-hero-pill{padding:8px 10px;font-size:11px;}.auth-shell .auth-hero-stats{display:none;}.auth-shell .auth-form{padding:18px;}.auth-shell .auth-form h2{font-size:20px;}.auth-shell .auth-note{padding:9px 10px;font-size:11px;}.auth-shell .auth-links{flex-direction:column;align-items:flex-start;gap:8px;}.auth-shell .auth-links .hint{margin-top:0;}.auth-shell .password-row{flex-direction:column;align-items:stretch;}.auth-shell .toggle-pass{width:100%;}}'
            ,
            'ui-shell auth-shell'
        );
        $html .= '<div class="auth-card">'
            . '<section class="auth-hero">'
            . '<div class="auth-hero-top">'
            . '<a class="auth-hero-logo" href="/admin/login" aria-label="SUPLA Admin">'
            . '<span class="auth-hero-mark">S</span>'
            . '<span>SUPLA Admin</span>'
            . '</a>'
            . '<div class="auth-lang"><a href="/admin/login?lang=pl"' . ($locale === 'pl' ? ' class="active"' : '') . '>PL</a><a href="/admin/login?lang=en"' . ($locale === 'en' ? ' class="active"' : '') . '>EN</a></div>'
            . '</div>'
            . '<div class="auth-hero-copy">'
            . '<h1>' . $escape($tr('login_title')) . '</h1>'
            . '<p>' . $escape($tr('separate_account')) . '</p>'
            . '</div>'
            . '<div class="auth-hero-footer">'
            . '<div class="auth-hero-pill">Panel administracyjny</div>'
            . '<div class="auth-hero-pill">2FA · Password reset · Audit log</div>'
            . '<div class="auth-hero-stats">'
            . '<div class="auth-hero-stat"><span>Last login</span><b>' . $escape($lastLogin !== '' ? $lastLogin : '—') . '</b></div>'
            . '<div class="auth-hero-stat"><span>Status</span><b>' . $escape($error !== '' ? $tr('login_failed') : 'Ready') . '</b></div>'
            . '</div>'
            . '</div>'
            . '</section>'
            . '<section class="auth-form">'
            . '<h2>' . $escape($tr('sign_in')) . '</h2>'
            . '<div class="muted">' . $escape($tr('separate_account')) . '</div>'
            . '<div class="auth-note"><strong>Tip:</strong><span>' . $escape($tr('login_tip')) . '</span></div>'
            . '<form method="post" action="/admin/login">'
            . '<input type="hidden" name="_csrf_token" value="' . $escape($csrfToken) . '" />'
            . '<label>' . $escape($tr('username')) . '</label><input name="_username" type="text" autocomplete="username" value="' . $escape($lastUsername) . '" required />'
            . '<label>' . $escape($tr('password')) . '</label><div class="password-row"><input id="admin-password" name="_password" type="password" autocomplete="current-password" required /><button type="button" class="toggle-pass" data-target="admin-password">' . $escape($tr('show_password')) . '</button></div>'
            . '<button type="submit" class="submit">' . $escape($tr('sign_in')) . '</button>'
            . '</form>'
            . '<div class="auth-links">'
            . '<div class="muted"><a href="/admin/forgot-password">' . $escape($tr('forgot_password')) . '</a></div>'
            . '<div class="hint">' . $escape($error !== '' ? $error : ($lastLogin !== '' ? sprintf($tr('last_login'), $lastLogin) : '')) . '</div>'
            . '</div>';
        if ($error !== '') {
            $html .= '<div class="err auth-alert">' . $escape($error) . '</div>';
        }
        $html .= '<script>(function(){document.addEventListener("click",function(event){var button=event.target.closest(".toggle-pass");if(!button){return;}var input=document.getElementById(button.getAttribute("data-target"));if(!input){return;}var shown=input.type==="text";input.type=shown?"password":"text";button.textContent=shown?' . json_encode($tr('show_password'), JSON_UNESCAPED_UNICODE) . ':' . json_encode($tr('hide_password'), JSON_UNESCAPED_UNICODE) . ';});})();</script>'
            . '</section>'
            . '</div>'
            . $this->adminUiPageClose();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/forgot-password", name="admin_forgot_password", methods={"GET","POST"})
     */
    public function forgotPasswordAction(Request $request, AdminPanelAccountStore $store, SuplaMailer $mailer): Response {
        if ($request->query->has('lang')) {
            return $this->redirectWithLocale($request, '/admin/forgot-password');
        }
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $msg = '';
        $err = '';
        if ($request->isMethod('POST')) {
            $username = trim((string)$request->request->get('username', ''));
            $email = trim((string)$request->request->get('email', ''));
            try {
                $token = $store->createPasswordResetToken($username, $email);
                $resetUrl = $request->getSchemeAndHttpHost() . '/admin/reset-password?token=' . rawurlencode($token);
                $subject = $tr('reset_subject');
                $body = sprintf($tr('reset_mail_body'), $resetUrl);
                $message = (new Email())
                    ->to($email)
                    ->subject($subject)
                    ->text($body);
                if ($mailer->send($message)) {
                    $msg = $tr('reset_sent');
                } else {
                    $err = $tr('reset_send_failed');
                }
                $store->audit('admin_password_reset_requested', ['admin' => $username, 'email' => $email, 'ip' => $request->getClientIp()]);
            } catch (\Throwable $e) {
                $err = $tr('reset_generic_error');
            }
        }

        $html = $this->adminUiPageOpen(
            $escape($tr('forgot_password')),
            'body.auth-shell{display:flex;align-items:center;justify-content:center;padding:24px;}.auth-shell{background:radial-gradient(circle at top left,rgba(11,122,58,.08) 0,rgba(35,166,91,.05) 30%,transparent 60%),radial-gradient(circle at bottom right,rgba(11,122,58,.06) 0,transparent 48%),linear-gradient(180deg,#f5faf7 0,#eef3f6 100%);}.auth-shell .auth-card{width:min(900px,96vw);display:grid;grid-template-columns:1fr 1fr;overflow:hidden;border-radius:24px;border:1px solid rgba(223,229,234,.95);background:rgba(255,255,255,.86);box-shadow:0 20px 60px rgba(16,24,40,.12);backdrop-filter:blur(14px);}.auth-shell .auth-hero{padding:30px;background:linear-gradient(160deg,var(--ui-accent) 0,var(--ui-accent-alt) 100%);color:#fff;display:flex;flex-direction:column;justify-content:space-between;gap:22px;}.auth-shell .auth-form{padding:30px;display:flex;flex-direction:column;justify-content:center;}.auth-shell .auth-form form{display:grid;gap:10px;margin-top:10px;}.auth-shell .auth-form label{font-size:12px;font-weight:700;color:#45515c;margin-bottom:-4px;}.auth-shell .auth-form input{width:100%;height:42px;}.auth-shell .submit{width:100%;height:44px;margin-top:4px;font-size:14px;font-weight:700;}.auth-shell .auth-links{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-top:12px;}@media (max-width:900px){.auth-shell .auth-card{grid-template-columns:1fr;}.auth-shell .auth-hero{padding:24px;}.auth-shell .auth-form{padding:24px;}}@media (max-width:640px){body.auth-shell{padding:10px;}.auth-shell .auth-card{border-radius:20px;}.auth-shell .auth-hero{padding:20px;}.auth-shell .auth-form{padding:20px;}}'
            ,
            'ui-shell auth-shell'
        );
        $html .= '<div class="auth-card"><section class="auth-hero"><div class="auth-hero-top"><a class="auth-hero-logo" href="/admin/login" aria-label="SUPLA Admin"><span class="auth-hero-mark">S</span><span>SUPLA Admin</span></a><div class="auth-lang"><a href="/admin/forgot-password?lang=pl">PL</a><a href="/admin/forgot-password?lang=en">EN</a></div></div><div class="auth-hero-copy"><h1>' . $escape($tr('forgot_password')) . '</h1><p>' . $escape($tr('forgot_password_help')) . '</p></div></section><section class="auth-form"><h2>' . $escape($tr('forgot_password')) . '</h2>'
            . '<form method="post"><label>' . $escape($tr('username')) . '</label><input name="username" required /><label>' . $escape($tr('email')) . '</label><input name="email" type="email" required /><button type="submit" class="submit">' . $escape($tr('send_reset')) . '</button></form>'
            . '<div class="auth-links"><div class="muted"><a href="/admin/login">' . $escape($tr('back_to_login')) . '</a></div></div>';
        if ($msg !== '') {
            $html .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $html .= '<div class="notice bad">' . $escape($err) . '</div>';
        }
        $html .= '</section></div>' . $this->adminUiPageClose();
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/reset-password", name="admin_reset_password", methods={"GET","POST"})
     */
    public function resetPasswordAction(Request $request, AdminPanelAccountStore $store): Response {
        if ($request->query->has('lang')) {
            return $this->redirectWithLocale($request, '/admin/reset-password');
        }
        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $token = trim((string)$request->query->get('token', $request->request->get('token', '')));
        $account = $token !== '' ? $store->getAccountByPasswordResetToken($token) : [];
        $msg = '';
        $err = '';
        if ($request->isMethod('POST')) {
            $password = (string)$request->request->get('newPassword', '');
            $password2 = (string)$request->request->get('newPassword2', '');
            if (!$account) {
                $err = $tr('reset_token_invalid');
            } elseif (!hash_equals($password, $password2)) {
                $err = $tr('passwords_mismatch');
            } else {
                $validation = $this->validatePassword($password, $locale);
                if ($validation !== null) {
                    $err = $validation;
                } else {
                    $username = $store->resetPasswordWithToken($token, $password);
                    $store->audit('admin_password_reset_completed', ['admin' => $username, 'ip' => $request->getClientIp()]);
                    $msg = $tr('reset_completed');
                    $account = [];
                }
            }
        }

        $html = $this->adminUiPageOpen(
            $escape($tr('reset_password')),
            'body.auth-shell{display:flex;align-items:center;justify-content:center;padding:24px;}.auth-shell{background:radial-gradient(circle at top left,rgba(11,122,58,.08) 0,rgba(35,166,91,.05) 30%,transparent 60%),radial-gradient(circle at bottom right,rgba(11,122,58,.06) 0,transparent 48%),linear-gradient(180deg,#f5faf7 0,#eef3f6 100%);}.auth-shell .auth-card{width:min(900px,96vw);display:grid;grid-template-columns:1fr 1fr;overflow:hidden;border-radius:24px;border:1px solid rgba(223,229,234,.95);background:rgba(255,255,255,.86);box-shadow:0 20px 60px rgba(16,24,40,.12);backdrop-filter:blur(14px);}.auth-shell .auth-hero{padding:30px;background:linear-gradient(160deg,var(--ui-accent) 0,var(--ui-accent-alt) 100%);color:#fff;display:flex;flex-direction:column;justify-content:space-between;gap:22px;}.auth-shell .auth-form{padding:30px;display:flex;flex-direction:column;justify-content:center;}.auth-shell .auth-form form{display:grid;gap:10px;margin-top:10px;}.auth-shell .auth-form label{font-size:12px;font-weight:700;color:#45515c;margin-bottom:-4px;}.auth-shell .auth-form input{width:100%;height:42px;}.auth-shell .submit{width:100%;height:44px;margin-top:4px;font-size:14px;font-weight:700;}.auth-shell .auth-links{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-top:12px;}@media (max-width:900px){.auth-shell .auth-card{grid-template-columns:1fr;}.auth-shell .auth-hero{padding:24px;}.auth-shell .auth-form{padding:24px;}}@media (max-width:640px){body.auth-shell{padding:10px;}.auth-shell .auth-card{border-radius:20px;}.auth-shell .auth-hero{padding:20px;}.auth-shell .auth-form{padding:20px;}}'
            ,
            'ui-shell auth-shell'
        );
        $html .= '<div class="auth-card"><section class="auth-hero"><div class="auth-hero-top"><a class="auth-hero-logo" href="/admin/login" aria-label="SUPLA Admin"><span class="auth-hero-mark">S</span><span>SUPLA Admin</span></a><div class="auth-lang"><a href="/admin/reset-password?lang=pl">PL</a><a href="/admin/reset-password?lang=en">EN</a></div></div><div class="auth-hero-copy"><h1>' . $escape($tr('reset_password')) . '</h1><p>' . $escape($tr('reset_password_help')) . '</p></div></section><section class="auth-form"><h2>' . $escape($tr('reset_password')) . '</h2>';
        if ($msg !== '') {
            $html .= '<div class="notice ok">' . $escape($msg) . '</div><div class="muted" style="margin-top:10px;font-size:12px;"><a href="/admin/login">' . $escape($tr('back_to_login')) . '</a></div>';
        } elseif (!$account) {
            $html .= '<div class="notice bad">' . $escape($tr('reset_token_invalid')) . '</div>';
        } else {
            $html .= '<form method="post"><input type="hidden" name="token" value="' . $escape($token) . '" /><label>' . $escape($tr('new_password')) . '</label><input type="password" name="newPassword" required /><label>' . $escape($tr('repeat_password')) . '</label><input type="password" name="newPassword2" required /><button type="submit" class="submit">' . $escape($tr('save_new_password')) . '</button></form>';
        }
        if ($err !== '') {
            $html .= '<div class="notice bad">' . $escape($err) . '</div>';
        }
        $html .= '</section></div>' . $this->adminUiPageClose();
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/logout", name="admin_logout", methods={"GET"})
     */
    public function logoutAction(): void {
        throw new \RuntimeException('Logout should be handled by the security firewall.');
    }

    private function getAdminLocale(Request $request): string {
        $cookie = strtolower(substr((string)$request->cookies->get(self::LOCALE_COOKIE, ''), 0, 2));
        return in_array($cookie, ['pl', 'en'], true) ? $cookie : 'pl';
    }

    private function redirectWithLocale(Request $request, string $path): RedirectResponse {
        $lang = strtolower(substr((string)$request->query->get('lang', 'pl'), 0, 2));
        if (!in_array($lang, ['pl', 'en'], true)) {
            $lang = 'pl';
        }
        $response = new RedirectResponse($path);
        $response->headers->setCookie(new Cookie(self::LOCALE_COOKIE, $lang, time() + 3600 * 24 * 365, '/', null, $request->isSecure(), true, false, 'Lax'));
        return $response;
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

    private function validatePassword(string $password, string $locale = 'pl'): ?string {
        $tr = $this->translator($locale);
        if (strlen($password) < 12) {
            return $tr('password_too_short');
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return $tr('password_policy_error');
        }
        return null;
    }

    private function formatAuthenticationError(AuthenticationException $exception, string $locale): string {
        $messageKey = $exception->getMessageKey();
        if (in_array($messageKey, ['Bad credentials.', 'Invalid credentials.'], true)) {
            return $this->translator($locale)('login_failed');
        }
        if ($messageKey === 'Account is locked.') {
            return $this->translator($locale)('account_locked');
        }
        return $this->translator($locale)('login_failed');
    }

    private function translator(string $locale): callable {
        $dict = [
            'pl' => [
                'login_title' => 'SUPLA Admin - Logowanie',
                'username' => 'Login',
                'password' => 'Hasło',
                'show_password' => 'Pokaż',
                'hide_password' => 'Ukryj',
                'sign_in' => 'Zaloguj',
                'login_failed' => 'Nieprawidłowy login lub hasło.',
                'account_locked' => 'Konto administratora jest tymczasowo zablokowane.',
                'forgot_password' => 'Nie pamiętam hasła',
                'separate_account' => 'To jest osobne konto administratora, niezależne od konta użytkownika SUPLA.',
                'login_tip' => 'Użyj konta administratora i włącz 2FA, jeśli jest dostępne.',
                'too_many_attempts' => 'Zbyt wiele błędnych prób. Spróbuj ponownie za %d s.',
                'last_login' => 'Ostatnie udane logowanie: %s',
                'email' => 'Adres e-mail',
                'send_reset' => 'Wyślij link resetu',
                'back_to_login' => 'Powrót do logowania',
                'reset_subject' => 'SUPLA Admin - reset hasła',
                'reset_mail_body' => "Link do resetu hasła administratora:\n\n%s\n\nLink jest ważny przez 60 minut.",
                'reset_sent' => 'Jeśli dane są poprawne, link resetu został wysłany.',
                'reset_send_failed' => 'Nie udało się wysłać wiadomości e-mail z linkiem resetu.',
                'reset_generic_error' => 'Nie udało się rozpocząć resetu hasła.',
                'reset_password' => 'Reset hasła administratora',
                'reset_token_invalid' => 'Link resetu jest nieprawidłowy lub wygasł.',
                'forgot_password_help' => 'Podaj login i adres e-mail, aby wysłać bezpieczny link resetu.',
                'reset_password_help' => 'Ustaw nowe hasło dla konta administratora.',
                'new_password' => 'Nowe hasło',
                'repeat_password' => 'Powtórz nowe hasło',
                'save_new_password' => 'Zapisz nowe hasło',
                'passwords_mismatch' => 'Hasła nie są takie same.',
                'reset_completed' => 'Hasło zostało zmienione. Możesz się zalogować.',
                'password_too_short' => 'Hasło musi mieć co najmniej 12 znaków.',
                'password_policy_error' => 'Hasło musi zawierać dużą i małą literę, cyfrę oraz znak specjalny.',
            ],
            'en' => [
                'login_title' => 'SUPLA Admin - Login',
                'username' => 'Username',
                'password' => 'Password',
                'show_password' => 'Show',
                'hide_password' => 'Hide',
                'sign_in' => 'Sign in',
                'login_failed' => 'Invalid username or password.',
                'account_locked' => 'The administrator account is temporarily locked.',
                'forgot_password' => 'Forgot password',
                'separate_account' => 'This is a separate administrator account, not a SUPLA user account.',
                'login_tip' => 'Use your administrator account and keep 2FA enabled when available.',
                'too_many_attempts' => 'Too many failed attempts. Try again in %d s.',
                'last_login' => 'Last successful login: %s',
                'email' => 'E-mail address',
                'send_reset' => 'Send reset link',
                'back_to_login' => 'Back to login',
                'reset_subject' => 'SUPLA Admin - password reset',
                'reset_mail_body' => "Admin password reset link:\n\n%s\n\nThe link is valid for 60 minutes.",
                'reset_sent' => 'If the data is correct, the reset link has been sent.',
                'reset_send_failed' => 'Could not send the password reset e-mail.',
                'reset_generic_error' => 'Could not start the password reset flow.',
                'reset_password' => 'Administrator password reset',
                'reset_token_invalid' => 'The reset link is invalid or expired.',
                'forgot_password_help' => 'Provide your username and e-mail address to send a secure reset link.',
                'reset_password_help' => 'Set a new password for the administrator account.',
                'new_password' => 'New password',
                'repeat_password' => 'Repeat new password',
                'save_new_password' => 'Save new password',
                'passwords_mismatch' => 'Passwords do not match.',
                'reset_completed' => 'Password changed. You can sign in now.',
                'password_too_short' => 'Password must be at least 12 characters long.',
                'password_policy_error' => 'Password must include uppercase, lowercase, a digit and a special character.',
            ],
        ];
        $lang = isset($dict[$locale]) ? $locale : 'pl';
        return static fn(string $key): string => $dict[$lang][$key] ?? $key;
    }
}
