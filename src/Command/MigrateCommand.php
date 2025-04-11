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

#[AsCommand(
    name: 'tenant:migrations:migrate',
    description: 'Proxy para migrar um novo banco de dados de tenant.',
)]
final class MigrateCommand extends Command
{
    private DatabaseSwitchService $databaseSwitchService;

    public function __construct(DatabaseSwitchService $databaseSwitchService)
    {
        $this->databaseSwitchService = $databaseSwitchService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('tenant:migrations:migrate')
            ->setAliases(['t:m:m'])
            ->setDescription('Proxy para executar doctrine:migrations:migrate para um banco de dados específico.')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Identificador do domínio do banco de dados')
            ->addArgument('version', InputArgument::OPTIONAL, 'O número da versão (AAAAMMDDHHMMSS) ou alias (first, prev, next, latest) para migrar.', 'latest')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Executar a migração como uma simulação.')
            ->addOption('query-time', null, InputOption::VALUE_NONE, 'Medir o tempo de todas as consultas individualmente.')
            ->addOption('allow-no-migration', null, InputOption::VALUE_NONE, 'Não lançar uma exceção quando nenhuma alteração for detectada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = $input->getArgument('domain');

        if ($domain) {
            return $this->migrateByDomain($domain, $input, $output);
        }

        $domains = $this->databaseSwitchService->getAllDomains();
        $exitCode = Command::SUCCESS;

        foreach ($domains as $domain) {
            $result = $this->migrateByDomain($domain, $input, $output);
            if ($result !== Command::SUCCESS) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }

    protected function migrateByDomain(string $domain, InputInterface $input, OutputInterface $output): int
    {
        $this->databaseSwitchService->switchDatabaseByDomain($domain);

        $newInput = new ArrayInput([
            'version' => $input->getArgument('version'),
            '--dry-run' => $input->getOption('dry-run'),
            '--query-time' => $input->getOption('query-time'),
            '--allow-no-migration' => $input->getOption('allow-no-migration'),
        ]);
        $newInput->setInteractive(false);

        $output->writeln(sprintf('Executando migrações para o domínio: %s', $domain));

        $command = $this->getApplication()->find('doctrine:migrations:migrate');
        return $command->run($newInput, $output);
    }
}
