<?php

namespace ControleOnline\EventListener;

use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Exception;

class DatabaseSwitchListener
{
    private $domain;
    private static $tenancy_params;


    public function __construct(
        private DatabaseSwitchService $databaseSwitchService,
        private DomainService $domainService
    ) {
    }

    public function onKernelRequest(RequestEvent $event)
    {
        try {
            if (!self::$tenancy_params)
                self::$tenancy_params = $this->databaseSwitchService->switchDatabaseByDomain(
                    $this->domainService->getDomain()
                );
        } catch (Exception $e) {
            throw new Exception(sprintf('%s', $e), 1);
        }
    }
}
