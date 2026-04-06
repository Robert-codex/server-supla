<?php
namespace SuplaBundle\EventListener;

use SuplaBundle\Entity\Main\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class BlockedUserRequestListener {
    private const PREF_BLOCKED_UNTIL = 'admin.blockedUntil';
    private const PREF_BLOCK_PROFILE = 'admin.blockProfile';

    public function __construct(private TokenStorageInterface $tokenStorage) {
    }

    public function onKernelController(FilterControllerEvent $event): void {
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }
        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }
        $path = (string)$event->getRequest()->getPathInfo();
        $scope = str_starts_with($path, '/api/') ? 'api' : 'www';
        $block = $this->getBlockState($user, $scope);
        if ($block['blocked']) {
            $message = 'Account is temporarily blocked.';
            if ($block['until'] > 0) {
                $message = 'Account is temporarily blocked until ' . date('Y-m-d H:i', $block['until']) . '.';
            }
            if ($block['reason'] !== '') {
                $message .= ' Reason: ' . $block['reason'];
            }
            throw new HttpException(Response::HTTP_LOCKED, $message);
        }
    }

    private function getBlockState(User $user, string $scope): array {
        $profile = $user->getPreference(self::PREF_BLOCK_PROFILE, null);
        if (is_array($profile)) {
            $until = (int)($profile['until'] ?? 0);
            $scopes = array_values(array_intersect(['www', 'api', 'mqtt'], (array)($profile['scopes'] ?? [])));
            if ($until > time() && in_array($scope, $scopes, true)) {
                return [
                    'blocked' => true,
                    'until' => $until,
                    'reason' => trim((string)($profile['reason'] ?? '')),
                ];
            }
        }
        $schedule = $user->getPreference('admin.blockSchedule', null);
        if (is_array($schedule) && $this->isScheduleBlockedNow($schedule, $scope)) {
            return [
                'blocked' => true,
                'until' => $this->getScheduleUntil($schedule),
                'reason' => trim((string)($schedule['reason'] ?? '')),
            ];
        }
        $until = (int)($user->getPreference(self::PREF_BLOCKED_UNTIL, 0) ?? 0);
        return ['blocked' => $until > time(), 'until' => $until, 'reason' => ''];
    }

    private function isScheduleBlockedNow(array $schedule, string $scope): bool {
        $days = array_map('intval', (array)($schedule['days'] ?? []));
        $from = (string)($schedule['from'] ?? '');
        $to = (string)($schedule['to'] ?? '');
        $scopes = array_values(array_intersect(['www', 'api', 'mqtt'], (array)($schedule['scopes'] ?? [])));
        if (!$days || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $to) || !in_array($scope, $scopes, true)) {
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

    private function getScheduleUntil(array $schedule): int {
        [$toHour, $toMinute] = array_map('intval', explode(':', (string)$schedule['to']));
        $until = (new \DateTimeImmutable('now'))->setTime($toHour, $toMinute);
        [$fromHour, $fromMinute] = array_map('intval', explode(':', (string)$schedule['from']));
        if (($fromHour * 60 + $fromMinute) >= ($toHour * 60 + $toMinute) && (((int)date('H')) * 60 + (int)date('i')) >= ($fromHour * 60 + $fromMinute)) {
            $until = $until->modify('+1 day');
        }
        return $until->getTimestamp();
    }
}
