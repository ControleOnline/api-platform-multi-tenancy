<?php

namespace ControleOnline\EventListener;

use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\TimezoneService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Exception;

class DatabaseSwitchListener
{
    private $domain;
    private static $tenancy_params;


    public function __construct(
        private DatabaseSwitchService $databaseSwitchService,
        private DomainService $domainService,
        private Security $security,
        private TimezoneService $timezoneService
    ) {}

    public function onKernelRequest(RequestEvent $event)
    {
        try {
            
            if (!self::$tenancy_params && $_ENV['MULTI_TENANCY']) {
                self::$tenancy_params = $this->databaseSwitchService->switchDatabaseByDomain(
                    $this->domainService->getDomain()
                );

                $this->timezoneService->applyForUser($this->security->getUser());
            }
        } catch (Exception $e) {
            throw new Exception(sprintf('%s', $e), 1);
        }
    }
}
