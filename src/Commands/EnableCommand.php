<?php

declare(strict_types=1);

namespace ThreeSixtyEu\Nexus\Composer\Commands;

use Composer\Command\BaseCommand;
use ThreeSixtyEu\Nexus\Composer\NexusPlugin;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnableCommand extends BaseCommand
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
            ->setName('nexus:enable')
            ->setDescription('Enables the Nexus Composer plugin')
            ->addArgument('url', InputArgument::OPTIONAL, 'Sets the URL to your Nexus proxy instance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputAdapter = new InputInterfaceAdapter($input);
        $url = $inputAdapter->getStringArgument('url');

        // Update configuration
        $config = $this->plugin->getConfiguration();
        $config->setEnabled(true);
        if ($url !== null) {
            $config->setURL($url);
        }

        // Write new configuration
        $this->plugin->writeConfiguration($config);

        $output->writeln('Nexus is now <info>enabled</info>.');
        return 0;
    }
}
