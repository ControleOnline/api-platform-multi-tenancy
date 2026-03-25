<?php

namespace ControleOnline\Command;

use ControleOnline\Service\DatabaseSwitchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Throwable;

#[AsCommand(
    name: 'tenant:migrations:migrate',
    description: 'Proxy para migrar um novo banco de dados de tenant.',
)]
final class TenantMigrateCommand extends DefaultCommand
{

    public function __construct(
        LockFactory $lockFactory,
        DatabaseSwitchService $databaseSwitchService
    ) {
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;
        parent::__construct('tenant:migrations:migrate');
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['t:m:m'])
            ->setDescription('Proxy para executar doctrine:migrations:migrate para um banco de dados específico.')
            ->addArgument('version', InputArgument::OPTIONAL, 'O número da versão (AAAAMMDDHHMMSS) ou alias (first, prev, next, latest) para migrar.', 'latest')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Executar a migração como uma simulação.')
            ->addOption('query-time', null, InputOption::VALUE_NONE, 'Medir o tempo de todas as consultas individualmente.')
            ->addOption('allow-no-migration', null, InputOption::VALUE_NONE, 'Não lançar uma exceção quando nenhuma alteração for detectada.');
    }

    protected function runCommand(): int
    {
        // ALEMAC // 2026/03/03 15:00:00
        $domain = $this->input->getOption('domain') ?: 'all-domains';
        $version = $this->input->getArgument('version') ?: 'latest';

        $this->addLog(sprintf('[tenant:migrations:migrate] Iniciando migração | domain=%s | version=%s', $domain, $version));

        $newInput = new ArrayInput([
            'version' => $this->input->getArgument('version'),
            '--dry-run' => $this->input->getOption('dry-run'),
            '--query-time' => $this->input->getOption('query-time'),
            '--allow-no-migration' => $this->input->getOption('allow-no-migration'),
        ]);
        $newInput->setInteractive(false);
        $command = $this->getApplication()->find('doctrine:migrations:migrate');

        try {
            $statusCode = $command->run($newInput, $this->output);

            if ($statusCode === Command::SUCCESS) {
                // ALEMAC // 2026/03/03 15:00:00
                $this->addLog(sprintf('[tenant:migrations:migrate] SUCESSO | domain=%s | version=%s', $domain, $version));
            } else {
                // ALEMAC // 2026/03/03 15:00:00
                $this->addLog(sprintf('[tenant:migrations:migrate] ERRO | domain=%s | version=%s | motivo=status_code_%d', $domain, $version, $statusCode));
            }

            return $statusCode;
        } catch (Throwable $exception) {
            // ALEMAC // 2026/03/03 15:00:00
            $this->addLog(sprintf(
                '[tenant:migrations:migrate] ERRO | domain=%s | version=%s | motivo=%s',
                $domain,
                $version,
                $exception->getMessage()
            ));

            return Command::FAILURE;
        }
    }
}
