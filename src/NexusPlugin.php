<?php

declare(strict_types=1);

namespace ThreeSixtyEu\Nexus\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Exception;
use ThreeSixtyEu\Nexus\Composer\Commands\CommandProvider;
use ThreeSixtyEu\Nexus\Composer\Compatibility\CompatibilityDetector;
use ThreeSixtyEu\Nexus\Composer\Composer\ComposerFactory;
use ThreeSixtyEu\Nexus\Composer\Config\PluginConfig;
use ThreeSixtyEu\Nexus\Composer\Config\PluginConfigReader;
use ThreeSixtyEu\Nexus\Composer\Config\PluginConfigWriter;
use ThreeSixtyEu\Nexus\Composer\Config\RemoteConfig;
use LogicException;
use RuntimeException;
use UnexpectedValueException;
use function file_exists;
use function filter_var;
use function getenv;
use function is_array;
use function sprintf;

use const FILTER_VALIDATE_BOOLEAN;
use const PHP_INT_MAX;

class NexusPlugin implements PluginInterface, EventSubscriberInterface, Capable
{
    protected const CONFIG_FILE = 'nexus.json';
    protected const REMOTE_CONFIG_URL = '%s/mirrors.json';

    protected static bool $enabled = true;

    protected Composer $composer;
    protected IOInterface $io;
    protected string $configPath;
    protected PluginConfig $configuration;
    protected UrlMapper $urlMapper;
    protected CompatibilityDetector $compatibilityDetector;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        $this->initialize();
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        static::$enabled = false;
    }

    private function initialize(): void
    {
        $this->configPath = sprintf('%s/%s', ComposerFactory::getComposerHomeDir(), static::CONFIG_FILE);
        $this->configuration = (new PluginConfigReader())->readOrNew($this->configPath);

        static::$enabled = $this->configuration->isEnabled();
        if (!static::$enabled) {
            return;
        }

        $url = $this->configuration->getURL();
        if ($url === null) {
            throw new LogicException('Nexus enabled but no URL set');
        }
        try {
            $remoteConfig = $this->getRemoteConfig($url);
            $mirrors = $remoteConfig->getMirrors();
        } catch (Exception $e) {
            $this->io->writeError(sprintf('[Nexus] Remote mirrors.json skipped: %s', $e->getMessage()), true, IOInterface::DEBUG);
            $mirrors = [];
        }

        $this->urlMapper = new UrlMapper($url, $mirrors, $this->io);
        $this->compatibilityDetector = new CompatibilityDetector($this->composer, $this->io, $this->urlMapper);
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [
            ComposerCommandProvider::class => CommandProvider::class,
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        if (!static::$enabled) {
            return [];
        }
        return [
            PluginEvents::PRE_COMMAND_RUN => ['onPreCommandRun', PHP_INT_MAX],
            PackageEvents::POST_PACKAGE_INSTALL => ['onPostPackageInstall', 0],
            PluginEvents::PRE_FILE_DOWNLOAD => ['onPreFileDownload', 0],
        ];
    }

    public function onPreCommandRun(): void
    {
        if (!static::$enabled) {
            return;
        }
        $url = $this->configuration->getURL();
        $this->io->writeError(sprintf('<info>[Nexus]</info> Proxy rewrite is enabled (%s)', $url));
        $this->compatibilityDetector->fixPluginCompatibility();
    }

    public function onPostPackageInstall(PackageEvent $event): void
    {
        if (!static::$enabled) {
            return;
        }
        $this->compatibilityDetector->onPackageInstall($event);
    }

    public function onPreFileDownload(PreFileDownloadEvent $event): void
    {
        if (!static::$enabled) {
            return;
        }
        $this->urlMapper->rewriteDownloadUrl($event);
    }

    public function getConfiguration(): PluginConfig
    {
        return $this->configuration;
    }

    public function writeConfiguration(PluginConfig $config): void
    {
        $writer = new PluginConfigWriter($config);
        $writer->write($this->configPath);
    }

    protected function getRemoteConfig(string $url): RemoteConfig
    {
        $httpDownloader = $this->composer->getLoop()->getHttpDownloader();
        $remoteConfigUrl = sprintf(static::REMOTE_CONFIG_URL, $url);

        $options = [];
        $insecure = filter_var(getenv('COMPOSER_DIST_PROXY_INSECURE'), FILTER_VALIDATE_BOOLEAN)
            || filter_var(getenv('NEXUS_INSECURE'), FILTER_VALIDATE_BOOLEAN);
        if ($insecure) {
            $options['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }

        $response = $httpDownloader->get($remoteConfigUrl, $options);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(
                sprintf('Unexpected status code %d for URL %s', $response->getStatusCode(), $remoteConfigUrl)
            );
        }
        $remoteConfigData = $response->decodeJson();
        if (!is_array($remoteConfigData)) {
            throw new UnexpectedValueException('Remote configuration is formatted incorrectly');
        }
        return RemoteConfig::fromArray($remoteConfigData);
    }
}
