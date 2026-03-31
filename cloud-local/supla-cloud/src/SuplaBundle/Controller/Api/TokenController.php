<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Controller\Api;

use Assert\Assertion;
use FOS\RestBundle\Controller\Annotations as Rest;
use OAuth2\OAuth2;
use OAuth2\OAuth2ServerException;
use OpenApi\Annotations as OA;
use SuplaBundle\Auth\OAuthScope;
use SuplaBundle\Auth\UserLoginAttemptListener;
use SuplaBundle\Auth\SuplaOAuth2;
use SuplaBundle\Enums\AuthenticationFailureReason;
use SuplaBundle\Model\Audit\FailedAuthAttemptsUserBlocker;
use SuplaBundle\Model\LoginChallengeTokenService;
use SuplaBundle\Model\TargetSuplaCloudRequestForwarder;
use SuplaBundle\Model\TwoFactorService;
use SuplaBundle\Model\UserManager;
use SuplaBundle\Repository\ApiClientRepository;
use SuplaBundle\Repository\UserRepository;
use SuplaBundle\Supla\SuplaAutodiscover;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Idea of issuing tokens without client & secret taken from the gist: https://gist.github.com/johnpancoast/359bad0255cb50ccd6ab13e4ac18e4e8
 */
class TokenController extends RestController {
    /** @var SuplaOAuth2 */
    private $server;
    /** @var RouterInterface */
    private $router;
    /** @var ApiClientRepository */
    private $apiClientRepository;
    /** @var FailedAuthAttemptsUserBlocker */
    private $failedAuthAttemptsUserBlocker;
    /** @var SuplaAutodiscover */
    private $autodiscover;
    /** @var TargetSuplaCloudRequestForwarder */
    private $suplaCloudRequestForwarder;
    /** @var UserRepository */
    private $userRepository;
    private UserManager $userManager;
    private TwoFactorService $twoFactorService;
    private LoginChallengeTokenService $loginChallengeTokenService;
    private UserLoginAttemptListener $userLoginAttemptListener;

    public function __construct(
        SuplaOAuth2 $server,
        RouterInterface $router,
        ApiClientRepository $apiClientRepository,
        FailedAuthAttemptsUserBlocker $failedAuthAttemptsUserBlocker,
        SuplaAutodiscover $autodiscover,
        TokenStorageInterface $tokenStorage,
        TargetSuplaCloudRequestForwarder $suplaCloudRequestForwarder,
        UserRepository $userRepository,
        UserManager $userManager,
        TwoFactorService $twoFactorService,
        LoginChallengeTokenService $loginChallengeTokenService,
        UserLoginAttemptListener $userLoginAttemptListener
    ) {
        $this->server = $server;
        $this->router = $router;
        $this->apiClientRepository = $apiClientRepository;
        $this->failedAuthAttemptsUserBlocker = $failedAuthAttemptsUserBlocker;
        $this->autodiscover = $autodiscover;
        $this->tokenStorage = $tokenStorage;
        $this->suplaCloudRequestForwarder = $suplaCloudRequestForwarder;
        $this->userRepository = $userRepository;
        $this->userManager = $userManager;
        $this->twoFactorService = $twoFactorService;
        $this->loginChallengeTokenService = $loginChallengeTokenService;
        $this->userLoginAttemptListener = $userLoginAttemptListener;
    }

    /** @Rest\Post("/webapp-auth") */
    public function webappAuthAction(Request $request) {
        $username = $request->get('username');
        $password = $request->get('password');
        if (!$username || !$password) {
            return $this->view(
                ['error' => OAuth2::ERROR_INVALID_GRANT, 'error_description' => 'Invalid username and password combination'],
                Response::HTTP_UNAUTHORIZED
            );
        }
        $server = $this->autodiscover->getAuthServerForUser($username);
        if ($server->isLocal()) {
            $user = $this->userRepository->findOneByEmail($username);
            if ($username && $this->failedAuthAttemptsUserBlocker->isAuthenticationFailureLimitExceeded($username)) {
                return $this->view(
                    ['error' => 'locked', 'error_description' => 'Your account has been blocked for a while.'],
                    Response::HTTP_TOO_MANY_REQUESTS
                );
            }
            if ($user && !$user->isEnabled()) {
                return $this->view(
                    ['error' => 'disabled', 'error_description' => 'Your account has not been confirmed.'],
                    Response::HTTP_CONFLICT
                );
            }
            if ($user && $this->twoFactorService->isEnabled($user) && $this->userManager->isPasswordValid($user, $password)) {
                return $this->view([
                    'error' => 'two_factor_required',
                    'challengeToken' => $this->loginChallengeTokenService->issue($user, $password),
                    'expiresIn' => $this->loginChallengeTokenService->getTtl(),
                ], Response::HTTP_UNAUTHORIZED);
            }
            return $this->issueTokenForWebappAction($request);
        } else {
            [$response, $status] = $this->suplaCloudRequestForwarder->issueWebappToken($server, $username, $password);
            return $this->view($response, $status);
        }
    }

