<?php

namespace SuplaBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\Security\AdminPanelAccountStore;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class AdminAuthController extends Controller {
    private const LOCALE_COOKIE = 'supla_admin_locale';

    /**
     * @Route("/admin/login", name="admin_login", methods={"GET","POST"})
     */
    public function loginAction(Request $request, AdminPanelAccountStore $store): Response {
        if ($this->getUser()) {
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

        $html = '<!doctype html><html><head><meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>' . $escape($tr('login_title')) . '</title>'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f7f7f8;min-height:100vh;display:flex;align-items:center;justify-content:center;}'
            . '.card{width:min(440px,92vw);background:#fff;border:1px solid #e2e2e2;border-radius:14px;padding:18px 18px 16px 18px;box-shadow:0 6px 20px rgba(0,0,0,.06);}'
            . '.top{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;}'
            . 'h1{font-size:18px;margin:0 0 6px 0;}'
            . 'label{display:block;font-size:12px;color:#444;margin:10px 0 6px 0;}'
            . 'input,button{font:inherit;padding:10px 12px;border:1px solid #ccc;border-radius:10px;width:100%;box-sizing:border-box;}'
            . '.password-row{position:relative;}'
            . '.toggle-pass{position:absolute;right:8px;top:8px;width:auto;padding:6px 8px;font-size:12px;border-radius:8px;background:#fff;color:#333;}'
            . 'button.submit{margin-top:14px;background:#0b7a3a;border-color:#0b7a3a;color:#fff;cursor:pointer;}'
            . '.err{background:#fdecee;color:#b00020;border:1px solid #f2b8bf;padding:10px 12px;border-radius:10px;margin:10px 0 0 0;font-size:13px;}'
            . '.muted{margin-top:12px;color:#666;font-size:12px;line-height:1.4;}'
            . '.hint{margin-top:10px;background:#fafafa;border:1px solid #eee;padding:10px 12px;border-radius:10px;font-size:12px;color:#555;}'
            . 'a{color:#0b7a3a;text-decoration:none;}a:hover{text-decoration:underline;}'
            . '</style></head><body><div class="card">'
            . '<div class="top"><div><h1>SUPLA Admin</h1></div><div><a href="/admin/login?lang=pl"' . ($locale === 'pl' ? ' style="font-weight:700;"' : '') . '>PL</a> | <a href="/admin/login?lang=en"' . ($locale === 'en' ? ' style="font-weight:700;"' : '') . '>EN</a></div></div>'
            . '<div class="muted" style="margin-top:0;">' . $escape($tr('separate_account')) . '</div>'
            . '<form method="post" action="/admin/login">'
            . '<input type="hidden" name="_csrf_token" value="' . $escape($csrfToken) . '" />'
            . '<label>' . $escape($tr('username')) . '</label><input name="_username" type="text" autocomplete="username" value="' . $escape($lastUsername) . '" required />'
            . '<label>' . $escape($tr('password')) . '</label><div class="password-row"><input id="admin-password" name="_password" type="password" autocomplete="current-password" required /><button type="button" class="toggle-pass" data-target="admin-password">' . $escape($tr('show_password')) . '</button></div>'
            . '<button type="submit" class="submit">' . $escape($tr('sign_in')) . '</button>'
            . '</form>'
            . '<div class="muted"><a href="/admin/forgot-password">' . $escape($tr('forgot_password')) . '</a></div>';

        if ($error !== '') {
            $html .= '<div class="err">' . $escape($error) . '</div>';
        }
        if ($lastLogin !== '') {
            $html .= '<div class="hint">' . $escape(sprintf($tr('last_login'), $lastLogin)) . '</div>';
        }
        $html .= '<script>(function(){document.addEventListener("click",function(event){var button=event.target.closest(".toggle-pass");if(!button){return;}var input=document.getElementById(button.getAttribute("data-target"));if(!input){return;}var shown=input.type==="text";input.type=shown?"password":"text";button.textContent=shown?' . json_encode($tr('show_password'), JSON_UNESCAPED_UNICODE) . ':' . json_encode($tr('hide_password'), JSON_UNESCAPED_UNICODE) . ';});})();</script>'
            . '</div></body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/forgot-password", name="admin_forgot_password", methods={"GET","POST"})
     */
    public function forgotPasswordAction(Request $request, AdminPanelAccountStore $store): Response {
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
                if (@mail($email, $subject, $body)) {
                    $msg = $tr('reset_sent');
                } else {
                    $err = $tr('reset_send_failed');
                }
                $store->audit('admin_password_reset_requested', ['admin' => $username, 'email' => $email, 'ip' => $request->getClientIp()]);
            } catch (\Throwable $e) {
                $err = $tr('reset_generic_error');
            }
        }

