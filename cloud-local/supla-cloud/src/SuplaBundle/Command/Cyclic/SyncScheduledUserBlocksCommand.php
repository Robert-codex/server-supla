<?php

namespace SuplaBundle\Command\Cyclic;

use Doctrine\ORM\EntityManagerInterface;
use SuplaBundle\Entity\Main\User;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncScheduledUserBlocksCommand extends AbstractCyclicCommand {
    private const PREF_BLOCK_SCHEDULE = 'admin.blockSchedule';
    private const PREF_PREV_MQTT_ENABLED = 'admin.prevMqttBrokerEnabled';

    public function __construct(private EntityManagerInterface $em) {
        parent::__construct();
    }

    protected function configure() {
        $this
            ->setName('supla:cyclic:sync-scheduled-user-blocks')
            ->setDescription('Synchronizes recurring user blocks for MQTT.');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        /** @var User[] $users */
        $users = $this->em->getRepository(User::class)->createQueryBuilder('u')->getQuery()->getResult();
        $changed = 0;
        foreach ($users as $user) {
            $schedule = $user->getPreference(self::PREF_BLOCK_SCHEDULE, null);
            if (!is_array($schedule)) {
                continue;
            }
            $scopes = array_values(array_intersect(['www', 'api', 'mqtt'], (array)($schedule['scopes'] ?? [])));
            if (!in_array('mqtt', $scopes, true)) {
                continue;
            }
            $active = $this->isScheduleBlockedNow($schedule);
            if ($active && $user->isMqttBrokerEnabled()) {
                $user->setPreference(self::PREF_PREV_MQTT_ENABLED, true);
                $user->setMqttBrokerEnabled(false);
                $this->em->persist($user);
                $changed++;
                continue;
            }
            if (!$active && (bool)$user->getPreference(self::PREF_PREV_MQTT_ENABLED, false)) {
                $user->setMqttBrokerEnabled(true);
                $user->setPreference(self::PREF_PREV_MQTT_ENABLED, false);
                $this->em->persist($user);
                $changed++;
            }
        }
        if ($changed > 0) {
            $this->em->flush();
        }
        $output->writeln('Scheduled block sync: ' . $changed . ' user(s) updated.');
        return 0;
    }

    public function getIntervalInMinutes(): int {
        return 5;
    }

    private function isScheduleBlockedNow(array $schedule): bool {
        $days = array_map('intval', (array)($schedule['days'] ?? []));
        $from = (string)($schedule['from'] ?? '');
        $to = (string)($schedule['to'] ?? '');
        if (!$days || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $to)) {
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
