<?php

namespace ControleOnline\EventListener;

use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Exception;

class DatabaseSwitchListener
{
    private $domain;
    private static $tenency_params;


    public function __construct(
        private DatabaseSwitchService $databaseSwitchService,
        private DomainService $domainService
    ) {
    }

    public function onKernelRequest(RequestEvent $event)
    {
        try {
            if (!self::$tenency_params)
                self::$tenency_params = $this->databaseSwitchService->switchDatabaseByDomain(
                    $this->domainService->getDomain()
                );
        } catch (Exception $e) {
            throw new Exception(sprintf('%s', $e), 1);
        }
    }
}
