<?php

declare(strict_types=1);

namespace GMTA\Velocita\Composer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PreFileDownloadEvent;
use GMTA\Velocita\Composer\Config\MirrorMapping;

use function array_key_exists;
use function count;
use function explode;
use function file_get_contents;
use function function_exists;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function parse_url;
use function preg_match;
use function preg_quote;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function stream_context_create;
use function strncmp;
use function strpos;
use function strtolower;
use function trim;

use const PHP_URL_HOST;

class UrlMapper
{
    private const GITHUB_REGEX = '#^https://api.github.com/repos/(?<package>.+)/zipball/(?<hash>[0-9a-f]+)$#i';

    private string $rootUrl;
    /**
     * @var MirrorMapping[]
     */
    private array $mappings;
    private ?IOInterface $io;

    /**
     * @var array<string, string|null>
     */
    private static array $metadataCache = [];

    /**
     * @param MirrorMapping[] $mappings
     */
    public function __construct(string $rootUrl, array $mappings = [], ?IOInterface $io = null)
    {
        $this->rootUrl = rtrim($rootUrl, '/');
        $this->mappings = $mappings;
        $this->io = $io;
    }

    /**
     * @param non-empty-string $url
     * @return non-empty-string
     */
    public function applyMappings(string $url): string
    {
        $patchedUrl = $this->applyGitHubShortcut($url);

        foreach ($this->mappings as $mapping) {
            $prefix = $mapping->getNormalizedUrl();
            $regex = sprintf('#^https?:%s(?<path>.+)$#i', preg_quote($prefix));
            $matches = [];
            if (preg_match($regex, $patchedUrl, $matches) === 1) {
                return sprintf(
                    '%s/%s/%s',
                    rtrim($this->rootUrl, '/'),
                    trim($mapping->getPath(), '/'),
                    ltrim($matches['path'], '/')
                );
            }
        }

        return $patchedUrl;
    }

    /**
     * @param non-empty-string $url
     * @return non-empty-string
     */
    protected function applyGitHubShortcut(string $url): string
    {
        $matches = [];
        if (preg_match(self::GITHUB_REGEX, $url, $matches) === 1) {
            $package = $matches['package'];
            $hash = $matches['hash'];
            return sprintf('https://codeload.github.com/%s/legacy.zip/%s', $package, $hash);
        }
        return $url;
    }

    public function rewriteDownloadUrl(PreFileDownloadEvent $event): void
    {
        if ($this->rootUrl === '') {
            return;
        }

        $url = $event->getProcessedUrl();
        if (!$url) {
            return;
        }

        // 1. Only process remote HTTP/HTTPS URLs
        if (!self::startsWith($url, 'http://') && !self::startsWith($url, 'https://')) {
            return;
        }

        // 2. Skip patch and diff files (e.g. cweagans/composer-patches)
        $lowerUrl = strtolower($url);
        if (self::contains($lowerUrl, '.patch') || self::contains($lowerUrl, '.diff')) {
            return;
        }

        // 3. If Velocita mirror mappings exist, use standard Velocita mapping rules
        if (count($this->mappings) > 0) {
            $mappedUrl = $this->applyMappings($url);
            if ($mappedUrl !== '' && $mappedUrl !== $url) {
                if ($this->io) {
                    $this->io->write(
                        sprintf('[Velocita] Mapped URL %s to %s', $url, $mappedUrl),
                        true,
                        IOInterface::DEBUG
                    );
                }
                $event->setProcessedUrl($mappedUrl);
            }
            return;
        }

        // 4. Nexus Proxy mode: Extract package and rewrite directly to Nexus
        $package = null;
        $context = $event->getContext();
        if ($context instanceof PackageInterface) {
            $package = $context;
        } elseif (
            is_array($context)
            && array_key_exists('package', $context)
            && $context['package'] instanceof PackageInterface
        ) {
            $package = $context['package'];
        }

        if ($package) {
            $packageName = $package->getName();
            $version = $package->getPrettyVersion();

            // Verify this download is actually the package dist archive, not a patch or auxiliary file
            $distUrl = $package->getDistUrl();
            if ($distUrl) {
                $patchedDistUrl = $this->applyGitHubShortcut($distUrl);
                $patchedUrl = $this->applyGitHubShortcut($url);
                $distHost = parse_url($patchedDistUrl, PHP_URL_HOST);
                $urlHost = parse_url($patchedUrl, PHP_URL_HOST);
                if ($distHost && $urlHost && $distHost !== $urlHost) {
                    return;
                }
            }

            if (self::contains($packageName, '/')) {
                [$vendor, $name] = explode('/', $packageName, 2);
                $vEnc = rawurlencode($vendor);
                $nEnc = rawurlencode($name);
                $verEnc = rawurlencode($version);

                if (self::startsWith($version, 'dev-')) {
                    $defaultNexusUrl = "{$this->rootUrl}/{$vEnc}/{$nEnc}~dev/{$verEnc}/{$vEnc}-{$nEnc}~dev-{$verEnc}";
                } else {
                    $defaultNexusUrl = "{$this->rootUrl}/{$vEnc}/{$nEnc}/{$verEnc}/{$vEnc}-{$nEnc}-{$verEnc}";
                }

                // Extract git commit reference from package or URL if available
                $reference = $package->getDistReference() ?: $package->getSourceReference();
                if (!$reference && preg_match('#/zipball/([a-f0-9]+)#i', $url, $m)) {
                    $reference = $m[1];
                }

                // Query Nexus metadata to resolve exact dist URL assigned by Nexus
                // (handles version aliases & versions sharing the same commit)
                $nexusUrl = $this->resolveNexusDistUrl(
                    $this->rootUrl,
                    $vendor,
                    $name,
                    $version,
                    $reference
                ) ?: $defaultNexusUrl;

                if ($this->io) {
                    $this->io->writeError(
                        '<info>[Velocita-Nexus] Intercepted URL:</info> ' . $url,
                        true,
                        IOInterface::DEBUG
                    );
                    $this->io->writeError(
                        '<info>[Velocita-Nexus] Rewriting URL to Nexus Proxy:</info> ' . $nexusUrl,
                        true,
                        IOInterface::DEBUG
                    );
                }

                $event->setProcessedUrl($nexusUrl);
            }
        }
    }

