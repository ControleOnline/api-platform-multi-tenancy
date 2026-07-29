<?php

namespace ControleOnline\EventListener;

use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class DatabaseSwitchListener
{
    public function __construct(
        private DatabaseSwitchService $databaseSwitchService,
        private DomainService $domainService
    ) {}

    public function onKernelRequest(RequestEvent $event)
    {
        if ($_ENV['MULTI_TENANCY'])
            $this->databaseSwitchService->switchDatabaseByDomain(
                $this->domainService->getDomain()
            );
    }
}
