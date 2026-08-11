<?php

declare(strict_types=1);

namespace ThreeSixtyEu\Nexus\Composer\Composer;

use Composer\Factory;

class ComposerFactory extends Factory
{
    /**
     * Exposes the protected Factory::getHomeDir().
     */
    public static function getComposerHomeDir(): string
    {
        return parent::getHomeDir();
    }
}
