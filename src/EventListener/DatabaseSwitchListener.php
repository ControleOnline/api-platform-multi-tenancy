<?php

namespace ControleOnline\EventListener;

use ControleOnline\Service\DatabaseSwitchService;
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
            throw new Exception(sprintf('%s', $e), 1);
        }
    }
}
