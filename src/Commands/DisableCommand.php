<?php

declare(strict_types=1);

namespace ThreeSixtyEu\Nexus\Composer\Commands;

use Composer\Command\BaseCommand;
use ThreeSixtyEu\Nexus\Composer\NexusPlugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisableCommand extends BaseCommand
{
    protected NexusPlugin $plugin;

    public function __construct(NexusPlugin $plugin)
    {
        parent::__construct();

        $this->plugin = $plugin;
    }

    protected function configure(): void
    {
        $this
            ->setName('nexus:disable')
            ->setDescription('Disables the Nexus Composer plugin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Update configuration
        $config = $this->plugin->getConfiguration();
        $config->setEnabled(false);

        // Write new configuration
        $this->plugin->writeConfiguration($config);

        $output->writeln('Nexus is now <warning>disabled</warning>.');
        return 0;
    }
}
