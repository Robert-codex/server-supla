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

namespace SuplaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\Entity\EntityUtils;
use SuplaBundle\Entity\Main\User;
use SuplaBundle\EventListener\ApiRateLimit\ApiRateLimitStorage;
use SuplaBundle\Model\AccessIdManager;
use SuplaBundle\Model\LocationManager;
use SuplaBundle\Model\UserManager;
use SuplaBundle\Security\AdminPanelAccountStore;
use SuplaBundle\Security\AdminPanelUser;
use SuplaBundle\Security\RegistrationBlockStore;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Admin panel for user management.
 *
 * Auth: Symfony session (form_login) using SUPLA user credentials.
 * Access is additionally restricted by env var ADMIN_PANEL_ALLOWED_EMAILS (comma-separated).
 */
class AdminUsersController extends Controller {
    use AdminUiTrait;

    private const PER_PAGE = 50;
    private const LOCALE_COOKIE = 'supla_admin_locale';
    private const PASSWORD_RESET_COOLDOWN_SECONDS = 600;
    private const PREF_BLOCKED_UNTIL = 'admin.blockedUntil';
    private const PREF_BLOCK_PROFILE = 'admin.blockProfile';
    private const PREF_BLOCK_HISTORY = 'admin.blockHistory';
    private const PREF_BLOCK_SCHEDULE = 'admin.blockSchedule';
    private const PREF_PREV_MQTT_ENABLED = 'admin.prevMqttBrokerEnabled';
    private const PREF_PENDING_LIMITS = 'admin.pendingLimits';
    private const PREF_LIMITS_SELF_UPDATE_LOCKED = 'admin.limitsSelfUpdateLocked';
    private const BLOCK_SCOPE_WWW = 'www';
    private const BLOCK_SCOPE_API = 'api';
    private const BLOCK_SCOPE_MQTT = 'mqtt';
    private const BLOCK_SCOPES = [self::BLOCK_SCOPE_WWW, self::BLOCK_SCOPE_API, self::BLOCK_SCOPE_MQTT];
    private const CURRENT_USER_LIMIT_FIELDS = [
        'accessId' => 'limitAid',
        'actionsPerSchedule' => 'limitActionsPerSchedule',
        'channelGroup' => 'limitChannelGroup',
        'channelPerGroup' => 'limitChannelPerGroup',
        'clientApp' => 'limitClientApp',
        'directLink' => 'limitDirectLink',
        'ioDevice' => 'limitIoDev',
        'location' => 'limitLoc',
        'oauthClient' => 'limitOAuthClient',
        'operationsPerScene' => 'limitOperationsPerScene',
        'pushNotifications' => 'limitPushNotifications',
        'pushNotificationsPerHour' => 'limitPushNotificationsPerHour',
        'scene' => 'limitScene',
        'schedule' => 'limitSchedule',
        'valueBasedTriggers' => 'limitValueBasedTriggers',
        'virtualChannels' => 'limitVirtualChannels',
    ];

