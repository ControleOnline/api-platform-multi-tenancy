<?php

namespace ControleOnline\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand as Migrate;
use Symfony\Component\Console\Application;

#[AsCommand(
    name: 'tenant:migrations:migrate',
    description: 'Proxy to migrate a new tenant database.',
)]
final class MigrateCommand extends Command
{

    private $em;
    private $application;

    public function __construct(EntityManagerInterface $entityManager, Application $application)
    {
        $this->em = $entityManager;
        $this->application = $application;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('tenant:migrations:migrate')
            ->setAliases(['t:m:m'])
            ->setDescription('Proxy to launch doctrine:migrations:migrate for specific database .')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Database Domain Identifier')
            ->addArgument('version', InputArgument::OPTIONAL, 'The version number (YYYYMMDDHHMMSS) or alias (first, prev, next, latest) to migrate to.', 'latest')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Execute the migration as a dry run.')
            ->addOption('query-time', null, InputOption::VALUE_NONE, 'Time all the queries individually.')
            ->addOption('allow-no-migration', null, InputOption::VALUE_NONE, 'Do not throw an exception when no changes are detected.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $newInput = new ArrayInput([
            'version' => $input->getArgument('version'),
            '--dry-run' => $input->getOption('dry-run'),
            '--query-time' => $input->getOption('query-time'),
            '--allow-no-migration' => $input->getOption('allow-no-migration'),
        ]);
        $newInput->setInteractive($input->isInteractive());
        // Encontra e executa o comando de migração do Doctrine
        $command = $this->application->find('doctrine:migrations:migrate');
        $command->run($newInput, $output);

        return Command::SUCCESS;
    }
}