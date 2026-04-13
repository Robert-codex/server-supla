<?php
namespace SuplaBundle\EventListener;

use SuplaBundle\Security\AdminPanelAccountStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class AdminPanelBruteForceListener {
    public function __construct(private AdminPanelAccountStore $store) {
    }

    public function onKernelRequest(GetResponseEvent $event): void {
        $request = $event->getRequest();
        if (!$request->isMethod('POST')) {
            return;
        }

        $path = (string)$request->getPathInfo();
        if ($path !== '/admin/login' && $path !== '/admin/2fa') {
            return;
        }

        $remaining = $this->store->getLoginBlockRemainingSeconds($request->getClientIp());
        if ($remaining <= 0) {
            return;
        }

        $response = new Response(
            'Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za ' . $remaining . ' s.',
            429,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => (string)$remaining]
        );
        $event->setResponse($response);
    }
}
