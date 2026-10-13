<?php

declare(strict_types=1);

namespace GMTA\Velocita\Composer\Config;

use RuntimeException;

use function filter_var;
use function in_array;
use function rtrim;
use function sprintf;

use const FILTER_VALIDATE_URL;

class PluginConfig
{
    public const TYPE_VELOCITA = 'velocita';
    public const TYPE_NEXUS = 'nexus';
    public const TYPES = [self::TYPE_VELOCITA, self::TYPE_NEXUS];

    protected bool $enabled = false;
    protected ?string $url = null;
    protected string $type = self::TYPE_VELOCITA;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getURL(): ?string
    {
        return $this->url;
    }

    public function setURL(?string $url): void
    {
        if ($url === null) {
            $this->url = null;
        } else {
            $this->url = rtrim($url, '/');
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function isNexus(): bool
    {
        return $this->type === self::TYPE_NEXUS;
    }

    public function validate(): void
    {
        if (!in_array($this->type, self::TYPES, true)) {
            throw new RuntimeException(sprintf('Invalid proxy type "%s" was set for this plugin', $this->type));
        }

        // If set, the URL must be valid
        if (($this->url !== null) && !filter_var($this->url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid URL was set for this plugin');
        }

        // If enabled, a URL must also be set
        if ($this->enabled && ($this->url === null)) {
            throw new RuntimeException('A URL must be set for this plugin to be enabled');
        }
    }
}