    /**
     * @Route("/admin", methods={"GET"})
     * @Route("/admin/dashboard", methods={"GET"})
     */
    public function dashboardAction(Request $request, EntityManagerInterface $em, AdminPanelAccountStore $store, RegistrationBlockStore $registrationBlockStore): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }

        if ($request->query->has('lang')) {
            $lang = strtolower(substr((string)$request->query->get('lang'), 0, 2));
            if (!in_array($lang, ['pl', 'en'], true)) {
                $lang = 'pl';
            }
            $params = $request->query->all();
            unset($params['lang']);
            $qs = http_build_query($params);
            $resp = new RedirectResponse('/admin/dashboard' . ($qs ? ('?' . $qs) : ''));
            $resp->headers->setCookie(new Cookie(self::LOCALE_COOKIE, $lang, time() + 3600 * 24 * 365, '/', null, $request->isSecure(), true, false, 'Lax'));
            return $resp;
        }

        $locale = $this->getAdminLocale($request);
        $msg = (string)$request->query->get('msg', '');
        $err = (string)$request->query->get('err', '');
        $searchQuery = trim((string)$request->query->get('q', ''));

        $stats = [
            'total' => (int)$em->getRepository(User::class)->createQueryBuilder('u')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult(),
            'enabled' => (int)$em->getRepository(User::class)->createQueryBuilder('u')->select('COUNT(u.id)')->andWhere('u.enabled = 1')->getQuery()->getSingleScalarResult(),
            'disabled' => (int)$em->getRepository(User::class)->createQueryBuilder('u')->select('COUNT(u.id)')->andWhere('u.enabled = 0')->getQuery()->getSingleScalarResult(),
        ];

        /** @var User[] $recentUsers */
        $recentUsers = $em->getRepository(User::class)->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        /** @var User[] $pendingUsers */
        $pendingUsers = $em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.enabled = 0')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        /** @var User[] $usersForAudit */
        $usersForAudit = $em->getRepository(User::class)->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $blockedCount = 0;
        $pendingCount = 0;
        $blockedUsers = [];
        $pendingLimitUsers = [];
        $problemUsers = [];
        foreach ($usersForAudit as $user) {
            $blockSummary = $this->getUserBlockSummary($user);
            if ($blockSummary['active']) {
                $blockedCount++;
                if (count($blockedUsers) < 8) {
                    $blockedUsers[] = [
                        'user' => $user,
                        'blockedUntil' => $blockSummary['until'],
                        'reason' => $blockSummary['reason'],
                        'scopes' => $blockSummary['scopes'],
                    ];
                }
            }
            $pendingLimits = $user->getPreference(self::PREF_PENDING_LIMITS, null);
            if (is_array($pendingLimits)) {
                $pendingCount++;
                if (count($pendingLimitUsers) < 8) {
                    $pendingLimitUsers[] = ['user' => $user, 'pending' => $pendingLimits];
                }
            }
            $problems = $this->detectUserProblems($user);
            if ($problems) {
                $problemUsers[] = ['user' => $user, 'problems' => $problems];
            }
            if (count($problemUsers) >= 8) {
                break;
            }
        }

        $stats['blocked'] = $blockedCount;
        $stats['pendingLimits'] = $pendingCount;
        $securityEvents = $store->getAuditTail(10);
        $registrationSeries = $this->buildRecentRegistrationSeries($em, 30);
        $authFailureSeries = $this->buildRecentAdminAuthFailureSeries($store, 30);
        $registrationState = $registrationBlockStore->getState();
        $alerts = $this->buildDashboardAlerts($stats, $blockedUsers, $pendingLimitUsers, $problemUsers, $locale);

        $html = $this->renderDashboardHtml($stats, $recentUsers, $pendingUsers, $blockedUsers, $pendingLimitUsers, $problemUsers, $securityEvents, $registrationSeries, $authFailureSeries, $registrationState, $alerts, $msg, $err, $locale, $searchQuery);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/users", methods={"GET"})
     */
    public function listAction(Request $request, EntityManagerInterface $em, RegistrationBlockStore $registrationBlockStore): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }

        if ($request->query->has('lang')) {
            $lang = strtolower(substr((string)$request->query->get('lang'), 0, 2));
            if (!in_array($lang, ['pl', 'en'], true)) {
                $lang = 'pl';
            }
            $params = $request->query->all();
            unset($params['lang']);
            $qs = http_build_query($params);
            $resp = new RedirectResponse('/admin/users' . ($qs ? ('?' . $qs) : ''));
            $resp->headers->setCookie(new Cookie(self::LOCALE_COOKIE, $lang, time() + 3600 * 24 * 365, '/', null, $request->isSecure(), true, false, 'Lax'));
            return $resp;
        }

        $page = max(1, (int)$request->query->get('page', 1));
        $query = trim((string)$request->query->get('q', ''));
        $enabledFilter = (string)$request->query->get('enabled', '');
        $msg = (string)$request->query->get('msg', '');
        $err = (string)$request->query->get('err', '');

        $qb = $em->getRepository(User::class)->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC');
        if ($query !== '') {
            $normalizedCode = strtoupper(trim($query));
            $idFromCode = null;
            if (preg_match('/^USR-(\d{1,10})$/', $normalizedCode, $match)) {
                $idFromCode = (int)$match[1];
            }
            if ($idFromCode !== null) {
                $qb->andWhere('u.id = :userId OR LOWER(u.email) LIKE :q')
                    ->setParameter('userId', $idFromCode)
                    ->setParameter('q', '%' . strtolower($query) . '%');
            } else {
                $qb->andWhere('LOWER(u.email) LIKE :q')->setParameter('q', '%' . strtolower($query) . '%');
            }
        }
        if ($enabledFilter === '1' || $enabledFilter === '0') {
            $qb->andWhere('u.enabled = :en')->setParameter('en', $enabledFilter === '1');
        }
        $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE + 1);

        /** @var User[] $users */
        $users = $qb->getQuery()->getResult();
        $hasNext = count($users) > self::PER_PAGE;
        if ($hasNext) {
            array_pop($users);
        }

        $locale = $this->getAdminLocale($request);
        $registrationState = $registrationBlockStore->getState();
        $html = $this->renderUsersHtml($request, $users, $page, $hasNext, $query, $enabledFilter, $msg, $err, $locale, $registrationState);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/users/{id}", requirements={"id"="\d+"}, methods={"GET"})
     */
    public function detailsAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return new RedirectResponse('/admin/users?err=' . urlencode('User not found.'));
        }

        $msg = (string)$request->query->get('msg', '');
        $err = (string)$request->query->get('err', '');
        $html = $this->renderUserDetailsHtml($user, $msg, $err, $this->getAdminLocale($request));
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @Route("/admin/users/{id}/reset-password", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function resetPasswordAction(Request $request, int $id, EntityManagerInterface $em, UserManager $userManager, AdminPanelAccountStore $store): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_reset_password_' . $id)) {
            return $response;
        }

        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', $tr('user_not_found'));
        }

        $email = trim((string)$user->getEmail());
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirectWith($request, 'err', $tr('invalid_email_address'));
        }
        $force = $this->isGranted('ROLE_ADMIN_SUPER') && $request->request->getBoolean('force');
        if ($remaining = $this->getPasswordResetCooldownRemaining($store, $id)) {
            if ($force) {
                $store->audit('admin_user_password_reset_cooldown_overridden', [
                    'admin' => (string)($this->getUser() instanceof AdminPanelUser ? $this->getUser()->getUsername() : ''),
                    'userId' => $id,
                    'email' => $email,
                    'ip' => $request->getClientIp(),
                ]);
            } else {
                return $this->redirectWith($request, 'err', sprintf($tr('password_reset_link_rate_limited'), (int)ceil($remaining / 60)));
            }
        }
        if ($force && !$this->isGranted('ROLE_ADMIN_SUPER')) {
            return $this->redirectWith($request, 'err', $tr('password_reset_link_failed'));
        }
        if ($force && $remaining <= 0) {
            // no-op, same flow as normal send
        }

        try {
            $userManager->passwordResetRequest($user);
            $store->audit('admin_user_password_reset_requested', [
                'admin' => (string)($this->getUser() instanceof AdminPanelUser ? $this->getUser()->getUsername() : ''),
                'userId' => $id,
                'email' => $email,
                'ip' => $request->getClientIp(),
                'force' => $force,
            ]);
        } catch (\Throwable $e) {
            return $this->redirectWith($request, 'err', $tr('password_reset_link_failed'));
        }

        return $this->redirectWith($request, 'msg', $tr('password_reset_link_sent'));
    }

    /**
     * @Route("/admin/users/{id}/toggle-enabled", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function toggleEnabledAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_toggle_enabled_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }
        $user->setEnabled(!$user->isEnabled());
        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'User updated.');
    }

    /**
     * Confirms a newly registered user (bypasses e-mail activation).
     *
     * @Route("/admin/users/{id}/confirm", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function confirmAction(
        Request $request,
        int $id,
        EntityManagerInterface $em,
        AccessIdManager $accessIdManager,
        LocationManager $locationManager
    ): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_confirm_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }
        if ($user->isEnabled()) {
            return $this->redirectWith($request, 'msg', 'User already confirmed.');
        }

        // Ensure initial setup: at least one Access ID, one Location, and a relation between them.
        if (method_exists($user, 'getAccessIDS') && $user->getAccessIDS()->isEmpty()) {
            $aid = $accessIdManager->createID($user);
            $em->persist($aid);
        }
        if (method_exists($user, 'getLocations') && $user->getLocations()->isEmpty()) {
            $location = $locationManager->createLocation($user);
            $em->persist($location);
        } else {
            // If user already has locations and an access id exists, but there are no relations,
            // link the first access id to all locations to avoid "no active access identifiers" on the home page.
            $firstAid = method_exists($user, 'getAccessIDS') ? $user->getAccessIDS()->first() : null;
            if ($firstAid) {
                $hasAnyRelation = false;
                foreach ($user->getLocations() as $loc) {
                    if (method_exists($loc, 'getAccessIds') && $loc->getAccessIds()->count() > 0) {
                        $hasAnyRelation = true;
                        break;
                    }
                }
                if (!$hasAnyRelation) {
                    foreach ($user->getLocations() as $loc) {
                        if (method_exists($loc, 'getAccessIds') && !$loc->getAccessIds()->contains($firstAid)) {
                            $loc->getAccessIds()->add($firstAid);
                            if (method_exists($firstAid, 'getLocations')) {
                                $firstAid->getLocations()->add($loc);
                            }
                            $em->persist($loc);
                        }
                    }
                }
            }
        }

        $user->setEnabled(true);
        if (method_exists($user, 'setToken')) {
            $user->setToken(null);
        }
        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'User confirmed.');
    }

    /**
     * @Route("/admin/users/{id}/block", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function blockAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_block_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }

        $seconds = (int)$request->request->get('seconds', 0);
        if ($seconds <= 0) {
            return $this->redirectWith($request, 'err', 'Invalid block duration.');
        }
        $scopes = $this->normalizeBlockScopes($request->request->get('scopes', self::BLOCK_SCOPES));
        if (!$scopes) {
            return $this->redirectWith($request, 'err', 'Choose at least one block scope.');
        }
        $reason = trim((string)$request->request->get('reason', ''));
        $blockedUntil = time() + $seconds;
        $blockProfile = [
            'until' => $blockedUntil,
            'scopes' => $scopes,
            'reason' => mb_substr($reason, 0, 250),
            'createdAt' => time(),
            'createdBy' => (string)($this->getUser() instanceof AdminPanelUser ? $this->getUser()->getUsername() : ''),
        ];
        $user->setPreference(self::PREF_BLOCK_PROFILE, $blockProfile);
        $user->setPreference(self::PREF_BLOCKED_UNTIL, $this->hasWebOrApiScope($scopes) ? $blockedUntil : 0);
        $this->applyMqttBlockState($user, in_array(self::BLOCK_SCOPE_MQTT, $scopes, true));
        $this->appendBlockHistoryEntry($user, [
            'event' => 'blocked',
            'ts' => time(),
            'until' => $blockedUntil,
            'reason' => $blockProfile['reason'],
            'scopes' => $scopes,
            'admin' => $blockProfile['createdBy'],
        ]);

        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'User blocked.');
    }

    /**
     * @Route("/admin/users/{id}/unblock", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function unblockAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_unblock_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }

        $previousProfile = $this->getUserBlockProfile($user);
        $previousSchedule = $this->getUserBlockSchedule($user);
        $user->setPreference(self::PREF_BLOCKED_UNTIL, 0);
        $user->setPreference(self::PREF_BLOCK_PROFILE, null);
        $user->setPreference(self::PREF_BLOCK_SCHEDULE, null);
        $this->applyMqttBlockState($user, false);
        $this->appendBlockHistoryEntry($user, [
            'event' => 'unblocked',
            'ts' => time(),
            'until' => 0,
            'reason' => (string)($previousProfile['reason'] ?? $previousSchedule['reason'] ?? ''),
            'scopes' => $previousProfile['scopes'] ?? $previousSchedule['scopes'] ?? [],
            'admin' => (string)($this->getUser() instanceof AdminPanelUser ? $this->getUser()->getUsername() : ''),
        ]);

        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'User unblocked.');
    }

    /**
     * @Route("/admin/users/{id}/block-schedule", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function blockScheduleAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_block_schedule_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }

        $days = array_map('intval', (array)$request->request->get('days', []));
        $days = array_values(array_unique(array_filter($days, static fn(int $day): bool => $day >= 1 && $day <= 7)));
        $from = trim((string)$request->request->get('fromTime', ''));
        $to = trim((string)$request->request->get('toTime', ''));
        $scopes = $this->normalizeBlockScopes($request->request->get('scopes', self::BLOCK_SCOPES));
        $reason = trim((string)$request->request->get('reason', ''));
        if (!$days || !$this->isValidTimeString($from) || !$this->isValidTimeString($to) || $from === $to) {
            return $this->redirectWith($request, 'err', 'Invalid recurring block configuration.');
        }
        if (!$scopes) {
            return $this->redirectWith($request, 'err', 'Choose at least one block scope.');
        }

        $schedule = [
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'scopes' => $scopes,
            'reason' => mb_substr($reason, 0, 250),
            'createdAt' => time(),
            'createdBy' => (string)($this->getUser() instanceof AdminPanelUser ? $this->getUser()->getUsername() : ''),
        ];
        $user->setPreference(self::PREF_BLOCK_SCHEDULE, $schedule);
        $this->applyMqttBlockState($user, $this->isScheduleBlockActiveNow($schedule) && in_array(self::BLOCK_SCOPE_MQTT, $scopes, true));
        $this->appendBlockHistoryEntry($user, [
            'event' => 'schedule_set',
            'ts' => time(),
            'until' => $this->getScheduleActiveUntil($schedule),
            'reason' => $schedule['reason'],
            'scopes' => $scopes,
            'admin' => $schedule['createdBy'],
        ]);

        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'Recurring block saved.');
    }

    /**
     * @Route("/admin/users/{id}/block-schedule/delete", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function deleteBlockScheduleAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_delete_block_schedule_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }
        $schedule = $this->getUserBlockSchedule($user);
        $user->setPreference(self::PREF_BLOCK_SCHEDULE, null);
        $this->applyMqttBlockState($user, false);
        $this->appendBlockHistoryEntry($user, [
            'event' => 'schedule_deleted',
            'ts' => time(),
            'until' => 0,
            'reason' => $schedule['reason'] ?? '',
            'scopes' => $schedule['scopes'] ?? [],
            'admin' => (string)($this->getUser() instanceof AdminPanelUser ? $this->getUser()->getUsername() : ''),
        ]);
        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'Recurring block deleted.');
    }

    /**
     * @Route("/admin/users/{id}/limits/approve", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function approveLimitsAction(Request $request, int $id, EntityManagerInterface $em, ApiRateLimitStorage $apiRateLimitStorage): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_approve_limits_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }
        $pending = $user->getPreference(self::PREF_PENDING_LIMITS, null);
        if (!is_array($pending) || !is_array(($pending['limits'] ?? null))) {
            return $this->redirectWith($request, 'err', 'No pending limits.');
        }

        $limits = $pending['limits'];
        foreach ($limits as $publicField => $value) {
            if (!array_key_exists($publicField, self::CURRENT_USER_LIMIT_FIELDS)) {
                return $this->redirectWith($request, 'err', 'Unknown limit field: ' . $publicField);
            }
            $value = (int)$value;
            if ($value < 0) {
                return $this->redirectWith($request, 'err', 'Invalid limit: ' . $publicField);
            }
            EntityUtils::setField($user, self::CURRENT_USER_LIMIT_FIELDS[$publicField], $value);
        }
        if (array_key_exists('apiRateLimit', $pending)) {
            $newRule = trim((string)$pending['apiRateLimit']);
            if ($newRule === '') {
                $user->setApiRateLimit(null);
            } else {
                $rule = new \SuplaBundle\EventListener\ApiRateLimit\ApiRateLimitRule($newRule);
                if (!$rule->isValid()) {
                    return $this->redirectWith($request, 'err', 'Invalid API rate limit rule.');
                }
                $user->setApiRateLimit($rule);
            }
            $apiRateLimitStorage->clearUserLimit($user);
        }
        $user->setPreference(self::PREF_PENDING_LIMITS, null);
        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'Limits approved.');
    }

    /**
     * @Route("/admin/users/{id}/limits/reject", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function rejectLimitsAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_reject_limits_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }
        $user->setPreference(self::PREF_PENDING_LIMITS, null);
        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'Pending limits rejected.');
    }

    /**
     * @Route("/admin/users/{id}/limits/toggle-lock", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function toggleLimitsLockAction(Request $request, int $id, EntityManagerInterface $em): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminOperator()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_toggle_limits_lock_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }
        $locked = (bool)$user->getPreference(self::PREF_LIMITS_SELF_UPDATE_LOCKED, false);
        $user->setPreference(self::PREF_LIMITS_SELF_UPDATE_LOCKED, !$locked);
        $em->persist($user);
        $em->flush();
        return $this->redirectWith($request, 'msg', 'Limits self-update flag updated.');
    }

    /**
     * @Route("/admin/users/{id}/delete", requirements={"id"="\d+"}, methods={"POST"})
     */
    public function deleteAction(Request $request, int $id, EntityManagerInterface $em, UserManager $userManager): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminSuper()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_user_delete_' . $id)) {
            return $response;
        }
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectWith($request, 'err', 'User not found.');
        }

        $confirm = trim((string)$request->request->get('confirmEmail', ''));
        if (!hash_equals(strtolower($user->getEmail()), strtolower($confirm))) {
            return $this->redirectWith($request, 'err', 'Delete blocked: type the exact email to confirm.');
        }

        try {
            $userManager->deleteAccount($user);
        } catch (\Throwable $e) {
            return $this->redirectWith($request, 'err', 'Delete failed: ' . $e->getMessage());
        }

        return $this->redirectWith($request, 'msg', 'User deleted.');
    }

    /**
     * @Route("/admin/users/registration-block", methods={"POST"})
     */
    public function registrationBlockToggleAction(Request $request, RegistrationBlockStore $registrationBlockStore): Response {
        if ($guard = $this->requireAllowedAdminUser()) {
            return $guard;
        }
        if ($guard = $this->requireAdminSuper()) {
            return $guard;
        }
        if ($response = $this->rejectInvalidCsrf($request, 'admin_registration_block_toggle')) {
            return $response;
        }

        $locale = $this->getAdminLocale($request);
        $tr = $this->translator($locale);
        $blocked = (string)$request->request->get('blocked', '1') === '1';
        $registrationBlockStore->setBlocked($blocked, $this->currentAdminUsername() ?: 'admin');
        return new RedirectResponse('/admin/users?msg=' . rawurlencode($blocked ? $tr('registration_blocked_saved') : $tr('registration_allowed_saved')) . '#registration-block');
    }

    private function redirectWith(Request $request, string $key, string $value): RedirectResponse {
        if ((string)$request->query->get('return', '') === 'details') {
            $params = $request->query->all();
            unset($params['msg'], $params['err']);
            $params[$key] = $value;
            $id = (int)$request->attributes->get('id', 0);
            return new RedirectResponse('/admin/users/' . $id . ($params ? ('?' . http_build_query($params)) : ''));
        }
        $params = $request->query->all();
        unset($params['msg'], $params['err']);
        $params[$key] = $value;
        $qs = http_build_query($params);
        return new RedirectResponse('/admin/users' . ($qs ? ('?' . $qs) : ''));
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

    private function requireAdminOperator(): ?Response {
        if (!$this->isGranted('ROLE_ADMIN_OPERATOR') && !$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return null;
    }

    private function requireAdminSuper(): ?Response {
        if (!$this->isGranted('ROLE_ADMIN_SUPER')) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return null;
    }

    private function currentAdminUsername(): string {
        $user = $this->getUser();
        return $user instanceof AdminPanelUser ? (string)$user->getUsername() : '';
    }

    private function getPasswordResetCooldownRemaining(AdminPanelAccountStore $store, int $userId): int {
        $entries = $store->getAuditEntries(500);
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $entry = $entries[$i];
            if (($entry['event'] ?? '') !== 'admin_user_password_reset_requested') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if ((int)($meta['userId'] ?? 0) !== $userId) {
                continue;
            }
            $timestamp = strtotime((string)($entry['ts'] ?? '')) ?: 0;
            if ($timestamp <= 0) {
                continue;
            }
            $remaining = ($timestamp + self::PASSWORD_RESET_COOLDOWN_SECONDS) - time();
            return max(0, $remaining);
        }
        return 0;
    }

    /**
     * @param User[] $users
     */
    private function renderUsersHtml(
        Request $request,
        array $users,
        int $page,
        bool $hasNext,
        string $query,
        string $enabledFilter,
        string $msg,
        string $err,
        string $locale,
        array $registrationState
    ): string {
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tr = $this->translator($locale);
        $selfQuery = [
            'q' => $query,
            'enabled' => $enabledFilter,
        ];
        $baseQs = http_build_query(array_filter($selfQuery, static fn($v) => $v !== '' && $v !== null));
        $prevUrl = '/admin/users?' . http_build_query(array_merge($selfQuery, ['page' => max(1, $page - 1)]));
        $nextUrl = '/admin/users?' . http_build_query(array_merge($selfQuery, ['page' => $page + 1]));

        $enabledOptions = [
            '' => $tr('all'),
            '1' => $tr('enabled'),
            '0' => $tr('disabled'),
        ];

        $rows = '';
        $canWrite = $this->isGranted('ROLE_ADMIN_OPERATOR') || $this->isGranted('ROLE_ADMIN_SUPER');
        $canSuper = $this->isGranted('ROLE_ADMIN_SUPER');
        $canDelete = $this->isGranted('ROLE_ADMIN_SUPER');
        foreach ($users as $index => $user) {
            $id = (int)$user->getId();
            $displayCode = $this->formatUserCode($user);
            $displayCodeHtml = '<a href="/admin/users/' . $id . '">' . $escape($displayCode) . '</a>' . $this->renderCopyCodeButton($displayCode, $tr('copy'));
            $rowNumber = (($page - 1) * self::PER_PAGE) + $index + 1;
            $email = (string)$user->getEmail();
            $enabled = $user->isEnabled() ? 'yes' : 'no';
            $enabledClass = $user->isEnabled() ? 'ok' : 'bad';
            $blockSummary = $this->getUserBlockSummary($user);
            $blockedUntil = (int)$blockSummary['until'];
            $blockedNow = (bool)$blockSummary['active'];
            $blockedText = $blockedNow ? $this->formatBlockStatusText($blockSummary, $locale) : $tr('no');
            $regDate = method_exists($user, 'getRegDate') && $user->getRegDate()
                ? $user->getRegDate()->format('Y-m-d H:i')
                : '';
            $locale = method_exists($user, 'getLocale') ? (string)$user->getLocale() : '';
            $pending = $user->getPreference(self::PREF_PENDING_LIMITS, null);
            $hasPending = is_array($pending);
            $limitsLock = (bool)$user->getPreference(self::PREF_LIMITS_SELF_UPDATE_LOCKED, false);
            $resetConfirmPrompt = json_encode(sprintf($tr('confirm_password_reset_prompt'), $displayCode, $email), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $resetForceConfirmPrompt = json_encode(sprintf($tr('confirm_password_reset_force_prompt'), $displayCode, $email), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $actionQs = $baseQs ? ('?' . $baseQs . '&page=' . $page) : ('?page=' . $page);

            $rows .= '<tr>';
            $rows .= '<td class="mono">' . $rowNumber . '</td>';
            $rows .= '<td class="mono">' . $displayCodeHtml . '</td>';
            $rows .= '<td><a href="/admin/users/' . $id . '">' . $escape($email) . '</a>';
            if ($canWrite) {
                $rows .= ' <form method="post" action="/admin/users/' . $id . '/reset-password' . $actionQs . '" style="display:inline;" onsubmit="return confirm(' . $resetConfirmPrompt . ');">';
                $rows .= $this->csrfField('admin_user_reset_password_' . $id);
                $rows .= '<button type="submit" class="mini-reset">' . $escape($tr('send_password_reset_link_short')) . '</button>';
                $rows .= '</form>';
                if ($canSuper) {
                    $rows .= ' <form method="post" action="/admin/users/' . $id . '/reset-password' . $actionQs . '" style="display:inline;" onsubmit="return confirm(' . $resetForceConfirmPrompt . ');">';
                    $rows .= $this->csrfField('admin_user_reset_password_' . $id);
                    $rows .= '<input type="hidden" name="force" value="1" />';
                    $rows .= '<button type="submit" class="mini-reset force">' . $escape($tr('send_password_reset_link_force_short')) . '</button>';
                    $rows .= '</form>';
                }
            }
            $rows .= '</td>';
            $rows .= '<td class="' . $enabledClass . '">' . $enabled . '</td>';
            $rows .= '<td class="' . ($blockedNow ? 'bad' : 'ok') . '">' . $escape($blockedText) . '</td>';
            $rows .= '<td class="mono">' . $escape($regDate) . '</td>';
            $rows .= '<td class="mono">' . $escape($locale) . '</td>';
            $rows .= '<td class="actions">';
            if ($canWrite) {
                $rows .= '<form method="post" action="/admin/users/' . $id . '/reset-password' . $actionQs . '" onsubmit="return confirm(' . $resetConfirmPrompt . ');">';
                $rows .= $this->csrfField('admin_user_reset_password_' . $id);
                $rows .= '<button type="submit">' . $escape($tr('send_password_reset_link')) . '</button>';
                $rows .= '</form>';
                if ($user->isEnabled()) {
                    $rows .= '<form method="post" action="/admin/users/' . $id . '/toggle-enabled' . $actionQs . '">';
                    $rows .= $this->csrfField('admin_user_toggle_enabled_' . $id);
                    $rows .= '<button type="submit">' . $tr('disable') . '</button>';
                    $rows .= '</form>';
                } else {
                    $rows .= '<form method="post" action="/admin/users/' . $id . '/confirm' . $actionQs . '">';
                    $rows .= $this->csrfField('admin_user_confirm_' . $id);
                    $rows .= '<button type="submit">' . $tr('confirm') . '</button>';
                    $rows .= '</form>';
                }
                $rows .= '<form method="post" action="/admin/users/' . $id . '/' . ($blockedNow ? 'unblock' : 'block') . $actionQs . '">';
                $rows .= $this->csrfField('admin_user_' . ($blockedNow ? 'unblock_' : 'block_') . $id);
                if ($blockedNow) {
                    $rows .= '<button type="submit">' . $escape($tr('unblock')) . '</button>';
                } else {
                    $rows .= '<select name="seconds">';
                    $rows .= '<option value="3600">1h</option>';
                    $rows .= '<option value="21600">6h</option>';
                    $rows .= '<option value="43200">12h</option>';
                    $rows .= '<option value="86400">1d</option>';
                    $rows .= '<option value="604800">1w</option>';
                    $rows .= '<option value="1209600">2w</option>';
                    $rows .= '<option value="2592000">1m</option>';
                    $rows .= '</select>';
                    $rows .= '<label><input type="checkbox" name="scopes[]" value="www" checked /> WWW</label>';
                    $rows .= '<label><input type="checkbox" name="scopes[]" value="api" checked /> API</label>';
                    $rows .= '<label><input type="checkbox" name="scopes[]" value="mqtt" checked /> MQTT</label>';
                    $rows .= '<input name="reason" placeholder="' . $escape($tr('block_reason_placeholder')) . '" value="" />';
                    $rows .= '<button type="submit">' . $escape($tr('block')) . '</button>';
                }
                $rows .= '</form>';
                $rows .= '<form method="post" action="/admin/users/' . $id . '/limits/toggle-lock' . $actionQs . '">';
                $rows .= $this->csrfField('admin_user_toggle_limits_lock_' . $id);
                $rows .= '<button type="submit">' . ($limitsLock ? $escape($tr('unlock_limits')) : $escape($tr('lock_limits'))) . '</button>';
                $rows .= '</form>';
                if ($hasPending) {
                    $rows .= '<form method="post" action="/admin/users/' . $id . '/limits/approve' . $actionQs . '">';
                    $rows .= $this->csrfField('admin_user_approve_limits_' . $id);
                    $rows .= '<button type="submit">' . $escape($tr('approve_limits')) . '</button>';
                    $rows .= '</form>';
                    $rows .= '<form method="post" action="/admin/users/' . $id . '/limits/reject' . $actionQs . '">';
                    $rows .= $this->csrfField('admin_user_reject_limits_' . $id);
                    $rows .= '<button type="submit">' . $escape($tr('reject_limits')) . '</button>';
                    $rows .= '</form>';
                }
            }
            if ($canDelete) {
                $rows .= '<form method="post" action="/admin/users/' . $id . '/delete' . $actionQs . '" class="danger">';
                $rows .= $this->csrfField('admin_user_delete_' . $id);
                $rows .= '<input name="confirmEmail" placeholder="' . $escape($tr('type_email_to_delete')) . '" value="" />';
                $rows .= '<button type="submit" class="danger">' . $escape($tr('delete')) . '</button>';
                $rows .= '</form>';
            }
            if (!$canWrite && !$canDelete) {
                $rows .= '<span style="color:#666;">read-only</span>';
            }
            $rows .= '</td>';
            $rows .= '</tr>';
        }

        $optionsHtml = '';
        foreach ($enabledOptions as $value => $label) {
            $sel = $value === $enabledFilter ? ' selected' : '';
            $optionsHtml .= '<option value="' . $escape($value) . '"' . $sel . '>' . $escape($label) . '</option>';
        }

        $notice = '';
        if ($msg !== '') {
            $notice .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $notice .= '<div class="notice bad">' . $escape($err) . '</div>';
        }
        $registrationBlockTileHtml = $this->renderRegistrationBlockTile($registrationState, $tr, $escape);
        $registrationBlockHtml = $this->renderRegistrationBlockCard($registrationState, $tr, $escape);

        $html = $this->adminUiLayoutOpen(
            $escape($tr('title_users')),
            'users',
            $this->isGranted('ROLE_ADMIN_SUPER'),
            '.bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0 14px 0;}.pager{display:flex;justify-content:space-between;align-items:center;margin-top:12px;}.copy-btn{margin-left:6px;width:26px;height:26px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:11px;border-radius:6px;vertical-align:middle;}.copy-btn.copied{background:#e7f6ee;border-color:#bfe8cf;color:#0b7a3a;}.mini-reset{margin-left:6px;padding:3px 8px;font-size:11px;border-radius:999px;line-height:1.2;background:#eef7f1;border-color:#bfe8cf;color:#0b7a3a;}.mini-reset.force{background:#fff4db;border-color:#f0d18a;color:#8a5a00;}.actions{display:flex;gap:8px;flex-wrap:wrap;}.danger input{min-width:220px;}.ui-page-tools{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px 0;flex-wrap:wrap;}.ui-page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}.ui-page-actions a{padding:6px 10px;border-radius:999px;background:#f6f8f9;border:1px solid #dfe5ea;text-decoration:none !important;}.registration-tile{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;border-left:4px solid #0b7a3a;background:linear-gradient(180deg,#f5fbf7 0%,#fff 100%);}.registration-tile.blocked{border-left-color:#b00020;background:linear-gradient(180deg,#fff7f7 0%,#fff 100%);box-shadow:0 8px 26px rgba(176,0,32,.08);}.registration-tile .badge{font-size:11px;font-weight:800;}.registration-tile .tile-main{min-width:220px;flex:1 1 320px;}.registration-tile .tile-title{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#0b7a3a;margin:0 0 6px 0;}.registration-tile.blocked .tile-title{color:#b00020;}.registration-tile .tile-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;}.registration-tile .tile-head strong{font-size:15px;}.registration-tile .tile-summary{font-size:13px;font-weight:600;}.registration-tile .tile-hint{margin-top:4px;color:#666;font-size:12px;max-width:52rem;}.registration-tile .tile-action{display:inline-block;text-decoration:none;padding:8px 12px;border-radius:10px;background:#0b7a3a;color:#fff;font-weight:700;box-shadow:0 4px 14px rgba(11,122,58,.2);}.registration-tile.blocked .tile-action{background:#b00020;box-shadow:0 4px 14px rgba(176,0,32,.2);}'
        );
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted">' . $escape($tr('title_users')) . '</div>'
            . '<div class="ui-page-actions"><a href="/admin/users?lang=pl" style="' . ($locale === 'pl' ? 'font-weight:700;' : '') . '">Polski</a><a href="/admin/users?lang=en" style="' . ($locale === 'en' ? 'font-weight:700;' : '') . '">English</a><a href="/admin/account">' . $escape($tr('account')) . '</a><a href="/admin/security-log">' . $escape($tr('security_log')) . '</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>'
            . '<h1>' . $escape($tr('title_users')) . '</h1>'
            . $notice
            . $registrationBlockTileHtml
            . '<div class="notice ok" style="margin-top:-2px;">' . $escape($tr('registration_block_success_notice')) . '</div>'
            . '<form class="bar" method="get" action="/admin/users">'
            . '<input name="q" placeholder="' . $escape($tr('search_email')) . '" value="' . $escape($query) . '" />'
            . '<select name="enabled">' . $optionsHtml . '</select>'
            . '<button type="submit">' . $escape($tr('search')) . '</button>'
            . ($baseQs !== '' ? '<a href="/admin/users">' . $escape($tr('clear')) . '</a>' : '')
            . '</form>'
            . $registrationBlockHtml
            . '<table><thead><tr>'
            . '<th>' . $escape($tr('lp')) . '</th><th>' . $escape($tr('user_code')) . '</th><th>Email</th><th>' . $escape($tr('enabled')) . '</th><th>' . $escape($tr('blocked')) . '</th><th>' . $escape($tr('registered')) . '</th><th>' . $escape($tr('locale')) . '</th><th>' . $escape($tr('actions')) . '</th>'
            . '</tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="8">' . $escape($tr('no_users')) . '</td></tr>')
            . '</tbody></table>'
            . '<div class="pager">'
            . '<div>' . $escape($tr('page')) . ': <span class="mono">' . $page . '</span></div>'
            . '<div style="display:flex;gap:10px;">'
            . ($page > 1 ? '<a href="' . $escape($prevUrl) . '">' . $escape($tr('prev')) . '</a>' : '<span style="opacity:.5;">' . $escape($tr('prev')) . '</span>')
            . ($hasNext ? '<a href="' . $escape($nextUrl) . '">' . $escape($tr('next')) . '</a>' : '<span style="opacity:.5;">' . $escape($tr('next')) . '</span>')
            . '</div></div>'
            . '<p style="margin-top:14px;color:#666;font-size:12px;">' . $escape($tr('delete_warning')) . '</p>'
            . $this->renderCopyCodeScript($tr('copied'))
            . $this->adminUiLayoutClose();
        return $html;
    }

    private function getAdminLocale(Request $request): string {
        $cookie = (string)$request->cookies->get(self::LOCALE_COOKIE, '');
        $cookie = strtolower(substr($cookie, 0, 2));
        if (in_array($cookie, ['pl', 'en'], true)) {
            return $cookie;
        }
        return 'pl';
    }

    private function translator(string $locale): callable {
        $dict = [
            'en' => [
                'dashboard' => 'Dashboard',
                'dashboard_title' => 'SUPLA Admin - Dashboard',
                'admins_menu' => 'Admins',
                'admin_history_menu' => 'Admin history',
                'system_health' => 'System health',
                'backup_restore' => 'Backup / Restore',
                'title_users' => 'SUPLA Admin - Users',
                'account' => 'Account',
                'lp' => 'No.',
                'user_code' => 'User code',
                'user_details_title' => 'SUPLA Admin - User details',
                'account_section' => 'Account',
                'user_code_label' => 'User code',
                'locations_section' => 'Locations',
                'access_ids_section' => 'Access IDs',
                'security_log' => 'Security log',
                'logout' => 'Logout',
                'stats_total' => 'Users total',
                'stats_enabled' => 'Enabled',
                'stats_disabled' => 'Disabled',
                'stats_blocked' => 'Blocked now',
                'stats_pending_limits' => 'Pending limits',
                'chart_registrations' => 'User registrations - last 30 days',
                'chart_failures' => 'Admin auth failures - last 30 days',
                'registrations_suffix' => 'registrations',
                'failures_suffix' => 'failures',
                'chart_range' => 'range',
                'chart_max_day' => 'max/day',
                'chart_total' => 'total',
                'recent_users' => 'Recent users',
                'detected_issues' => 'Detected issues',
                'pending_accounts' => 'Accounts pending confirmation',
                'blocked_users' => 'Blocked users',
                'pending_limit_approvals' => 'Pending limit approvals',
                'registration_block' => 'Registration block',
                'registration_blocked_summary' => 'New user registrations are currently blocked.',
                'registration_allowed_summary' => 'New user registrations are currently allowed.',
                'registration_blocked_state' => 'Registration is blocked',
                'registration_allowed_state' => 'Registration is allowed',
                'registration_block_banner_title' => 'Registration control',
                'registration_block_banner_subtitle' => 'Accounts & limits',
                'registration_block_banner_hint' => 'The switch is below. Jump there to change the status.',
                'registration_block_banner_action' => 'Go to switch',
                'registration_block_hint' => 'Use this switch to control whether new users can register.',
                'registration_allow' => 'Allow registration',
                'registration_changed_at' => 'Changed at',
                'registration_changed_by' => 'Changed by',
                'registration_message' => 'Message',
                'registration_blocked_saved' => 'Registration blocked.',
                'registration_allowed_saved' => 'Registration allowed.',
                'registration_block_success_notice' => 'Registration status updated.',
                'registration_block_superadmin_only' => 'Only a superadmin can change registration status.',
                'recent_admin_security_events' => 'Recent admin security events',
                'alerts_title' => 'Admin alerts',
                'no_alerts' => 'No active alerts.',
                'open' => 'Open',
                'blocked_users_alert' => '%d blocked users require attention.',
                'pending_limits_alert' => '%d users have pending limits to approve.',
                'problem_users_alert' => '%d users have detected issues. First: %s',
                'status' => 'Status',
                'user' => 'User',
                'problems' => 'Problems',
                'blocked_until' => 'Blocked until',
                'fields' => 'Fields',
                'entry' => 'Entry',
                'yes' => 'yes',
                'no' => 'no',
                'no_data' => 'No data.',
                'no_issues' => 'No obvious issues detected.',
                'no_pending_accounts' => 'No accounts pending confirmation.',
                'no_blocks' => 'No active blocks.',
                'no_pending_limits' => 'No pending limits.',
                'no_security_events' => 'No security events.',
                'search_email' => 'Search email or USR code...',
                'search' => 'Search',
                'clear' => 'Clear',
                'copy' => 'Copy',
                'copied' => 'Copied',
                'block_reason' => 'Block reason',
                'block_reason_placeholder' => 'Reason for the block',
                'block_scopes' => 'Blocked services',
                'block_history' => 'Block history',
                'no_block_history' => 'No block history.',
                'schedule_block' => 'Recurring block',
                'schedule_days' => 'Days',
                'schedule_time' => 'Time',
                'save_schedule' => 'Save recurring block',
                'delete_schedule' => 'Delete recurring block',
                'schedule_preset' => 'Preset',
                'schedule_preset_none' => 'Custom',
                'preset_work_hours' => 'Mon-Fri 08:00-16:00',
                'preset_weekend' => 'Weekend 00:00-23:59',
                'preset_night' => 'Every day 22:00-06:00',
                'event' => 'Event',
                'all' => 'All',
                'enabled' => 'Enabled',
                'disabled' => 'Disabled',
                'enable' => 'Enable',
                'disable' => 'Disable',
                'confirm' => 'Confirm',
                'blocked' => 'Blocked',
                'block' => 'Block',
                'unblock' => 'Unblock',
                'lock_limits' => 'Lock limits',
                'unlock_limits' => 'Unlock limits',
                'approve_limits' => 'Approve limits',
                'reject_limits' => 'Reject limits',
                'registered' => 'Registered',
                'locale' => 'Locale',
                'actions' => 'Actions',
                'no_users' => 'No users found.',
                'page' => 'Page',
                'prev' => 'Prev',
                'next' => 'Next',
                'delete' => 'Delete',
                'type_email_to_delete' => 'Type email to delete',
                'delete_warning' => 'Delete is irreversible and removes all user data.',
                'send_password_reset_link' => 'Send password reset link',
                'password_reset_link_sent' => 'Password reset link sent.',
                'password_reset_link_failed' => 'Could not send the password reset link.',
                'invalid_email_address' => 'User does not have a valid e-mail address.',
                'user_not_found' => 'User not found.',
                'password_reset_link_rate_limited' => 'Password reset link was already sent recently. Try again in %d minutes.',
                'confirm_password_reset_prompt' => 'Send password reset link to %s (%s)?',
                'confirm_password_reset_force_prompt' => 'Force send password reset link to %s (%s) and override cooldown?',
                'send_password_reset_link_force' => 'Send anyway',
                'send_password_reset_link_short' => 'Reset',
                'send_password_reset_link_force_short' => 'Reset anyway',
            ],
            'pl' => [
                'dashboard' => 'Dashboard',
                'dashboard_title' => 'SUPLA Admin - Dashboard',
                'admins_menu' => 'Admini',
                'admin_history_menu' => 'Historia adminów',
                'system_health' => 'Stan systemu',
                'backup_restore' => 'Backup / Restore',
                'title_users' => 'SUPLA Admin - Uzytkownicy',
                'account' => 'Konto',
                'lp' => 'Lp.',
                'user_code' => 'Kod usera',
                'user_details_title' => 'SUPLA Admin - Szczegóły użytkownika',
                'account_section' => 'Konto',
                'user_code_label' => 'Kod usera',
                'locations_section' => 'Lokalizacje',
                'access_ids_section' => 'Access ID',
                'security_log' => 'Log bezpieczenstwa',
                'logout' => 'Wyloguj',
                'stats_total' => 'Wszyscy użytkownicy',
                'stats_enabled' => 'Aktywni',
                'stats_disabled' => 'Nieaktywni',
                'stats_blocked' => 'Aktualnie zablokowani',
                'stats_pending_limits' => 'Oczekujące limity',
                'chart_registrations' => 'Rejestracje użytkowników - ostatnie 30 dni',
                'chart_failures' => 'Błędy logowania admina - ostatnie 30 dni',
                'registrations_suffix' => 'rejestracje',
                'failures_suffix' => 'błędy',
                'chart_range' => 'zakres',
                'chart_max_day' => 'max/dzień',
                'chart_total' => 'suma',
                'recent_users' => 'Ostatni użytkownicy',
                'detected_issues' => 'Wykryte problemy',
                'pending_accounts' => 'Konta oczekujące na potwierdzenie',
                'blocked_users' => 'Zablokowani użytkownicy',
                'pending_limit_approvals' => 'Oczekujące akceptacje limitów',
                'registration_block' => 'Blokada rejestracji',
                'registration_blocked_summary' => 'Rejestracja nowych kont użytkowników jest zablokowana.',
                'registration_allowed_summary' => 'Rejestracja nowych kont użytkowników jest dozwolona.',
                'registration_blocked_state' => 'Rejestracja jest zablokowana',
                'registration_allowed_state' => 'Rejestracja jest dozwolona',
                'registration_block_banner_title' => 'Sterowanie rejestracją',
                'registration_block_banner_subtitle' => 'Konta i limity',
                'registration_block_banner_hint' => 'Przełącznik znajduje się niżej. Przejdź tam, aby zmienić status.',
                'registration_block_banner_action' => 'Przejdź do przełącznika',
                'registration_block_hint' => 'Użyj tego przełącznika, aby sterować możliwością rejestracji nowych użytkowników.',
                'registration_allow' => 'Odblokuj rejestrację',
                'registration_changed_at' => 'Zmieniono',
                'registration_changed_by' => 'Zmienił',
                'registration_message' => 'Komunikat',
                'registration_blocked_saved' => 'Rejestracja zablokowana.',
                'registration_allowed_saved' => 'Rejestracja dozwolona.',
                'registration_block_success_notice' => 'Status rejestracji został zaktualizowany.',
                'registration_block_superadmin_only' => 'Tylko superadmin może zmieniać blokadę rejestracji.',
                'recent_admin_security_events' => 'Ostatnie zdarzenia bezpieczeństwa admina',
                'alerts_title' => 'Alerty admina',
                'no_alerts' => 'Brak aktywnych alertów.',
                'open' => 'Otwórz',
                'blocked_users_alert' => 'Wymaga uwagi: %d zablokowanych użytkowników.',
                'pending_limits_alert' => '%d użytkowników ma oczekujące limity do akceptacji.',
                'problem_users_alert' => 'Wykryto problemy u %d użytkowników. Pierwszy: %s',
                'status' => 'Status',
                'user' => 'Użytkownik',
                'problems' => 'Problemy',
                'blocked_until' => 'Blokada do',
                'fields' => 'Pola',
                'entry' => 'Wpis',
                'yes' => 'tak',
                'no' => 'nie',
                'no_data' => 'Brak danych.',
                'no_issues' => 'Nie wykryto oczywistych problemów.',
                'no_pending_accounts' => 'Brak kont oczekujących na potwierdzenie.',
                'no_blocks' => 'Brak aktywnych blokad.',
                'no_pending_limits' => 'Brak oczekujących limitów.',
                'no_security_events' => 'Brak zdarzeń bezpieczeństwa.',
                'search_email' => 'Szukaj email lub kodu USR...',
                'search' => 'Szukaj',
                'clear' => 'Wyczysc',
                'copy' => 'Kopiuj',
                'copied' => 'Skopiowano',
                'block_reason' => 'Powód blokady',
                'block_reason_placeholder' => 'Powód blokady',
                'block_scopes' => 'Zablokowane usługi',
                'block_history' => 'Historia blokad',
                'no_block_history' => 'Brak historii blokad.',
                'schedule_block' => 'Blokada cykliczna',
                'schedule_days' => 'Dni',
                'schedule_time' => 'Godziny',
                'save_schedule' => 'Zapisz blokadę cykliczną',
                'delete_schedule' => 'Usuń blokadę cykliczną',
                'schedule_preset' => 'Preset',
                'schedule_preset_none' => 'Własny',
                'preset_work_hours' => 'Pon-Pt 08:00-16:00',
                'preset_weekend' => 'Weekend 00:00-23:59',
                'preset_night' => 'Codziennie 22:00-06:00',
                'event' => 'Zdarzenie',
                'all' => 'Wszyscy',
                'enabled' => 'Aktywny',
                'disabled' => 'Zablokowany',
                'enable' => 'Odblokuj',
                'disable' => 'Zablokuj',
                'confirm' => 'Potwierdz konto',
                'blocked' => 'Blokada',
                'block' => 'Blokuj',
                'unblock' => 'Odblokuj',
                'lock_limits' => 'Zablokuj limity',
                'unlock_limits' => 'Odblokuj limity',
                'approve_limits' => 'Akceptuj limity',
                'reject_limits' => 'Odrzuc limity',
                'registered' => 'Rejestracja',
                'locale' => 'Jezyk',
                'actions' => 'Akcje',
                'no_users' => 'Brak uzytkownikow.',
                'page' => 'Strona',
                'prev' => 'Wstecz',
                'next' => 'Dalej',
                'delete' => 'Usun',
                'type_email_to_delete' => 'Wpisz email aby usunac',
                'delete_warning' => 'Usuniecie jest nieodwracalne i usuwa wszystkie dane uzytkownika.',
                'send_password_reset_link' => 'Wyslij link do resetu hasla',
                'password_reset_link_sent' => 'Link do resetu hasla zostal wyslany.',
                'password_reset_link_failed' => 'Nie udalo sie wyslac linku do resetu hasla.',
                'invalid_email_address' => 'Uzytkownik nie ma prawidlowego adresu e-mail.',
                'user_not_found' => 'Nie znaleziono uzytkownika.',
                'password_reset_link_rate_limited' => 'Link resetu hasla zostal juz wyslany niedawno. Sprobuj ponownie za %d minut.',
                'confirm_password_reset_prompt' => 'Wyslac link resetu hasla do %s (%s)?',
                'confirm_password_reset_force_prompt' => 'Wymusic wyslanie linku resetu hasla do %s (%s) i pominac cooldown?',
                'send_password_reset_link_force' => 'Wyslij mimo to',
                'send_password_reset_link_short' => 'Reset',
                'send_password_reset_link_force_short' => 'Reset mimo to',
            ],
        ];
        $lang = isset($dict[$locale]) ? $locale : 'pl';
        return static fn(string $key): string => $dict[$lang][$key] ?? $key;
    }

    /**
     * @param array<int, array{user: User, problems: string[]}> $problemUsers
     * @param array<int, array{user: User, blockedUntil: int}> $blockedUsers
     * @param array<int, array{user: User, pending: array}> $pendingLimitUsers
     * @param User[] $recentUsers
     * @param User[] $pendingUsers
     * @param string[] $securityEvents
     * @param array<int, array{level:string,title:string,message:string,link?:string}> $alerts
     */
    private function renderDashboardHtml(array $stats, array $recentUsers, array $pendingUsers, array $blockedUsers, array $pendingLimitUsers, array $problemUsers, array $securityEvents, array $registrationSeries, array $authFailureSeries, array $registrationState, array $alerts, string $msg, string $err, string $locale, string $searchQuery): string {
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $canWrite = $this->isGranted('ROLE_ADMIN_OPERATOR') || $this->isGranted('ROLE_ADMIN_SUPER');
        $tr = $this->translator($locale);
        $notice = '';
        if ($msg !== '') {
            $notice .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $notice .= '<div class="notice bad">' . $escape($err) . '</div>';
        }

        $alertsHtml = '';
        foreach ($alerts as $alert) {
            $level = in_array((string)($alert['level'] ?? 'warn'), ['ok', 'warn', 'bad'], true) ? (string)$alert['level'] : 'warn';
            $title = (string)($alert['title'] ?? '');
            $message = (string)($alert['message'] ?? '');
            $link = (string)($alert['link'] ?? '');
            $alertsHtml .= '<div class="alert ' . $escape($level) . '"><b>' . $escape($title) . ':</b> ' . $escape($message);
            if ($link !== '') {
                $alertsHtml .= ' <a href="' . $escape($link) . '">' . $escape($tr('open')) . '</a>';
            }
            $alertsHtml .= '</div>';
        }
        if ($alertsHtml === '') {
            $alertsHtml = '<div class="alert ok">' . $escape($tr('no_alerts')) . '</div>';
        }

        $statsHtml = '';
        foreach ([
            $tr('stats_total') => (string)($stats['total'] ?? 0),
            $tr('stats_enabled') => (string)($stats['enabled'] ?? 0),
            $tr('stats_disabled') => (string)($stats['disabled'] ?? 0),
            $tr('stats_blocked') => (string)($stats['blocked'] ?? 0),
            $tr('stats_pending_limits') => (string)($stats['pendingLimits'] ?? 0),
        ] as $label => $value) {
            $statsHtml .= '<div class="stat"><div class="label">' . $escape($label) . '</div><div class="value">' . $escape($value) . '</div></div>';
        }

        $recentHtml = '';
        foreach ($recentUsers as $user) {
            $userId = (int)$user->getId();
            $displayCode = $this->formatUserCode($user);
            $recentHtml .= '<tr><td class="mono"><a href="/admin/users/' . $userId . '">' . $escape($displayCode) . '</a>' . $this->renderCopyCodeButton($displayCode, $tr('copy')) . '</td><td><a href="/admin/users/' . $userId . '">' . $escape((string)$user->getEmail()) . '</a></td><td>' . ($user->isEnabled() ? $escape($tr('yes')) : $escape($tr('no'))) . '</td></tr>';
        }
        if ($recentHtml === '') {
            $recentHtml = '<tr><td colspan="3" style="color:#666;">' . $escape($tr('no_data')) . '</td></tr>';
        }

        $problemHtml = '';
        foreach ($problemUsers as $item) {
            $problemHtml .= '<tr><td><a href="/admin/users/' . (int)$item['user']->getId() . '">' . $escape((string)$item['user']->getEmail()) . '</a></td><td>' . $escape(implode(', ', $item['problems'])) . '</td></tr>';
        }
        if ($problemHtml === '') {
            $problemHtml = '<tr><td colspan="2" style="color:#666;">' . $escape($tr('no_issues')) . '</td></tr>';
        }

        $pendingUsersHtml = '';
        foreach ($pendingUsers as $user) {
            $userId = (int)$user->getId();
            $displayCode = $this->formatUserCode($user);
            $pendingUsersHtml .= '<tr><td class="mono"><a href="/admin/users/' . $userId . '">' . $escape($displayCode) . '</a>' . $this->renderCopyCodeButton($displayCode, $tr('copy')) . '</td><td><a href="/admin/users/' . $userId . '">' . $escape((string)$user->getEmail()) . '</a></td><td>' . ($canWrite ? '<form method="post" action="/admin/users/' . $userId . '/confirm" style="display:inline;">' . $this->csrfField('admin_user_confirm_' . $userId) . '<button type="submit">Confirm</button></form>' : '<span style="color:#666;">read-only</span>') . '</td></tr>';
        }
        if ($pendingUsersHtml === '') {
            $pendingUsersHtml = '<tr><td colspan="3" style="color:#666;">' . $escape($tr('no_pending_accounts')) . '</td></tr>';
        }

        $blockedUsersHtml = '';
        foreach ($blockedUsers as $item) {
            $reason = trim((string)($item['reason'] ?? ''));
            $scopes = $this->formatBlockScopes((array)($item['scopes'] ?? []), $locale);
            $details = $escape(date('Y-m-d H:i', (int)$item['blockedUntil'])) . ($scopes !== '' ? ' · ' . $escape($scopes) : '') . ($reason !== '' ? ' · ' . $escape($reason) : '');
            $blockedUsersHtml .= '<tr><td><a href="/admin/users/' . (int)$item['user']->getId() . '">' . $escape((string)$item['user']->getEmail()) . '</a></td><td class="mono">' . $details . '</td></tr>';
        }
        if ($blockedUsersHtml === '') {
            $blockedUsersHtml = '<tr><td colspan="2" style="color:#666;">' . $escape($tr('no_blocks')) . '</td></tr>';
        }

        $pendingLimitsHtml = '';
        foreach ($pendingLimitUsers as $item) {
            $pendingLimitsHtml .= '<tr><td><a href="/admin/users/' . (int)$item['user']->getId() . '">' . $escape((string)$item['user']->getEmail()) . '</a></td><td class="mono">' . count((array)($item['pending']['limits'] ?? [])) . '</td><td>' . ($canWrite ? '<form method="post" action="/admin/users/' . (int)$item['user']->getId() . '/limits/approve" style="display:inline;">' . $this->csrfField('admin_user_approve_limits_' . (int)$item['user']->getId()) . '<button type="submit">Approve</button></form>' : '<span style="color:#666;">read-only</span>') . '</td></tr>';
        }
        if ($pendingLimitsHtml === '') {
            $pendingLimitsHtml = '<tr><td colspan="3" style="color:#666;">' . $escape($tr('no_pending_limits')) . '</td></tr>';
        }

        $securityEventsHtml = '';
        foreach ($securityEvents as $line) {
            $securityEventsHtml .= '<tr><td class="mono">' . $escape($line) . '</td></tr>';
        }
        if ($securityEventsHtml === '') {
            $securityEventsHtml = '<tr><td style="color:#666;">' . $escape($tr('no_security_events')) . '</td></tr>';
        }

        $chartsHtml = ''
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('chart_registrations')) . '</h3>'
            . $this->renderBarChart($registrationSeries, '#0b7a3a', $tr('registrations_suffix'), $tr('chart_range'), $tr('chart_max_day'), $tr('chart_total'))
            . '</div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('chart_failures')) . '</h3>'
            . $this->renderBarChart($authFailureSeries, '#b00020', $tr('failures_suffix'), $tr('chart_range'), $tr('chart_max_day'), $tr('chart_total'))
            . '</div>';

        $html = $this->adminUiLayoutOpen(
            $escape($tr('dashboard_title')),
            'dashboard',
            $this->isGranted('ROLE_ADMIN_SUPER'),
            '.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:10px 0 14px 0;}.stat .label{font-size:11px;color:#5b6570;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;}.stat .value{font-size:26px;font-weight:750;letter-spacing:-0.02em;}.columns{display:grid;grid-template-columns:1fr 1fr;gap:12px;}.alert-wrap{margin:10px 0 14px 0;}.alert{padding:9px 11px;border-radius:10px;margin:7px 0;font-size:13px;border:1px solid transparent;}.alert.ok{background:#e7f6ee;color:#0b7a3a;border-color:#bfe8cf;}.alert.warn{background:#fff4db;color:#8a5a00;border-color:#f0d18a;}.alert.bad{background:#fdecee;color:#b00020;border-color:#f2b8bf;}.chart{width:100%;height:auto;display:block;}.chart-meta{display:flex;justify-content:space-between;gap:12px;color:#666;font-size:12px;margin-top:8px;}.ui-page-tools{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px 0;flex-wrap:wrap;}.ui-page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}.registration-tile{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;border-left:4px solid #0b7a3a;background:linear-gradient(180deg,#f5fbf7 0%,#fff 100%);}.registration-tile.blocked{border-left-color:#b00020;background:linear-gradient(180deg,#fff7f7 0%,#fff 100%);box-shadow:0 8px 26px rgba(176,0,32,.08);}.registration-tile .badge{font-size:11px;font-weight:800;}.registration-tile .tile-main{min-width:220px;flex:1 1 320px;}.registration-tile .tile-title{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#0b7a3a;margin:0 0 6px 0;}.registration-tile.blocked .tile-title{color:#b00020;}.registration-tile .tile-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;}.registration-tile .tile-head strong{font-size:15px;}.registration-tile .tile-summary{font-size:13px;font-weight:600;}.registration-tile .tile-hint{margin-top:4px;color:#666;font-size:12px;max-width:52rem;}.registration-tile .tile-action{display:inline-block;text-decoration:none;padding:8px 12px;border-radius:10px;background:#0b7a3a;color:#fff;font-weight:700;box-shadow:0 4px 14px rgba(11,122,58,.2);}.registration-tile.blocked .tile-action{background:#b00020;box-shadow:0 4px 14px rgba(176,0,32,.2);}'
        );
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted">Dashboard overview and quick access to all admin sections.</div>'
            . '<div class="ui-page-actions"><a href="/admin/account">' . $escape($tr('account')) . '</a><a href="/admin/security-log">' . $escape($tr('security_log')) . '</a><a href="/admin/logout">' . $escape($tr('logout')) . '</a></div>'
            . '</div>'
            . '<h1>' . $escape($tr('dashboard_title')) . '</h1>'
            . $notice
            . '<div class="card alert-wrap"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('alerts_title')) . '</h3>' . $alertsHtml . '</div>'
            . '<form class="bar" method="get" action="/admin/users" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0 14px 0;"><input name="q" placeholder="' . $escape($tr('search_email')) . '" value="' . $escape($searchQuery) . '" /><button type="submit">' . $escape($tr('search')) . '</button></form>'
            . '<div class="stats">' . $statsHtml . '</div>'
            . '<div class="columns">' . $chartsHtml . '</div>'
            . '<div class="columns">'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('recent_users')) . '</h3><table><thead><tr><th>' . $escape($tr('user_code')) . '</th><th>Email</th><th>' . $escape($tr('status')) . '</th></tr></thead><tbody>' . $recentHtml . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('detected_issues')) . '</h3><table><thead><tr><th>' . $escape($tr('user')) . '</th><th>' . $escape($tr('problems')) . '</th></tr></thead><tbody>' . $problemHtml . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('pending_accounts')) . '</h3><table><thead><tr><th>' . $escape($tr('user_code')) . '</th><th>Email</th><th>' . $escape($tr('actions')) . '</th></tr></thead><tbody>' . $pendingUsersHtml . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('blocked_users')) . '</h3><table><thead><tr><th>' . $escape($tr('user')) . '</th><th>' . $escape($tr('blocked_until')) . '</th></tr></thead><tbody>' . $blockedUsersHtml . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('pending_limit_approvals')) . '</h3><table><thead><tr><th>' . $escape($tr('user')) . '</th><th>' . $escape($tr('fields')) . '</th><th>' . $escape($tr('actions')) . '</th></tr></thead><tbody>' . $pendingLimitsHtml . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:16px;">' . $escape($tr('recent_admin_security_events')) . '</h3><table><thead><tr><th>' . $escape($tr('entry')) . '</th></tr></thead><tbody>' . $securityEventsHtml . '</tbody></table></div>'
            . '</div>'
            . $this->renderCopyCodeScript($tr('copied'))
            . $this->adminUiLayoutClose();
        return $html;
    }

    /**
     * @param array{blocked:bool,changedAt:?string,changedBy:?string,message:string} $state
     */
    private function renderRegistrationBlockTile(array $state, callable $tr, callable $escape): string {
        $blocked = !empty($state['blocked']);
        $summary = $blocked ? $tr('registration_blocked_summary') : $tr('registration_allowed_summary');
        $statusLabel = $blocked ? $tr('registration_blocked_state') : $tr('registration_allowed_state');
        $badgeClass = $blocked ? 'warn' : 'ok';
        $tileClass = $blocked ? 'registration-tile blocked' : 'registration-tile';
        $actionLabel = $blocked ? $tr('registration_block_banner_action') : $tr('registration_block_banner_action');

        return '<div class="card section ' . $tileClass . '" style="margin:0 0 14px 0;">'
            . '<div class="tile-main">'
            . '<div class="tile-title">' . $escape($tr('registration_block_banner_title')) . '</div>'
            . '<div class="tile-subtitle" style="font-size:12px;color:#5b6570;margin:-2px 0 4px 0;">' . $escape($tr('registration_block_banner_subtitle')) . '</div>'
            . '<div class="tile-head">'
            . '<strong>' . $escape($tr('registration_block')) . '</strong>'
            . '<span class="badge ' . $badgeClass . '">' . $escape($statusLabel) . '</span>'
            . '</div>'
            . '<div class="tile-summary">' . $escape($summary) . '</div>'
            . '<div class="tile-hint">' . $escape($tr('registration_block_banner_hint')) . '</div>'
            . '</div>'
            . '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
            . '<a href="#registration-block" class="tile-action">' . $escape($actionLabel) . '</a>'
            . '</div>'
            . '</div>';
    }

    /**
     * @param array{blocked:bool,changedAt:?string,changedBy:?string,message:string} $state
     */
    private function renderRegistrationBlockCard(array $state, callable $tr, callable $escape): string {
        $blocked = !empty($state['blocked']);
        $summary = $blocked ? $tr('registration_blocked_summary') : $tr('registration_allowed_summary');
        $changedAt = (string)($state['changedAt'] ?? '-');
        $changedBy = (string)($state['changedBy'] ?? '-');
        $message = (string)($state['message'] ?? '');
        $buttonLabel = $blocked ? $tr('registration_allow') : $tr('registration_block');
        $buttonClass = $blocked ? 'gray' : 'danger';
        $actionValue = $blocked ? '0' : '1';
        $canToggle = $this->isGranted('ROLE_ADMIN_SUPER');

        $statusLabel = $blocked ? $tr('registration_blocked_state') : $tr('registration_allowed_state');
        $accent = $blocked ? 'style="border-left:4px solid #b00020;background:linear-gradient(180deg,#fff 0%,#fff9f9 100%);"' : 'style="border-left:4px solid #0b7a3a;background:linear-gradient(180deg,#fff 0%,#f7fbf8 100%);"';

        return '<div class="card section" id="registration-block" ' . $accent . '>'
            . '<h3 style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;"><span>' . $escape($tr('registration_block')) . '</span><span class="badge ' . ($blocked ? 'warn' : 'ok') . '">' . $escape($blocked ? $tr('yes') : $tr('no')) . '</span></h3>'
            . '<div class="summary" style="font-size:14px;font-weight:600;">' . $escape($statusLabel) . '</div>'
            . '<div class="summary" style="margin-top:-2px;">' . $escape($summary) . '</div>'
            . '<div class="hint" style="display:grid;gap:4px;border-top:1px solid rgba(0,0,0,.06);padding-top:10px;">'
            . '<div><b>' . $escape($tr('registration_changed_at')) . ':</b> <span class="mono">' . $escape($changedAt) . '</span></div>'
            . '<div><b>' . $escape($tr('registration_changed_by')) . ':</b> <span class="mono">' . $escape($changedBy) . '</span></div>'
            . '<div><b>' . $escape($tr('registration_message')) . ':</b> ' . $escape($message) . '</div>'
            . '</div>'
            . ($canToggle
                ? '<div style="margin-top:12px;color:#666;font-size:12px;">' . $escape($tr('registration_block_hint')) . '</div><form method="post" action="/admin/users/registration-block" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
                    . '<input type="hidden" name="_token" value="' . $escape($this->csrfToken('admin_registration_block_toggle')) . '" />'
                    . '<input type="hidden" name="blocked" value="' . $escape($actionValue) . '" />'
                    . '<button type="submit" class="' . $buttonClass . '">' . $escape($buttonLabel) . '</button>'
                    . '</form>'
                : '<div style="margin-top:12px;color:#666;font-size:12px;">' . $escape($tr('registration_block_superadmin_only')) . '</div>')
            . '</div>';
    }

    /**
     * @param array<int, array{user: User, problems: string[]}> $problemUsers
     * @param array<int, array{user: User, blockedUntil: int}> $blockedUsers
     * @param array<int, array{user: User, pending: array}> $pendingLimitUsers
     * @return array<int, array{level:string,title:string,message:string,link?:string}>
     */
    private function buildDashboardAlerts(array $stats, array $blockedUsers, array $pendingLimitUsers, array $problemUsers, string $locale): array {
        $tr = $this->translator($locale);
        $alerts = [];

        if ((int)($stats['blocked'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'warn',
                'title' => $tr('blocked_users'),
                'message' => sprintf($tr('blocked_users_alert'), (int)$stats['blocked']),
                'link' => '/admin/health',
            ];
        }

        if ((int)($stats['pendingLimits'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'warn',
                'title' => $tr('pending_limit_approvals'),
                'message' => sprintf($tr('pending_limits_alert'), (int)$stats['pendingLimits']),
                'link' => '/admin/health',
            ];
        }

        if ($problemUsers) {
            $firstProblem = $problemUsers[0];
            $alerts[] = [
                'level' => 'bad',
                'title' => $tr('detected_issues'),
                'message' => sprintf($tr('problem_users_alert'), count($problemUsers), (string)($firstProblem['user']->getEmail() ?? '')),
                'link' => '/admin/health',
            ];
        }

        if (!$alerts) {
            $alerts[] = [
                'level' => 'ok',
                'title' => $tr('alerts_title'),
                'message' => $tr('no_alerts'),
                'link' => '/admin/health',
            ];
        }

        return $alerts;
    }

    private function buildRecentRegistrationSeries(EntityManagerInterface $em, int $days): array {
        $series = $this->initializeDailySeries($days);
        $from = (new \DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');
        $rows = $em->getRepository(User::class)->createQueryBuilder('u')
            ->select('u.regDate AS regDate')
            ->andWhere('u.regDate >= :from')
            ->setParameter('from', $from)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $regDate = $row['regDate'] ?? null;
            if ($regDate instanceof \DateTimeInterface) {
                $key = $regDate->format('Y-m-d');
                if (isset($series[$key])) {
                    $series[$key]++;
                }
            }
        }
        return $series;
    }

    private function buildRecentAdminAuthFailureSeries(AdminPanelAccountStore $store, int $days): array {
        $series = $this->initializeDailySeries($days);
        foreach ($store->getAuditTail(2000) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $event = (string)($row['event'] ?? '');
            if (!in_array($event, ['admin_login_failure', 'admin_2fa_failed'], true)) {
                continue;
            }
            $ts = (string)($row['ts'] ?? '');
            if ($ts === '') {
                continue;
            }
            try {
                $date = (new \DateTimeImmutable($ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
            } catch (\Throwable $e) {
                continue;
            }
            if (isset($series[$date])) {
                $series[$date]++;
            }
        }
        return $series;
    }

    private function initializeDailySeries(int $days): array {
        $series = [];
        $today = new \DateTimeImmutable('today');
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $today->modify('-' . $offset . ' days')->format('Y-m-d');
            $series[$date] = 0;
        }
        return $series;
    }

    private function renderBarChart(array $series, string $color, string $suffix, string $rangeLabel, string $maxLabel, string $totalLabel): string {
        if (!$series) {
            return '<div style="color:#666;font-size:12px;">No data.</div>';
        }
        $values = array_values($series);
        $max = max(1, ...$values);
        $barWidth = 10;
        $gap = 4;
        $padding = 12;
        $height = 140;
        $width = $padding * 2 + count($values) * ($barWidth + $gap) - $gap;
        $bars = '';
        foreach ($values as $index => $value) {
            $barHeight = $max > 0 ? max(2, (int)round(($value / $max) * 100)) : 2;
            $x = $padding + $index * ($barWidth + $gap);
            $y = $height - 20 - $barHeight;
            $label = array_keys($series)[$index];
            $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . $barWidth . '" height="' . $barHeight . '" rx="2" fill="' . htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><title>' . htmlspecialchars($label . ': ' . $value . ' ' . $suffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title></rect>';
        }
        $first = array_key_first($series);
        $last = array_key_last($series);
        $total = array_sum($values);
        return '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="chart">'
            . '<line x1="' . $padding . '" y1="' . ($height - 20) . '" x2="' . ($width - $padding) . '" y2="' . ($height - 20) . '" stroke="#d5d5d5" stroke-width="1" />'
            . $bars
            . '</svg>'
            . '<div class="chart-meta"><span>' . htmlspecialchars($rangeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ' . htmlspecialchars((string)$first, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -> ' . htmlspecialchars((string)$last, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span><span>' . htmlspecialchars($maxLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ' . $max . '</span><span>' . htmlspecialchars($totalLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ' . $total . '</span></div>';
    }

    private function renderUserDetailsHtml(User $user, string $msg, string $err, string $locale): string {
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tr = $this->translator($locale);
        $id = (int)$user->getId();
        $displayCode = $this->formatUserCode($user);
        $blockSummary = $this->getUserBlockSummary($user);
        $blockedUntil = (int)$blockSummary['until'];
        $blockedNow = (bool)$blockSummary['active'];
        $pending = $user->getPreference(self::PREF_PENDING_LIMITS, null);
        $limitsLock = (bool)$user->getPreference(self::PREF_LIMITS_SELF_UPDATE_LOCKED, false);
        $actionQs = '?return=details';
        $canWrite = $this->isGranted('ROLE_ADMIN_OPERATOR') || $this->isGranted('ROLE_ADMIN_SUPER');
        $canSuper = $this->isGranted('ROLE_ADMIN_SUPER');
        $canDelete = $this->isGranted('ROLE_ADMIN_SUPER');

        $notice = '';
        if ($msg !== '') {
            $notice .= '<div class="notice ok">' . $escape($msg) . '</div>';
        }
        if ($err !== '') {
            $notice .= '<div class="notice bad">' . $escape($err) . '</div>';
        }

        $locations = method_exists($user, 'getLocations') ? $user->getLocations() : [];
        $accessIds = method_exists($user, 'getAccessIDS') ? $user->getAccessIDS() : [];

        $locationsHtml = '';
        foreach ($locations as $location) {
            $accessIdCount = method_exists($location, 'getAccessIds') ? $location->getAccessIds()->count() : 0;
            $caption = method_exists($location, 'getCaption') ? (string)$location->getCaption() : '';
            $locationsHtml .= '<tr><td class="mono">' . (int)$location->getId() . '</td><td>' . $escape($caption) . '</td><td class="mono">' . $accessIdCount . '</td></tr>';
        }
        if ($locationsHtml === '') {
            $locationsHtml = '<tr><td colspan="3" style="color:#666;">No locations.</td></tr>';
        }

        $accessIdsHtml = '';
        foreach ($accessIds as $accessId) {
            $locationCount = method_exists($accessId, 'getLocations') ? $accessId->getLocations()->count() : 0;
            $accessIdsHtml .= '<tr><td class="mono">' . (int)$accessId->getId() . '</td><td class="mono">' . $locationCount . '</td></tr>';
        }
        if ($accessIdsHtml === '') {
            $accessIdsHtml = '<tr><td colspan="2" style="color:#666;">No access IDs.</td></tr>';
        }

        $limitsHtml = '';
        foreach (self::CURRENT_USER_LIMIT_FIELDS as $publicField => $entityField) {
            $value = EntityUtils::getField($user, $entityField);
            $pendingValue = is_array($pending) && is_array($pending['limits'] ?? null) && array_key_exists($publicField, $pending['limits']) ? (string)$pending['limits'][$publicField] : '';
            $limitsHtml .= '<tr><td>' . $escape($publicField) . '</td><td class="mono">' . $escape((string)$value) . '</td><td class="mono">' . $escape($pendingValue) . '</td></tr>';
        }

        $problems = $this->detectUserProblems($user);
        $problemsHtml = '';
        if ($problems) {
            $items = '';
            foreach ($problems as $problem) {
                $items .= '<li>' . $escape($problem) . '</li>';
            }
            $problemsHtml = '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;">Problems</h3><ul>' . $items . '</ul></div>';
        }

        $blockHistoryHtml = '';
        foreach ($this->getUserBlockHistory($user) as $entry) {
            $blockHistoryHtml .= '<tr><td class="mono">' . $escape(date('Y-m-d H:i', (int)($entry['ts'] ?? 0))) . '</td><td>' . $escape((string)($entry['event'] ?? '')) . '</td><td class="mono">' . $escape($this->formatBlockScopes((array)($entry['scopes'] ?? []), $locale)) . '</td><td class="mono">' . ($entry['until'] ? $escape(date('Y-m-d H:i', (int)$entry['until'])) : '-') . '</td><td>' . $escape((string)($entry['reason'] ?? '')) . '</td><td class="mono">' . $escape((string)($entry['admin'] ?? '')) . '</td></tr>';
        }
        if ($blockHistoryHtml === '') {
            $blockHistoryHtml = '<tr><td colspan="6" style="color:#666;">' . $escape($tr('no_block_history')) . '</td></tr>';
        }
        $schedule = $this->getUserBlockSchedule($user);
        $scheduleDays = $schedule ? $this->formatScheduleSummary($schedule, $locale) : '';
        $scheduleForm = '';
        $scheduleDeleteForm = '';
        $resetConfirmPrompt = json_encode(sprintf($tr('confirm_password_reset_prompt'), $displayCode, (string)$user->getEmail()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $resetForceConfirmPrompt = json_encode(sprintf($tr('confirm_password_reset_force_prompt'), $displayCode, (string)$user->getEmail()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($canWrite) {
            $presetOptions = [
                'none' => ['label' => $tr('schedule_preset_none'), 'days' => [], 'from' => '', 'to' => ''],
                'work_hours' => ['label' => $tr('preset_work_hours'), 'days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '16:00'],
                'weekend' => ['label' => $tr('preset_weekend'), 'days' => [6, 7], 'from' => '00:00', 'to' => '23:59'],
                'night' => ['label' => $tr('preset_night'), 'days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '22:00', 'to' => '06:00'],
            ];
            $dayLabels = $locale === 'en'
                ? [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']
                : [1 => 'Pon', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sob', 7 => 'Nd'];
            $scheduleForm .= '<form method="post" action="/admin/users/' . $id . '/block-schedule' . $actionQs . '">';
            $scheduleForm .= $this->csrfField('admin_user_block_schedule_' . $id);
            $scheduleForm .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center;"><span>' . $escape($tr('schedule_preset')) . ':</span><select class="schedule-preset">';
            foreach ($presetOptions as $presetKey => $preset) {
                $daysData = htmlspecialchars(implode(',', $preset['days']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $scheduleForm .= '<option value="' . $escape($presetKey) . '" data-days="' . $daysData . '" data-from="' . $escape($preset['from']) . '" data-to="' . $escape($preset['to']) . '">' . $escape($preset['label']) . '</option>';
            }
            $scheduleForm .= '</select></div>';
            foreach ($dayLabels as $dayNumber => $dayLabel) {
                $checked = in_array($dayNumber, (array)($schedule['days'] ?? [1,2,3,4,5]), true) ? ' checked' : '';
                $scheduleForm .= '<label><input type="checkbox" class="schedule-day" name="days[]" value="' . $dayNumber . '"' . $checked . ' /> ' . $dayLabel . '</label> ';
            }
            $scheduleForm .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">';
            $scheduleForm .= '<input type="time" class="schedule-from" name="fromTime" value="' . $escape((string)($schedule['from'] ?? '08:00')) . '" />';
            $scheduleForm .= '<input type="time" class="schedule-to" name="toTime" value="' . $escape((string)($schedule['to'] ?? '16:00')) . '" />';
            $scheduleForm .= '<label><input type="checkbox" name="scopes[]" value="www"' . (in_array('www', (array)($schedule['scopes'] ?? self::BLOCK_SCOPES), true) ? ' checked' : '') . ' /> WWW</label>';
            $scheduleForm .= '<label><input type="checkbox" name="scopes[]" value="api"' . (in_array('api', (array)($schedule['scopes'] ?? self::BLOCK_SCOPES), true) ? ' checked' : '') . ' /> API</label>';
            $scheduleForm .= '<label><input type="checkbox" name="scopes[]" value="mqtt"' . (in_array('mqtt', (array)($schedule['scopes'] ?? self::BLOCK_SCOPES), true) ? ' checked' : '') . ' /> MQTT</label>';
            $scheduleForm .= '</div>';
            $scheduleForm .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">';
            $scheduleForm .= '<input name="reason" placeholder="' . $escape($tr('block_reason_placeholder')) . '" value="' . $escape((string)($schedule['reason'] ?? '')) . '" />';
            $scheduleForm .= '<button type="submit">' . $escape($tr('save_schedule')) . '</button>';
            $scheduleForm .= '</div></form>';
            if ($schedule) {
                $scheduleDeleteForm = '<form method="post" action="/admin/users/' . $id . '/block-schedule/delete' . $actionQs . '" style="margin-top:8px;">' . $this->csrfField('admin_user_delete_block_schedule_' . $id) . '<button type="submit">' . $escape($tr('delete_schedule')) . '</button></form>';
            }
        }

        $html = $this->adminUiLayoutOpen(
            $escape($tr('user_details_title')),
            'users',
            $this->isGranted('ROLE_ADMIN_SUPER'),
            '.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px;}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;}.copy-btn{margin-left:6px;width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:11px;border-radius:8px;vertical-align:middle;background:#fff;color:#0b7a3a;border-color:#d7e7db;}.copy-btn.copied{background:#e7f6ee;border-color:#bfe8cf;color:#0b7a3a;}.ui-page-tools{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 14px 0;flex-wrap:wrap;}.ui-page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}.ui-page-actions a{padding:6px 10px;border-radius:999px;background:#f6f8f9;border:1px solid #dfe5ea;text-decoration:none !important;}'
        );
        $html .= '<div class="ui-page-tools">'
            . '<div class="ui-muted"><a href="/admin/users">← ' . $escape($tr('title_users')) . '</a></div>'
            . '<div class="ui-page-actions"><a href="/admin/account">Konto</a><a href="/admin/security-log">Log bezpieczeństwa</a><a href="/admin/logout">Wyloguj</a></div>'
            . '</div>'
            . '<h1>User: ' . $escape((string)$user->getEmail()) . '</h1>'
            . $notice
            . '<div class="grid"><div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">' . $escape($tr('account_section')) . '</h3><table><tbody>'
            . '<tr><th>ID</th><td class="mono">' . $id . '</td></tr>'
            . '<tr><th>' . $escape($tr('user_code_label')) . '</th><td class="mono">' . $escape($displayCode) . $this->renderCopyCodeButton($displayCode, $tr('copy')) . '</td></tr>'
            . '<tr><th>Email</th><td>' . $escape((string)$user->getEmail()) . '</td></tr>'
            . '<tr><th>Enabled</th><td>' . ($user->isEnabled() ? 'yes' : 'no') . '</td></tr>'
            . '<tr><th>' . $escape($tr('blocked')) . '</th><td>' . ($blockedNow ? $escape($this->formatBlockStatusText($blockSummary, $locale)) : $escape($tr('no'))) . '</td></tr>'
            . '<tr><th>' . $escape($tr('block_scopes')) . '</th><td>' . $escape($this->formatBlockScopes((array)$blockSummary['scopes'], $locale)) . '</td></tr>'
            . '<tr><th>' . $escape($tr('block_reason')) . '</th><td>' . $escape((string)$blockSummary['reason']) . '</td></tr>'
            . '<tr><th>' . $escape($tr('schedule_block')) . '</th><td>' . ($schedule ? $escape($scheduleDays) : '-') . '</td></tr>'
            . '<tr><th>Locale</th><td>' . $escape((string)(method_exists($user, 'getLocale') ? $user->getLocale() : '')) . '</td></tr>'
            . '<tr><th>Limits self-update</th><td>' . ($limitsLock ? 'locked' : 'allowed') . '</td></tr>'
            . '</tbody></table></div>'
            . $problemsHtml
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">Limits</h3><table><thead><tr><th>Field</th><th>Current</th><th>Pending</th></tr></thead><tbody>' . $limitsHtml . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">' . $escape($tr('locations_section')) . '</h3><table><thead><tr><th>ID</th><th>Caption</th><th>Access IDs</th></tr></thead><tbody>' . $locationsHtml . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">' . $escape($tr('access_ids_section')) . '</h3><table><thead><tr><th>ID</th><th>Locations</th></tr></thead><tbody>' . $accessIdsHtml . '</tbody></table></div>'
            . '</div><div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">Actions</h3><div class="actions">'
            . ($canWrite ? (
                '<form method="post" action="/admin/users/' . $id . '/reset-password' . $actionQs . '" onsubmit="return confirm(' . $resetConfirmPrompt . ');">' . $this->csrfField('admin_user_reset_password_' . $id) . '<button type="submit">' . $escape($tr('send_password_reset_link')) . '</button></form>'
                . ($canSuper ? '<form method="post" action="/admin/users/' . $id . '/reset-password' . $actionQs . '" onsubmit="return confirm(' . $resetForceConfirmPrompt . ');">' . $this->csrfField('admin_user_reset_password_' . $id) . '<input type="hidden" name="force" value="1" /><button type="submit">' . $escape($tr('send_password_reset_link_force')) . '</button></form>' : '')
                . ($user->isEnabled()
                    ? '<form method="post" action="/admin/users/' . $id . '/toggle-enabled' . $actionQs . '">' . $this->csrfField('admin_user_toggle_enabled_' . $id) . '<button type="submit">Disable</button></form>'
                    : '<form method="post" action="/admin/users/' . $id . '/confirm' . $actionQs . '">' . $this->csrfField('admin_user_confirm_' . $id) . '<button type="submit">Confirm account</button></form>')
                . '<form method="post" action="/admin/users/' . $id . '/' . ($blockedNow ? 'unblock' : 'block') . $actionQs . '">' . $this->csrfField('admin_user_' . ($blockedNow ? 'unblock_' : 'block_') . $id)
                . ($blockedNow ? '<button type="submit">Unblock</button>' : '<select name="seconds"><option value="3600">1h</option><option value="21600">6h</option><option value="43200">12h</option><option value="86400">1d</option><option value="604800">1w</option><option value="1209600">2w</option><option value="2592000">1m</option></select><label><input type="checkbox" name="scopes[]" value="www" checked /> WWW</label><label><input type="checkbox" name="scopes[]" value="api" checked /> API</label><label><input type="checkbox" name="scopes[]" value="mqtt" checked /> MQTT</label><input name="reason" placeholder="' . $escape($tr('block_reason_placeholder')) . '" value="" /><button type="submit">Block</button>')
                . '</form>'
                . '<form method="post" action="/admin/users/' . $id . '/limits/toggle-lock' . $actionQs . '">' . $this->csrfField('admin_user_toggle_limits_lock_' . $id) . '<button type="submit">' . ($limitsLock ? 'Unlock limits' : 'Lock limits') . '</button></form>'
                . (is_array($pending) ? '<form method="post" action="/admin/users/' . $id . '/limits/approve' . $actionQs . '">' . $this->csrfField('admin_user_approve_limits_' . $id) . '<button type="submit">Approve limits</button></form><form method="post" action="/admin/users/' . $id . '/limits/reject' . $actionQs . '">' . $this->csrfField('admin_user_reject_limits_' . $id) . '<button type="submit">Reject limits</button></form>' : '')
            ) : '<span style="color:#666;">read-only</span>')
            . '</div></div>'
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">' . $escape($tr('block_history')) . '</h3><table><thead><tr><th>' . $escape($tr('registered')) . '</th><th>' . $escape($tr('event')) . '</th><th>' . $escape($tr('block_scopes')) . '</th><th>' . $escape($tr('blocked_until')) . '</th><th>' . $escape($tr('block_reason')) . '</th><th>Admin</th></tr></thead><tbody>' . $blockHistoryHtml . '</tbody></table></div>'
            . ($canDelete ? '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">Delete user</h3><form method="post" action="/admin/users/' . $id . '/delete' . $actionQs . '">' . $this->csrfField('admin_user_delete_' . $id) . '<input name="confirmEmail" placeholder="Type exact email to delete" value="" style="min-width:100%;box-sizing:border-box;margin-bottom:8px;" /><button type="submit" class="danger">Delete</button></form></div>' : '')
            . '<div class="card"><h3 style="margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;">' . $escape($tr('schedule_block')) . '</h3>' . ($schedule ? '<div style="margin-bottom:8px;color:#333;">' . $escape($scheduleDays) . '</div>' : '') . $scheduleForm . $scheduleDeleteForm . '</div>'
            . '</div></div>'
            . '<script>(function(){document.addEventListener("change",function(event){var select=event.target.closest(".schedule-preset");if(!select){return;}var form=select.form;if(!form){return;}var option=select.options[select.selectedIndex];var days=(option.getAttribute("data-days")||"").split(",").filter(Boolean);form.querySelectorAll(".schedule-day").forEach(function(input){input.checked=days.indexOf(input.value)!==-1;});var from=option.getAttribute("data-from")||"";var to=option.getAttribute("data-to")||"";var fromInput=form.querySelector(".schedule-from");var toInput=form.querySelector(".schedule-to");if(fromInput&&from){fromInput.value=from;}if(toInput&&to){toInput.value=to;}});})();</script>'
            . $this->renderCopyCodeScript($tr('copied'))
            . $this->adminUiLayoutClose();
        return $html;
    }

    /**
     * @return string[]
     */
    private function detectUserProblems(User $user): array {
        $problems = [];
        $locations = method_exists($user, 'getLocations') ? $user->getLocations() : null;
        $accessIds = method_exists($user, 'getAccessIDS') ? $user->getAccessIDS() : null;

        if ($locations && $locations->isEmpty()) {
            $problems[] = 'no locations';
        }
        if ($accessIds && $accessIds->isEmpty()) {
            $problems[] = 'no access IDs';
        }
        if ($locations && !$locations->isEmpty()) {
            $hasRelation = false;
            foreach ($locations as $location) {
                if (method_exists($location, 'getAccessIds') && $location->getAccessIds()->count() > 0) {
                    $hasRelation = true;
                    break;
                }
            }
            if (!$hasRelation) {
                $problems[] = 'missing AccessID-location relation';
            }
        }

        return $problems;
    }

    private function getUserBlockProfile(User $user): array {
        $profile = $user->getPreference(self::PREF_BLOCK_PROFILE, null);
        if (!is_array($profile)) {
            $legacyUntil = (int)($user->getPreference(self::PREF_BLOCKED_UNTIL, 0) ?? 0);
            if ($legacyUntil > 0) {
                $profile = [
                    'until' => $legacyUntil,
                    'scopes' => [self::BLOCK_SCOPE_WWW, self::BLOCK_SCOPE_API, self::BLOCK_SCOPE_MQTT],
                    'reason' => '',
                    'createdAt' => null,
                    'createdBy' => '',
                ];
            } else {
                $profile = [];
            }
        }
        return [
            'until' => max(0, (int)($profile['until'] ?? 0)),
            'scopes' => $this->normalizeBlockScopes($profile['scopes'] ?? []),
            'reason' => trim((string)($profile['reason'] ?? '')),
            'createdAt' => isset($profile['createdAt']) ? (int)$profile['createdAt'] : null,
            'createdBy' => trim((string)($profile['createdBy'] ?? '')),
        ];
    }

    private function getUserBlockSummary(User $user): array {
        $profile = $this->getUserBlockProfile($user);
        $schedule = $this->getUserBlockSchedule($user);
        $temporaryActive = $profile['until'] > time() && count($profile['scopes']) > 0;
        $scheduleActive = $this->isScheduleBlockActiveNow($schedule);
        $activeUntil = $temporaryActive ? $profile['until'] : ($scheduleActive ? $this->getScheduleActiveUntil($schedule) : 0);
        $activeScopes = $temporaryActive ? $profile['scopes'] : ($scheduleActive ? $schedule['scopes'] : []);
        $activeReason = $temporaryActive ? $profile['reason'] : ($scheduleActive ? $schedule['reason'] : '');
        return [
            'active' => $temporaryActive || $scheduleActive,
            'until' => $activeUntil,
            'scopes' => $activeScopes,
            'reason' => $activeReason,
            'createdAt' => $temporaryActive ? $profile['createdAt'] : ($schedule['createdAt'] ?? null),
            'createdBy' => $temporaryActive ? $profile['createdBy'] : ($schedule['createdBy'] ?? ''),
            'source' => $temporaryActive ? 'temporary' : ($scheduleActive ? 'schedule' : null),
            'schedule' => $schedule,
        ];
    }

    private function getUserBlockSchedule(User $user): array {
        $schedule = $user->getPreference(self::PREF_BLOCK_SCHEDULE, null);
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
            'createdAt' => isset($schedule['createdAt']) ? (int)$schedule['createdAt'] : null,
            'createdBy' => trim((string)($schedule['createdBy'] ?? '')),
        ];
    }

    private function normalizeBlockScopes($rawScopes): array {
        if (!is_array($rawScopes)) {
            $rawScopes = [$rawScopes];
        }
        $scopes = [];
        foreach ($rawScopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (in_array($scope, self::BLOCK_SCOPES, true) && !in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }
        return $scopes;
    }

    private function hasWebOrApiScope(array $scopes): bool {
        return in_array(self::BLOCK_SCOPE_WWW, $scopes, true) || in_array(self::BLOCK_SCOPE_API, $scopes, true);
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

    private function applyMqttBlockState(User $user, bool $shouldBlock): void {
        if ($shouldBlock) {
            if ($user->isMqttBrokerEnabled()) {
                $user->setPreference(self::PREF_PREV_MQTT_ENABLED, true);
                $user->setMqttBrokerEnabled(false);
            }
            return;
        }
        $prev = (bool)$user->getPreference(self::PREF_PREV_MQTT_ENABLED, false);
        if ($prev) {
            $user->setMqttBrokerEnabled(true);
        }
        $user->setPreference(self::PREF_PREV_MQTT_ENABLED, false);
    }

    private function appendBlockHistoryEntry(User $user, array $entry): void {
        $history = $user->getPreference(self::PREF_BLOCK_HISTORY, null);
        if (!is_array($history)) {
            $history = [];
        }
        array_unshift($history, [
            'event' => (string)($entry['event'] ?? ''),
            'ts' => (int)($entry['ts'] ?? time()),
            'until' => (int)($entry['until'] ?? 0),
            'reason' => trim((string)($entry['reason'] ?? '')),
            'scopes' => $this->normalizeBlockScopes($entry['scopes'] ?? []),
            'admin' => trim((string)($entry['admin'] ?? '')),
        ]);
        $user->setPreference(self::PREF_BLOCK_HISTORY, array_slice($history, 0, 25));
    }

    private function getUserBlockHistory(User $user): array {
        $history = $user->getPreference(self::PREF_BLOCK_HISTORY, null);
        return is_array($history) ? $history : [];
    }

    private function formatBlockScopes(array $scopes, string $locale): string {
        $labels = [
            'en' => [self::BLOCK_SCOPE_WWW => 'WWW', self::BLOCK_SCOPE_API => 'API', self::BLOCK_SCOPE_MQTT => 'MQTT'],
            'pl' => [self::BLOCK_SCOPE_WWW => 'WWW', self::BLOCK_SCOPE_API => 'API', self::BLOCK_SCOPE_MQTT => 'MQTT'],
        ];
        $locale = isset($labels[$locale]) ? $locale : 'pl';
        $values = [];
        foreach ($scopes as $scope) {
            if (isset($labels[$locale][$scope])) {
                $values[] = $labels[$locale][$scope];
            }
        }
        return implode(', ', $values);
    }

    private function formatBlockStatusText(array $summary, string $locale): string {
        $tr = $this->translator($locale);
        if (!(bool)($summary['active'] ?? false)) {
            return $tr('no');
        }
        $text = $tr('yes') . ' (' . strtolower($tr('blocked_until')) . ' ' . date('Y-m-d H:i', (int)$summary['until']) . ')';
        $scopes = $this->formatBlockScopes((array)($summary['scopes'] ?? []), $locale);
        if ($scopes !== '') {
            $text .= ' · ' . $scopes;
        }
        $reason = trim((string)($summary['reason'] ?? ''));
        if ($reason !== '') {
            $text .= ' · ' . $reason;
        }
        if (($summary['source'] ?? null) === 'schedule' && !empty($summary['schedule'])) {
            $text .= ' · ' . $this->formatScheduleSummary($summary['schedule'], $locale);
        }
        return $text;
    }

    private function formatScheduleSummary(array $schedule, string $locale): string {
        if (!$schedule) {
            return '';
        }
        $dayNames = $locale === 'en'
            ? [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']
            : [1 => 'Pon', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sob', 7 => 'Nd'];
        $days = [];
        foreach ((array)$schedule['days'] as $day) {
            if (isset($dayNames[$day])) {
                $days[] = $dayNames[$day];
            }
        }
        return implode(',', $days) . ' ' . $schedule['from'] . '-' . $schedule['to'];
    }

    private function formatUserCode(User $user): string {
        return 'USR-' . str_pad((string)((int)$user->getId()), 6, '0', STR_PAD_LEFT);
    }

    private function renderCopyCodeButton(string $code, string $label): string {
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<button type="button" class="copy-btn" data-copy-text="' . $safeCode . '" title="' . $safeLabel . '" aria-label="' . $safeLabel . '">'
            . '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16 1H6C4.9 1 4 1.9 4 3v12h2V3h10V1zm3 4H10C8.9 5 8 5.9 8 7v14c0 1.1.9 2 2 2h9c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H10V7h9v14z"/></svg>'
            . '</button>';
    }

    private function renderCopyCodeScript(string $copiedLabel): string {
        $safeCopied = htmlspecialchars($copiedLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<script>(function(){function copyText(text){if(navigator.clipboard&&navigator.clipboard.writeText){return navigator.clipboard.writeText(text);}var field=document.createElement("textarea");field.value=text;field.setAttribute("readonly","readonly");field.style.position="absolute";field.style.left="-9999px";document.body.appendChild(field);field.select();try{document.execCommand("copy");}finally{document.body.removeChild(field);}return Promise.resolve();}document.addEventListener("click",function(event){var button=event.target.closest(".copy-btn");if(!button){return;}copyText(button.getAttribute("data-copy-text")||"").then(function(){var originalTitle=button.getAttribute("title")||"";button.classList.add("copied");button.setAttribute("title","' . $safeCopied . '");button.setAttribute("aria-label","' . $safeCopied . '");setTimeout(function(){button.classList.remove("copied");button.setAttribute("title",originalTitle);button.setAttribute("aria-label",originalTitle);},1200);});});})();</script>';
    }

    private function rejectInvalidCsrf(Request $request, string $tokenId): ?RedirectResponse {
        if (!$this->isCsrfTokenValid($tokenId, (string)$request->request->get('_token', ''))) {
            return $this->redirectWith($request, 'err', 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.');
        }
        return null;
    }

    private function csrfField(string $tokenId): string {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->csrfToken($tokenId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
    }

    private function csrfToken(string $tokenId): string {
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = $this->get('security.csrf.token_manager');
        return $csrfTokenManager->getToken($tokenId)->getValue();
    }
}
