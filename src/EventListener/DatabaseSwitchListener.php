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
            $this->domain = $this->databaseSwitchService->getDomain($event->getRequest());
            if (!self::$tenency_params)
                self::$tenency_params = $this->databaseSwitchService->switchDatabaseByDomain($this->domain);
        } catch (Exception $e) {
            throw new Exception(
                sprintf('Don`t connect on this domain (%s). ', $this->domain)
                    . $e->getMessage(),
                1
            );
        }
    }
}