    private function resolveNexusDistUrl(
        string $proxyBaseClean,
        string $vendor,
        string $name,
        string $version,
        ?string $reference = null
    ): ?string {
        $vEnc = rawurlencode($vendor);
        $nEnc = rawurlencode($name);
        $metadataUrl = "{$proxyBaseClean}/p2/{$vEnc}/{$nEnc}.json";

        if (array_key_exists($metadataUrl, self::$metadataCache)) {
            $json = self::$metadataCache[$metadataUrl];
        } else {
            $json = $this->fetchUrlQuietly($metadataUrl, 2);
            self::$metadataCache[$metadataUrl] = $json;
        }

        if (!$json) {
            return null;
        }

        $data = @json_decode($json, true);
        if (
            !is_array($data)
            || !array_key_exists('packages', $data)
            || !is_array($data['packages'])
            || !array_key_exists("{$vendor}/{$name}", $data['packages'])
        ) {
            return null;
        }

        $pkgs = $data['packages']["{$vendor}/{$name}"];
        if (!is_array($pkgs)) {
            return null;
        }

        $currentDistUrl = null;
        foreach ($pkgs as $p) {
            if (
                is_array($p)
                && array_key_exists('dist', $p)
                && is_array($p['dist'])
                && array_key_exists('url', $p['dist'])
                && is_string($p['dist']['url'])
            ) {
                $currentDistUrl = $p['dist']['url'];
            }

            $ver = (is_array($p) && array_key_exists('version', $p) && is_string($p['version'])) ? $p['version'] : null;
            $ref = null;
            if (is_array($p)) {
                if (array_key_exists('reference', $p) && is_string($p['reference'])) {
                    $ref = $p['reference'];
                } elseif (
                    array_key_exists('dist', $p)
                    && is_array($p['dist'])
                    && array_key_exists('reference', $p['dist'])
                    && is_string($p['dist']['reference'])
                ) {
                    $ref = $p['dist']['reference'];
                }
            }

            $versionMatches = ($ver === $version
                || $ver === "v{$version}"
                || ltrim((string)$ver, 'v') === ltrim($version, 'v'));
            $referenceMatches = ($reference !== null && $reference !== '' && $ref === $reference);

            if ($versionMatches || $referenceMatches) {
                if ($currentDistUrl) {
                    if (
                        !self::startsWith($currentDistUrl, 'http://')
                        && !self::startsWith($currentDistUrl, 'https://')
                    ) {
                        return "{$proxyBaseClean}/" . ltrim($currentDistUrl, '/');
                    }
                    return $currentDistUrl;
                }
            }
        }

        return null;
    }

    /**
     * Performs a quiet, out-of-band synchronous HTTP fetch using raw cURL or stream context.
     *
     * Note: This intentionally does not use Composer's HttpDownloader because this method
     * is invoked synchronously inside the PreFileDownloadEvent listener. Calling HttpDownloader
     * here would trigger recursive PreFileDownloadEvents, risk event-loop re-entrancy during
     * batch downloads, and throw fatal TransportExceptions on 404s instead of quietly falling back.
     */
    private function fetchUrlQuietly(string $url, int $timeout = 2): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            $res = curl_exec($ch);
            if (\PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            return is_string($res) ? $res : null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $res = @file_get_contents($url, false, $context);

        return is_string($res) ? $res : null;
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, \strlen($needle)) === 0;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
