<?php

namespace ControleOnline\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('tenant-install')]
final class TenantInstallScheduleProvider implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly LockFactory $lockFactory,
        #[Autowire(service: 'cache.scheduler')]
        private readonly CacheInterface $schedulerCache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        if ($this->schedule instanceof Schedule) {
            return $this->schedule;
        }

        $this->schedule = (new Schedule())
            ->lock($this->lockFactory->createLock('scheduler:tenant-install'))
            ->stateful($this->schedulerCache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::cron(
                '* * * * *',
                new RunCommandMessage('tenant:install:pending --limit=3')
            ));

        return $this->schedule;
    }
}
