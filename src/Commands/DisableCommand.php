<?php

declare(strict_types=1);

namespace GMTA\Velocita\Composer\Commands;

use Composer\Command\BaseCommand;
use GMTA\Velocita\Composer\VelocitaPlugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisableCommand extends BaseCommand
{
    protected VelocitaPlugin $plugin;

    public function __construct(VelocitaPlugin $plugin)
    {
        parent::__construct();

        $this->plugin = $plugin;
    }

    protected function configure(): void
    {
        $this
            ->setName('velocita:disable')
            ->setAliases(['nexus:disable'])
            ->setDescription('Disables the Velocita / Nexus plugin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Update configuration
        $config = $this->plugin->getConfiguration();
        $config->setEnabled(false);

        // Write new configuration
        $this->plugin->writeConfiguration($config);

        $output->writeln('Velocita / Nexus is now <warning>disabled</warning>.');
        return 0;
    }
}