    /** @Rest\Post("/webapp-two-factor") */
    public function verifyWebappTwoFactorAction(Request $request) {
        $challengeToken = trim((string)$request->get('challengeToken'));
        $code = trim((string)$request->get('code'));
        $recoveryCode = trim((string)$request->get('recoveryCode'));

        if (!$challengeToken || (!$code && !$recoveryCode)) {
            return $this->view(
                ['error' => OAuth2::ERROR_INVALID_GRANT, 'error_description' => 'Invalid two-factor authentication request'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        try {
            $payload = $this->loginChallengeTokenService->consume($challengeToken);
        } catch (\RuntimeException $e) {
            return $this->view(['error' => 'two_factor_challenge_invalid'], Response::HTTP_CONFLICT);
        }

        $username = $payload['username'] ?? '';
        $password = $payload['password'] ?? '';
        $user = $username ? $this->userRepository->findOneByEmail($username) : null;
        if (!$user || !$user->isEnabled() || !$this->twoFactorService->isEnabled($user)) {
            return $this->view(['error' => 'two_factor_challenge_invalid'], Response::HTTP_CONFLICT);
        }
        if ($this->loginChallengeTokenService->isExpired($payload) || !$this->loginChallengeTokenService->matchesUser($payload, $user)) {
            return $this->view(['error' => 'two_factor_challenge_invalid'], Response::HTTP_CONFLICT);
        }
        if ($this->failedAuthAttemptsUserBlocker->isAuthenticationFailureLimitExceeded($username)) {
            return $this->view(
                ['error' => 'locked', 'error_description' => 'Your account has been blocked for a while.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $valid = $code
            ? $this->twoFactorService->verifyCode($user, $code)
            : $this->twoFactorService->consumeRecoveryCode($user, $recoveryCode);
        if (!$valid) {
            $this->userLoginAttemptListener->onAuthenticationFailure($username, AuthenticationFailureReason::BAD_CREDENTIALS());
            return $this->view(
                ['error' => 'invalid_two_factor_code', 'error_description' => 'Invalid two-factor authentication code'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $tokenRequest = Request::create('/api/webapp-tokens', 'POST', [
            'username' => $username,
            'password' => $password,
        ]);

        return $this->issueTokenForWebappAction($tokenRequest);
    }

    /**
     * @Rest\Post("webapp-tokens")
     */
    public function issueTokenForWebappAction(Request $request) {
        $webappClient = $this->apiClientRepository->getWebappClient();
        $grantType = $request->get('grant_type', 'password');
        $requestData = [
            'client_id' => $webappClient->getPublicId(),
            'client_secret' => $webappClient->getSecret(),
            'grant_type' => $grantType,
            'scope' => (string)OAuthScope::getScopeForWebappToken(),
        ];
        $requestData = array_merge($requestData, [
            'username' => $request->get('username'),
            'password' => $request->get('password'),
        ]);
        Assertion::notBlank($requestData['username'], 'Please enter a valid email address'); // i18n
        Assertion::notEmpty($requestData['password'], 'The password should be 8 or more characters.'); // i18n
        $tokenRequest = Request::create($this->router->generate('fos_oauth_server_token'), 'POST', $requestData);
        try {
            return $this->server->grantAccessToken($tokenRequest);
        } catch (OAuth2ServerException $e) {
            $username = $request->get('username');
            $user = $this->userRepository->findOneByEmail($username);
            if ($username && $this->failedAuthAttemptsUserBlocker->isAuthenticationFailureLimitExceeded($username)) {
                return $this->view(
                    ['error' => 'locked', 'error_description' => 'Your account has been blocked for a while.'],
                    Response::HTTP_TOO_MANY_REQUESTS
                );
            } elseif ($user && !$user->isEnabled()) {
                return $this->view(
                    ['error' => 'disabled', 'error_description' => 'Your account has not been confirmed.'],
                    Response::HTTP_CONFLICT
                );
            } else {
                return $e->getHttpResponse()->setStatusCode(401);
            }
        }
    }

    /**
     * @OA\Get(
     *     path="/token-info",
     *     operationId="getTokenInfo",
     *     summary="Returns information about used access token",
     *     tags={"Server"},
     *     @OA\Response(response="200", description="Success", @OA\JsonContent(
     *       @OA\Property(property="userShortUniqueId", type="string"),
     *       @OA\Property(property="scope", type="string"),
     *       @OA\Property(property="expiresAt", type="integer"),
     *     ))
     * )
     * @Rest\Get("/token-info")
     */
    public function tokenInfoAction() {
        $token = $this->tokenStorage->getToken()->getCredentials();
        $accessToken = $this->server->getStorage()->getAccessToken($token);
        return $this->view([
            'userShortUniqueId' => $this->getUser()->getShortUniqueId(),
            'scope' => $accessToken->getScope(),
            'expiresAt' => $accessToken->getExpiresAt(),
        ]);
    }
}
