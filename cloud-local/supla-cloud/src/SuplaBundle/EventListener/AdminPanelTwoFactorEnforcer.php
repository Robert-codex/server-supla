<?php
namespace SuplaBundle\EventListener;

use SuplaBundle\Security\AdminPanelAccountStore;
use SuplaBundle\Security\AdminPanelUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AdminPanelTwoFactorEnforcer {
    private const SESSION_KEY = 'supla_admin_2fa_passed';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AdminPanelAccountStore $store
    ) {
    }

    public function onKernelRequest(GetResponseEvent $event): void {
        $request = $event->getRequest();
        $path = (string)$request->getPathInfo();
        if (strpos($path, '/admin') !== 0) {
            return;
        }
        if ($path === '/admin/login' || $path === '/admin/logout' || $path === '/admin/2fa') {
            return;
        }
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }
        $user = $token->getUser();
        if (!$user instanceof AdminPanelUser) {
            return;
        }
        if (!$this->store->isTwoFactorEnabled()) {
            return;
        }
        $session = $request->getSession();
        if ($session && $session->get(self::SESSION_KEY, false)) {
            return;
        }
        $event->setResponse(new RedirectResponse('/admin/2fa'));
    }

    public static function markPassed($session): void {
        if ($session) {
            $session->set(self::SESSION_KEY, true);
        }
    }

    public static function clear($session): void {
        if ($session) {
            $session->remove(self::SESSION_KEY);
        }
    }
}

