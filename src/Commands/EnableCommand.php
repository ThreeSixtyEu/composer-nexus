<?php

declare(strict_types=1);

namespace GMTA\Velocita\Composer\Commands;

use Composer\Command\BaseCommand;
use GMTA\Velocita\Composer\Config\PluginConfig;
use GMTA\Velocita\Composer\VelocitaPlugin;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;
use function ucfirst;

class EnableCommand extends BaseCommand
{
    protected VelocitaPlugin $plugin;
    protected string $type;

    public function __construct(VelocitaPlugin $plugin, string $type = PluginConfig::TYPE_VELOCITA)
    {
        // Must be set before the parent constructor, which calls configure()
        $this->type = $type;

        parent::__construct();

        $this->plugin = $plugin;
    }

    protected function configure(): void
    {
        $label = $this->getLabel();
        $this
            ->setName(sprintf('%s:enable', $this->type))
            ->setDescription(sprintf('Enables the plugin in %s mode', $label))
            ->addArgument('url', InputArgument::OPTIONAL, sprintf('Sets the URL to your %s instance', $label));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputAdapter = new InputInterfaceAdapter($input);
        $url = $inputAdapter->getStringArgument('url');

        // Update configuration
        $config = $this->plugin->getConfiguration();
        if ($url === null && $config->getType() !== $this->type) {
            // Do not reuse a URL that was configured for a different proxy type
            $output->writeln(sprintf('<error>Please provide the URL to your %s instance.</error>', $this->getLabel()));
            return 1;
        }
        $config->setEnabled(true);
        $config->setType($this->type);
        if ($url !== null) {
            $config->setURL($url);
        }

        // Write new configuration
        $this->plugin->writeConfiguration($config);

        $output->writeln(sprintf('%s is now <info>enabled</info>.', $this->getLabel()));
        return 0;
    }

    private function getLabel(): string
    {
        return ucfirst($this->type);
    }
}
