<?php

declare(strict_types=1);

namespace ThreeSixtyEu\Nexus\Composer\Compatibility;

use Composer\Plugin\PluginInterface;

interface CompatibilityFix
{
    public function applyPluginFix(PluginInterface $plugin): void;
}
