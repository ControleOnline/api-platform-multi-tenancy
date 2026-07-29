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
        if (!$_ENV['MULTI_TENANCY']) {
            return;
        }

        if ($this->shouldLetControllerSwitchDatabase($event)) {
            return;
        }

        $this->databaseSwitchService->switchDatabaseByDomain(
            $this->domainService->getDomain()
        );
    }

    private function shouldLetControllerSwitchDatabase(RequestEvent $event): bool
    {
        return preg_match(
            '#^/oauth/mercadolivre/return$#',
            $event->getRequest()->getPathInfo()
        ) === 1;
    }
}