        $html = '<!doctype html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>' . $escape($tr('forgot_password')) . '</title><style>'
            . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f7f7f8;min-height:100vh;display:flex;align-items:center;justify-content:center;}'
            . '.card{width:min(440px,92vw);background:#fff;border:1px solid #e2e2e2;border-radius:14px;padding:18px;box-shadow:0 6px 20px rgba(0,0,0,.06);}'
            . 'label{display:block;font-size:12px;color:#444;margin:10px 0 6px 0;} input,button{font:inherit;padding:10px 12px;border:1px solid #ccc;border-radius:10px;width:100%;box-sizing:border-box;} button{margin-top:14px;background:#0b7a3a;border-color:#0b7a3a;color:#fff;cursor:pointer;} .notice{padding:10px 12px;border-radius:10px;margin-top:10px;font-size:13px;} .ok{background:#e7f6ee;color:#0b7a3a;border:1px solid #bfe8cf;} .bad{background:#fdecee;color:#b00020;border:1px solid #f2b8bf;} a{color:#0b7a3a;text-decoration:none;}'
            . '</style></head><body><div class="card"><div style="display:flex;justify-content:space-between;"><b>SUPLA Admin</b><div><a href="/admin/forgot-password?lang=pl">PL</a> | <a href="/admin/forgot-password?lang=en">EN</a></div></div><h1 style="font-size:18px;">' . $escape($tr('forgot_password')) . '</h1>'
            . '<form method="post"><label>' . $escape($tr('username')) . '</label><input name="username" required /><label>' . $escape($tr('email')) . '</label><input name="email" type="email" required /><button type="submit">' . $escape($tr('send_reset')) . '</button></form>'
            . '<div class="muted" style="margin-top:10px;font-size:12px;"><a href="/admin/login">' . $escape($tr('back_to_login')) . '</a></div>';
        if ($msg !== '') {
            $html .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $html .= '<div class="notice bad">' . $escape($err) . '</div>';
        }
        $html .= '</div></body></html>';
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

        $html = '<!doctype html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>' . $escape($tr('reset_password')) . '</title><style>'
            . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f7f7f8;min-height:100vh;display:flex;align-items:center;justify-content:center;}'
            . '.card{width:min(440px,92vw);background:#fff;border:1px solid #e2e2e2;border-radius:14px;padding:18px;box-shadow:0 6px 20px rgba(0,0,0,.06);} label{display:block;font-size:12px;color:#444;margin:10px 0 6px 0;} input,button{font:inherit;padding:10px 12px;border:1px solid #ccc;border-radius:10px;width:100%;box-sizing:border-box;} button{margin-top:14px;background:#0b7a3a;border-color:#0b7a3a;color:#fff;cursor:pointer;} .notice{padding:10px 12px;border-radius:10px;margin-top:10px;font-size:13px;} .ok{background:#e7f6ee;color:#0b7a3a;border:1px solid #bfe8cf;} .bad{background:#fdecee;color:#b00020;border:1px solid #f2b8bf;} a{color:#0b7a3a;text-decoration:none;}'
            . '</style></head><body><div class="card"><h1 style="font-size:18px;">' . $escape($tr('reset_password')) . '</h1>';
        if ($msg !== '') {
            $html .= '<div class="notice ok">' . $escape($msg) . '</div><div class="muted" style="margin-top:10px;font-size:12px;"><a href="/admin/login">' . $escape($tr('back_to_login')) . '</a></div>';
        } elseif (!$account) {
            $html .= '<div class="notice bad">' . $escape($tr('reset_token_invalid')) . '</div>';
        } else {
            $html .= '<form method="post"><input type="hidden" name="token" value="' . $escape($token) . '" /><label>' . $escape($tr('new_password')) . '</label><input type="password" name="newPassword" required /><label>' . $escape($tr('repeat_password')) . '</label><input type="password" name="newPassword2" required /><button type="submit">' . $escape($tr('save_new_password')) . '</button></form>';
        }
        if ($err !== '') {
            $html .= '<div class="notice bad">' . $escape($err) . '</div>';
        }
        $html .= '</div></body></html>';
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
