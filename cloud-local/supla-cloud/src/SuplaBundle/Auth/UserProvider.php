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

namespace SuplaBundle\Auth;

use Doctrine\Persistence\ManagerRegistry;
use SuplaBundle\Entity\Main\User;
use SuplaBundle\Model\Audit\FailedAuthAttemptsUserBlocker;
use SuplaBundle\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\LockedException;

class UserProvider extends EntityUserProvider {
    private const PREF_BLOCKED_UNTIL = 'admin.blockedUntil';
    private const PREF_BLOCK_PROFILE = 'admin.blockProfile';

    /** @var FailedAuthAttemptsUserBlocker */
    private $failedAuthAttemptsUserBlocker;
    /** @var UserRepository */
    private $userRepository;

    public function __construct(
        ManagerRegistry $registry,
        FailedAuthAttemptsUserBlocker $failedAuthAttemptsUserBlocker,
        UserRepository $userRepository
    ) {
        parent::__construct($registry, User::class, 'email');
        $this->failedAuthAttemptsUserBlocker = $failedAuthAttemptsUserBlocker;
        $this->userRepository = $userRepository;
    }

    public function loadUserByUsername($username) {
        if (preg_match('/^api_[0-9]+$/', $username)) {
            $user = $this->userRepository->findOneBy(['oauthCompatUserName' => $username]);
            if ($user) {
                $user->setOAuthOldApiCompatEnabled();
            }
            return $user;
        }
        if ($this->failedAuthAttemptsUserBlocker->isAuthenticationFailureLimitExceeded($username)) {
            throw new LockedException();
        }
        /** @var User $user */
        $user = parent::loadUserByUsername($username);
        if (!$user->isEnabled()) {
            throw new DisabledException();
        }
        if ($this->isScopeBlocked($user, ['www', 'api'])) {
            throw new LockedException();
        }
        return $user;
    }

    private function isScopeBlocked(User $user, array $scopes): bool {
        $profile = $user->getPreference(self::PREF_BLOCK_PROFILE, null);
        if (is_array($profile)) {
            $blockedUntil = (int)($profile['until'] ?? 0);
            $blockedScopes = array_values(array_intersect(['www', 'api', 'mqtt'], (array)($profile['scopes'] ?? [])));
            if ($blockedUntil > time() && count(array_intersect($scopes, $blockedScopes)) > 0) {
                return true;
            }
        }
        $schedule = $user->getPreference('admin.blockSchedule', null);
        if (is_array($schedule) && $this->isScheduleBlockedNow($schedule, $scopes)) {
            return true;
        }
        $blockedUntil = (int)($user->getPreference(self::PREF_BLOCKED_UNTIL, 0) ?? 0);
        return $blockedUntil > time();
    }

    private function isScheduleBlockedNow(array $schedule, array $scopes): bool {
        $days = array_map('intval', (array)($schedule['days'] ?? []));
        $from = (string)($schedule['from'] ?? '');
        $to = (string)($schedule['to'] ?? '');
        $scheduleScopes = array_values(array_intersect(['www', 'api', 'mqtt'], (array)($schedule['scopes'] ?? [])));
        if (!$days || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $to) || !count(array_intersect($scopes, $scheduleScopes))) {
            return false;
        }
        $day = (int)date('N');
        if (!in_array($day, $days, true)) {
            return false;
        }
        [$fromHour, $fromMinute] = array_map('intval', explode(':', $from));
        [$toHour, $toMinute] = array_map('intval', explode(':', $to));
        $nowMinutes = ((int)date('H')) * 60 + (int)date('i');
        $fromMinutes = $fromHour * 60 + $fromMinute;
        $toMinutes = $toHour * 60 + $toMinute;
        if ($fromMinutes < $toMinutes) {
            return $nowMinutes >= $fromMinutes && $nowMinutes < $toMinutes;
        }
        return $nowMinutes >= $fromMinutes || $nowMinutes < $toMinutes;
    }
}
