<?php

namespace SuplaBundle\Command\Admin;

use SuplaBundle\Model\AdminScheduledBackupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunScheduledBackupsCommand extends Command {
    public function __construct(private AdminScheduledBackupManager $manager) {
        parent::__construct();
    }

    protected function configure() {
        $this
            ->setName('supla:admin:run-scheduled-backups')
            ->setDescription('Runs due backup schedules defined in the admin panel.')
            ->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $result = $this->manager->runDueScheduledBackup(false, 'cron');
        $output->writeln((string)($result['message'] ?? 'No scheduled backup due.'));
        return 0;
    }
}
