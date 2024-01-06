<?php

namespace ControleOnline\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Exception;

class DatabaseSwitchListener
{
    private $domain;
    private static $tenency_params;
    private $databaseSwitchService;


    public function __construct(DatabaseSwitchService $DatabaseSwitchService)
    {
        $this->databaseSwitchService = $DatabaseSwitchService;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        try {
            if (!self::$tenency_params)
                self::$tenency_params = $this->databaseSwitchService->switchDatabaseByDomain(
                    $this->databaseSwitchService->getDomain($event->getRequest())
                );
        } catch (Exception $e) {
            throw new Exception(sprintf('Domain (%s) not found', $this->domain), 1);
        }
    }
}
