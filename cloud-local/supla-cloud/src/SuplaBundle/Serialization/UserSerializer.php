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

namespace SuplaBundle\Serialization;

use SuplaBundle\Entity\Main\User;
use SuplaBundle\EventListener\ApiRateLimit\ApiRateLimitStatus;
use SuplaBundle\EventListener\ApiRateLimit\ApiRateLimitStorage;
use SuplaBundle\EventListener\ApiRateLimit\DefaultUserApiRateLimit;
use SuplaBundle\Model\ApiVersions;
use SuplaBundle\Model\TimeProvider;
use SuplaBundle\Model\TwoFactorService;
use SuplaBundle\Repository\UserRepository;
use SuplaBundle\Supla\SuplaServerAware;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

class UserSerializer extends AbstractSerializer implements NormalizerAwareInterface {
    use SuplaServerAware;
    use NormalizerAwareTrait;

    /** @var ApiRateLimitStorage */
    private $apiRateLimitStorage;
    /** @var DefaultUserApiRateLimit */
    private $defaultUserApiRateLimit;
    /** @var TimeProvider */
    private $timeProvider;
    /** @var UserRepository */
    private $userRepository;
    /** @var bool */
    private $apiRateLimitEnabled;
    private TwoFactorService $twoFactorService;

    public function __construct(
        ApiRateLimitStorage $apiRateLimitStorage,
        DefaultUserApiRateLimit $defaultUserApiRateLimit,
        TimeProvider $timeProvider,
        UserRepository $userRepository,
        bool $apiRateLimitEnabled,
        TwoFactorService $twoFactorService
    ) {
        parent::__construct();
        $this->apiRateLimitStorage = $apiRateLimitStorage;
        $this->defaultUserApiRateLimit = $defaultUserApiRateLimit;
        $this->timeProvider = $timeProvider;
        $this->userRepository = $userRepository;
        $this->apiRateLimitEnabled = $apiRateLimitEnabled;
        $this->twoFactorService = $twoFactorService;
    }

    /**
     * @param \SuplaBundle\Entity\Main\User $user
     * @inheritdoc
     */
    protected function addExtraFields(array &$normalized, $user, array $context) {
        if ($this->isSerializationGroupRequested('limits', $context) && $this->apiRateLimitEnabled) {
            $rule = $user->getApiRateLimit() ?: $this->defaultUserApiRateLimit;
            $cacheItem = $this->apiRateLimitStorage->getItem($this->apiRateLimitStorage->getUserKey($user));
            $status = null;
            if ($cacheItem->isHit()) {
                $status = new ApiRateLimitStatus($cacheItem->get());
            }
            if (!$status || $status->isExpired($this->timeProvider)) {
                $status = ApiRateLimitStatus::fromRule($rule, $this->timeProvider);
            }
            $normalized['apiRateLimit'] = [
                'rule' => $rule->toArray(),
                'status' => $status->toArray(),
            ];
            $normalized['limits']['pushNotificationsPerHour'] = $this->suplaServer->getPushNotificationLimit($user);
        }
        if (ApiVersions::V2_4()->isRequestedEqualOrGreaterThan($context)) {
            if (!isset($normalized['relationsCount']) && $this->isSerializationGroupRequested('user.relationsCount', $context)) {
                $normalized['relationsCount'] = $this->userRepository->find($user->getId())->getRelationsCount();
            }
        }
        if ($this->isSerializationGroupRequested('sun', $context)) {
            $time = $this->timeProvider->getTimestamp();
            $lat = $user->getHomeLatitude();
            $lng = $user->getHomeLongitude();
            $sunInfo = date_sun_info($time, $lat, $lng) ?: [];
            $normalized['closestSunset'] = is_int($sunInfo['sunset'] ?? null) ? $sunInfo['sunset'] : null;
            $normalized['closestSunrise'] = is_int($sunInfo['sunrise'] ?? null) ? $sunInfo['sunrise'] : null;
        }
        if ($context['accessToken'] ?? null) {
            $normalized['accessToken'] = $this->normalizer->normalize($context['accessToken'], context: $context);
        }
        if (isset($normalized['preferences']) && is_array($normalized['preferences'])) {
            $normalized['preferences'] = $this->twoFactorService->removeSensitivePreferences($normalized['preferences']);
        }
        $normalized['twoFactor'] = $this->twoFactorService->getPublicState($user);

        // Expose admin-related state to the user UI (without leaking sensitive details).
        $blockProfile = $user->getPreference('admin.blockProfile', null);
        $blockedUntil = (int)($user->getPreference('admin.blockedUntil', 0) ?? 0);
        $blockedReason = '';
        $blockedScopes = ['www', 'api', 'mqtt'];
        if (is_array($blockProfile)) {
            $blockedUntil = (int)($blockProfile['until'] ?? 0);
            $blockedReason = trim((string)($blockProfile['reason'] ?? ''));
            $blockedScopes = array_values(array_intersect(['www', 'api', 'mqtt'], (array)($blockProfile['scopes'] ?? [])));
        }
        $schedule = $user->getPreference('admin.blockSchedule', null);
        if ((!$blockedUntil || $blockedUntil <= $this->timeProvider->getTimestamp()) && is_array($schedule)) {
            $days = array_map('intval', (array)($schedule['days'] ?? []));
            $from = (string)($schedule['from'] ?? '');
            $to = (string)($schedule['to'] ?? '');
            $scheduleScopes = array_values(array_intersect(['www', 'api', 'mqtt'], (array)($schedule['scopes'] ?? [])));
            if ($days && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $from) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $to) && in_array((int)date('N'), $days, true)) {
                [$fromHour, $fromMinute] = array_map('intval', explode(':', $from));
                [$toHour, $toMinute] = array_map('intval', explode(':', $to));
                $nowMinutes = ((int)date('H')) * 60 + (int)date('i');
                $fromMinutes = $fromHour * 60 + $fromMinute;
                $toMinutes = $toHour * 60 + $toMinute;
                $active = $fromMinutes < $toMinutes ? ($nowMinutes >= $fromMinutes && $nowMinutes < $toMinutes) : ($nowMinutes >= $fromMinutes || $nowMinutes < $toMinutes);
                if ($active) {
                    $until = (new \DateTimeImmutable('now'))->setTime($toHour, $toMinute);
                    if ($fromMinutes >= $toMinutes && $nowMinutes >= $fromMinutes) {
                        $until = $until->modify('+1 day');
                    }
                    $blockedUntil = $until->getTimestamp();
                    $blockedReason = trim((string)($schedule['reason'] ?? ''));
                    $blockedScopes = $scheduleScopes;
                }
            }
        }
        if ($blockedUntil > 0) {
            $normalized['accountBlocking'] = [
                'blockedUntil' => $blockedUntil,
                'blocked' => $blockedUntil > $this->timeProvider->getTimestamp(),
                'reason' => $blockedReason,
                'scopes' => $blockedScopes,
            ];
        } else {
            $normalized['accountBlocking'] = [
                'blockedUntil' => null,
                'blocked' => false,
                'reason' => '',
                'scopes' => [],
            ];
        }
        $pendingLimits = $user->getPreference('admin.pendingLimits', null);
        if (is_array($pendingLimits)) {
            $normalized['pendingLimits'] = $pendingLimits;
        } else {
            $normalized['pendingLimits'] = null;
        }
        $normalized['accountLimitsSelfUpdateLocked'] = (bool)$user->getPreference('admin.limitsSelfUpdateLocked', false);
    }

    public function supportsNormalization($entity, $format = null) {
        return $entity instanceof User;
    }
}
