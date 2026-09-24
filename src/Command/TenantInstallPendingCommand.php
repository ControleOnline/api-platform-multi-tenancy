<?php

namespace ControleOnline\Command;

use ControleOnline\Service\DatabaseSwitchService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'tenant:install:pending',
    description: 'Instala tenants pendentes registrados na tabela master tenancies.',
)]
final class TenantInstallPendingCommand extends Command
{
    private const DEFAULT_LIMIT = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseSwitchService $databaseSwitchService,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct('tenant:install:pending');
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Quantidade maxima de tenants por execucao.', self::DEFAULT_LIMIT)
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Instala apenas um dominio especifico pendente.')
            ->addOption('ensure-schema-only', null, InputOption::VALUE_NONE, 'Apenas garante as colunas de instalacao no master.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('tenant:install:pending', 55.0);
        if (!$lock->acquire()) {
            $output->writeln('[tenant:install:pending] ignored | another installer is running');

            return Command::SUCCESS;
        }

        try {
            $this->databaseSwitchService->switchBackToOriginalDatabase();
            $this->ensureInstallColumns();

            if ((bool) $input->getOption('ensure-schema-only')) {
                $output->writeln('[tenant:install:pending] schema ok');

                return Command::SUCCESS;
            }

            $limit = $this->normalizePositiveInt(
                $input->getOption('limit'),
                self::DEFAULT_LIMIT,
                20
            );
            $pendingTenants = $this->findPendingTenants(
                $limit,
                trim((string) $input->getOption('domain'))
            );

            if ($pendingTenants === []) {
                $output->writeln('[tenant:install:pending] no pending tenants');

                return Command::SUCCESS;
            }

            $exitCode = Command::SUCCESS;
            foreach ($pendingTenants as $tenant) {
                if (!$this->claimTenant((int) $tenant['id'])) {
                    continue;
                }

                $domain = trim((string) $tenant['app_host']);
                if ($this->installTenant($domain, $output) !== Command::SUCCESS) {
                    $exitCode = Command::FAILURE;
                }
            }

            return $exitCode;
        } finally {
            $this->databaseSwitchService->switchBackToOriginalDatabase();
            $lock->release();
        }
    }

    private function ensureInstallColumns(): void
    {
        if (!$this->columnExists('installation_status')) {
            $this->connection->executeStatement(
                'ALTER TABLE `tenancies`
                 ADD `installation_status` ENUM("pending", "installing", "installed", "failed") NOT NULL DEFAULT "pending"'
            );
            $this->connection->executeStatement(
                'UPDATE `tenancies`
                 SET `installation_status` = "installed"
                 WHERE `installation_status` = "pending"'
            );
        }
    }

    private function columnExists(string $columnName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['tenancies', $columnName]
        ) > 0;
    }

    /**
     * @return array<int, array{id: int|string, app_host: string}>
     */
    private function findPendingTenants(int $limit, string $domain = ''): array
    {
        $where = [
            '`installation_status` = "pending"',
        ];
        $params = [];
        $types = [];

        if ($domain !== '') {
            $where[] = '`app_host` = :domain';
            $params['domain'] = $domain;
            $types['domain'] = ParameterType::STRING;
        }

        return $this->connection->executeQuery(
            sprintf(
                'SELECT `id`, `app_host`
                 FROM `tenancies`
                 WHERE %s
                 ORDER BY `id`
                 LIMIT %d',
                implode(' AND ', $where),
                $limit
            ),
            $params,
            $types
        )->fetchAllAssociative();
    }

    private function claimTenant(int $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE `tenancies`
             SET `installation_status` = "installing"
             WHERE `id` = :id
               AND `installation_status` = "pending"',
            [
                'id' => $id,
            ],
            [
                'id' => ParameterType::INTEGER,
            ]
        ) === 1;
    }

    private function installTenant(string $domain, OutputInterface $output): int
    {
        if ($domain === '') {
            return Command::FAILURE;
        }

        $output->writeln(sprintf('[tenant:install:pending] installing | domain=%s', $domain));

        $command = $this->getApplication()?->find('tenant:migrations:migrate');
        if (!$command instanceof Command) {
            $this->markFailed($domain);

            return Command::FAILURE;
        }

        $input = new ArrayInput([
            'command' => 'tenant:migrations:migrate',
            '--domain' => $domain,
            '--allow-no-migration' => true,
            '--query-time' => true,
        ]);
        $input->setInteractive(false);

        try {
            $exitCode = $command->run($input, $output);
        } finally {
            $this->databaseSwitchService->switchBackToOriginalDatabase();
        }

        if ($exitCode === Command::SUCCESS) {
            $this->markInstalled($domain);
            $output->writeln(sprintf('[tenant:install:pending] installed | domain=%s', $domain));

            return Command::SUCCESS;
        }

        $this->markFailed($domain);
        $output->writeln(sprintf('[tenant:install:pending] failed | domain=%s | status=%d', $domain, $exitCode));

        return Command::FAILURE;
    }

    private function markInstalled(string $domain): void
    {
        $this->connection->executeStatement(
            'UPDATE `tenancies`
             SET `installation_status` = "installed"
             WHERE `app_host` = :domain',
            ['domain' => $domain],
            ['domain' => ParameterType::STRING]
        );
    }

    private function markFailed(string $domain): void
    {
        $this->connection->executeStatement(
            'UPDATE `tenancies`
             SET `installation_status` = "failed"
             WHERE `app_host` = :domain',
            ['domain' => $domain],
            ['domain' => ParameterType::STRING]
        );
    }

    private function normalizePositiveInt(mixed $value, int $default, int $max): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => $max,
            ],
        ]);

        return is_int($normalized) ? $normalized : $default;
    }
}
