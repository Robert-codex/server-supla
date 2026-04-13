<?php
namespace SuplaBundle\EventListener;

use SuplaBundle\Security\AdminPanelAccountStore;
use SuplaBundle\Security\AdminPanelUser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AdminPanelAuthAuditListener {
    public function __construct(
        private AdminPanelAccountStore $store,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage
    ) {
    }

    public function onInteractiveAuthenticationSuccess(): void {
        $req = $this->requestStack->getCurrentRequest();
        if (!$req || strpos((string)$req->getPathInfo(), '/admin') !== 0) {
            return;
        }
        $token = $this->tokenStorage->getToken();
        $user = $token ? $token->getUser() : null;
        if (!$user instanceof AdminPanelUser) {
            return;
        }
        $this->store->clearFailedLoginAttempts($req->getClientIp());
        $this->store->audit('admin_login_success', [
            'admin' => $user->getUsername(),
            'ip' => $req ? $req->getClientIp() : null,
            'ua' => $req ? (string)$req->headers->get('User-Agent', '') : '',
        ]);
        AdminPanelTwoFactorEnforcer::clear($req ? $req->getSession() : null);
    }

    public function onInteractiveAuthenticationFailure(): void {
        $req = $this->requestStack->getCurrentRequest();
        if (!$req || strpos((string)$req->getPathInfo(), '/admin') !== 0) {
            return;
        }
        $blockedUntil = $this->store->registerFailedLoginAttempt($req->getClientIp());
        $this->store->audit('admin_login_failure', [
            'ip' => $req ? $req->getClientIp() : null,
            'ua' => $req ? (string)$req->headers->get('User-Agent', '') : '',
            'blockedUntil' => $blockedUntil > 0 ? date(DATE_ATOM, $blockedUntil) : null,
        ]);
    }
}
