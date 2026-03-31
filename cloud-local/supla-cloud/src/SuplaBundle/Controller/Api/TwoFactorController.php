<?php

namespace SuplaBundle\Controller\Api;

use Assert\Assertion;
use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use SuplaBundle\EventListener\UnavailableInMaintenance;
use SuplaBundle\Model\Transactional;
use SuplaBundle\Model\TwoFactorService;
use SuplaBundle\Model\UserManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorController extends RestController {
    use Transactional;

    private UserManager $userManager;
    private TwoFactorService $twoFactorService;

    public function __construct(UserManager $userManager, TwoFactorService $twoFactorService) {
        $this->userManager = $userManager;
        $this->twoFactorService = $twoFactorService;
    }

    /**
     * @Rest\Post("/two-factor/setup")
     * @Security("is_granted('ROLE_ACCOUNT_RW')")
     * @UnavailableInMaintenance
     */
    public function setupAction() {
        $user = $this->getUser();
        Assertion::false($this->twoFactorService->isEnabled($user), 'Two-factor authentication is already enabled.');

        $payload = $this->transactional(function (EntityManagerInterface $entityManager) use ($user) {
            $payload = $this->twoFactorService->beginSetup($user, 'SUPLA Cloud');
            $entityManager->persist($user);
            return $payload;
        });

        return $this->view($payload, Response::HTTP_OK);
    }

    /**
     * @Rest\Post("/two-factor/confirm")
     * @Security("is_granted('ROLE_ACCOUNT_RW')")
     * @UnavailableInMaintenance
     */
    public function confirmAction(Request $request) {
        $data = $request->request->all();
        $user = $this->getUser();
        $password = trim((string)($data['password'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));

        Assertion::true($this->userManager->isPasswordValid($user, $password), 'Current password is incorrect');

        $recoveryCodes = $this->transactional(function (EntityManagerInterface $entityManager) use ($user, $code) {
            $recoveryCodes = $this->twoFactorService->confirmSetup($user, $code);
            $entityManager->persist($user);
            return $recoveryCodes;
        });

        return $this->view([
            'recoveryCodes' => $recoveryCodes,
            'twoFactor' => $this->twoFactorService->getPublicState($user),
        ], Response::HTTP_OK);
    }

    /**
     * @Rest\Post("/two-factor/disable")
     * @Security("is_granted('ROLE_ACCOUNT_RW')")
     * @UnavailableInMaintenance
     */
    public function disableAction(Request $request) {
        $data = $request->request->all();
        $user = $this->getUser();
        $password = trim((string)($data['password'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        $recoveryCode = trim((string)($data['recoveryCode'] ?? ''));

        Assertion::true($this->userManager->isPasswordValid($user, $password), 'Current password is incorrect');
        Assertion::true($this->twoFactorService->isEnabled($user), 'Two-factor authentication is not enabled.');
        Assertion::true(
            ($code && $this->twoFactorService->verifyCode($user, $code))
            || ($recoveryCode && $this->twoFactorService->consumeRecoveryCode($user, $recoveryCode)),
            'Invalid two-factor authentication code.'
        );

        $this->transactional(function (EntityManagerInterface $entityManager) use ($user) {
            $this->twoFactorService->disable($user);
            $entityManager->persist($user);
            return null;
        });

        return $this->view(['twoFactor' => $this->twoFactorService->getPublicState($user)], Response::HTTP_OK);
    }

    /**
     * @Rest\Post("/two-factor/recovery-codes")
     * @Security("is_granted('ROLE_ACCOUNT_RW')")
     * @UnavailableInMaintenance
     */
    public function regenerateRecoveryCodesAction(Request $request) {
        $data = $request->request->all();
        $user = $this->getUser();
        $password = trim((string)($data['password'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        $recoveryCode = trim((string)($data['recoveryCode'] ?? ''));

        Assertion::true($this->userManager->isPasswordValid($user, $password), 'Current password is incorrect');
        Assertion::true($this->twoFactorService->isEnabled($user), 'Two-factor authentication is not enabled.');
        Assertion::true(
            ($code && $this->twoFactorService->verifyCode($user, $code))
            || ($recoveryCode && $this->twoFactorService->consumeRecoveryCode($user, $recoveryCode)),
            'Invalid two-factor authentication code.'
        );

        $recoveryCodes = $this->transactional(function (EntityManagerInterface $entityManager) use ($user) {
            $recoveryCodes = $this->twoFactorService->regenerateRecoveryCodes($user);
            $entityManager->persist($user);
            return $recoveryCodes;
        });

        return $this->view([
            'recoveryCodes' => $recoveryCodes,
            'twoFactor' => $this->twoFactorService->getPublicState($user),
        ], Response::HTTP_OK);
    }
}
