<?php

declare(strict_types=1);

namespace ThreeSixtyEu\Nexus\Composer\Commands;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use ThreeSixtyEu\Nexus\Composer\NexusPlugin;

class CommandProvider implements CommandProviderCapability
{
    protected NexusPlugin $plugin;

    /**
     * @param array{plugin: NexusPlugin} $arguments
     */
    public function __construct(array $arguments)
    {
        $this->plugin = $arguments['plugin'];
    }

    public function getCommands(): array
    {
        return [
            new EnableCommand($this->plugin),
            new DisableCommand($this->plugin),
        ];
    }
}
