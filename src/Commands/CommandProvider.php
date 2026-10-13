<?php

declare(strict_types=1);

namespace GMTA\Velocita\Composer\Commands;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use GMTA\Velocita\Composer\Config\PluginConfig;
use GMTA\Velocita\Composer\VelocitaPlugin;

class CommandProvider implements CommandProviderCapability
{
    protected VelocitaPlugin $plugin;

    /**
     * @param array{plugin: VelocitaPlugin} $arguments
     */
    public function __construct(array $arguments)
    {
        $this->plugin = $arguments['plugin'];
    }

    public function getCommands(): array
    {
        return [
            new EnableCommand($this->plugin, PluginConfig::TYPE_VELOCITA),
            new EnableCommand($this->plugin, PluginConfig::TYPE_NEXUS),
            new DisableCommand($this->plugin),
        ];
    }
}
