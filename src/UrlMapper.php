<?php

declare(strict_types=1);

namespace ThreeSixtyEu\Nexus\Composer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PreFileDownloadEvent;
use ThreeSixtyEu\Nexus\Composer\Config\MirrorMapping;

use function explode;
use function file_get_contents;
use function filter_var;
use function function_exists;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function parse_url;
use function preg_match;
use function preg_quote;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;
use const FILTER_VALIDATE_BOOLEAN;
use const PHP_URL_HOST;

class UrlMapper
{
    private string $rootUrl;
    /**
     * @var MirrorMapping[]
     */
    private array $mappings;
    private ?IOInterface $io;

    private static bool $proxyOffline = false;
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

    public function applyMappings(string $url): string
    {
        if (self::$proxyOffline || empty($this->rootUrl)) {
            return $url;
        }

        $patchedUrl = $url;

        foreach ($this->mappings as $mapping) {
            $prefix = $mapping->getNormalizedUrl();
            $regex = sprintf('#^https?:%s(?<path>.+)$#i', preg_quote($prefix, '#'));
            $matches = [];
            if (preg_match($regex, $patchedUrl, $matches) === 1) {
                $patchedUrl = sprintf(
                    '%s/%s/%s',
                    rtrim($this->rootUrl, '/'),
                    trim($mapping->getPath(), '/'),
                    ltrim($matches['path'], '/')
                );
                break;
            }
        }

        return $patchedUrl;
    }

    public function rewriteDownloadUrl(PreFileDownloadEvent $event): void
    {
        if (self::$proxyOffline || empty($this->rootUrl)) {
            return;
        }

        $url = $event->getProcessedUrl();
        if (!$url) {
            return;
        }

        // 1. Only process remote HTTP/HTTPS URLs
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return;
        }

        // 2. Skip patch and diff files (e.g. cweagans/composer-patches)
        $lowerUrl = strtolower($url);
        if (str_contains($lowerUrl, '.patch') || str_contains($lowerUrl, '.diff')) {
            return;
        }

        // 3. Skip internal repository packages (e.g. git2.funlife.cz)
        if (str_contains($url, 'git2.funlife.cz')) {
            return;
        }

        // Extract Package object from context
        $package = null;
        $context = $event->getContext();
        if ($context instanceof PackageInterface) {
            $package = $context;
        } elseif (is_array($context) && isset($context['package']) && $context['package'] instanceof PackageInterface) {
            $package = $context['package'];
        }

        if ($package) {
            $packageName = $package->getName();
            $version = $package->getPrettyVersion();

            // Do not proxy internal funlife packages
            if (str_starts_with($packageName, 'funlife/')) {
                return;
            }

            // Verify this download is actually the package dist archive, not a patch or auxiliary file
            $distUrl = $package->getDistUrl();
            if ($distUrl) {
                $distHost = parse_url($distUrl, PHP_URL_HOST);
                $urlHost = parse_url($url, PHP_URL_HOST);
                if ($distHost && $urlHost && $distHost !== $urlHost) {
                    return;
                }
            }

            if (str_contains($packageName, '/')) {
                [$vendor, $name] = explode('/', $packageName, 2);
                $defaultNexusUrl = "{$this->rootUrl}/{$vendor}/{$name}/{$version}/{$vendor}-{$name}-{$version}.zip";

                // Extract git commit reference from package or URL if available
                $reference = $package->getDistReference() ?: $package->getSourceReference();
                if (!$reference && preg_match('#/zipball/([a-f0-9]+)#i', $url, $m)) {
                    $reference = $m[1];
                }

                // Query Nexus metadata to find exact dist URL assigned by Nexus (handles version aliases & commit groups)
                $nexusUrl = $this->resolveNexusDistUrl($this->rootUrl, $vendor, $name, $version, $reference) ?: $defaultNexusUrl;

                // Step 1: Check if package is already cached in Nexus proxy
                $exists = $this->urlExistsInNexus($nexusUrl);

                if ($exists === null) {
                    self::$proxyOffline = true;
                    if ($this->io) {
                        $this->io->writeError('<warning>[Nexus] Proxy server appears offline or timed out. Disabling proxy for remaining packages.</warning>');
                    }
                    return;
                }

                if ($exists === true) {
                    if ($this->io) {
                        $this->io->writeError('<info>[Nexus] Intercepted URL:</info> ' . $url);
                        $this->io->writeError('<info>[Nexus] Rewriting URL to Nexus Proxy:</info> ' . $nexusUrl);
                    }
                    $event->setProcessedUrl($nexusUrl);
                    return;
                }

                // Step 2: If missing in Nexus, request metadata to trigger caching, then fall back immediately
                if ($this->io) {
                    $this->io->writeError('<info>[Nexus] Package missing in Nexus proxy. Triggering cache warmup for ' . $packageName . ' (' . $version . ')</info>');
                }
                $this->triggerNexusMetadataIndexing($this->rootUrl, $vendor, $name);

                // We no longer sleep or wait for Nexus to finish downloading. Fall back to original URL immediately.
                if ($this->io) {
                    $this->io->writeError('<comment>[Nexus] Falling back to original URL to avoid blocking:</comment> ' . $url);
                }
                return;
            }
        }
    }

    private function resolveNexusDistUrl(string $proxyBaseClean, string $vendor, string $name, string $version, ?string $reference = null): ?string
    {
        $metadataUrl = "{$proxyBaseClean}/p2/{$vendor}/{$name}.json";

        if (isset(self::$metadataCache[$metadataUrl])) {
            $json = self::$metadataCache[$metadataUrl];
        } else {
            $json = $this->fetchUrlQuietly($metadataUrl, 2);
            self::$metadataCache[$metadataUrl] = $json;
        }

        if (!$json) {
            return null;
        }

        $data = @json_decode($json, true);
        if (!is_array($data) || !isset($data['packages']["{$vendor}/{$name}"])) {
            return null;
        }

        $pkgs = $data['packages']["{$vendor}/{$name}"];
        if (!is_array($pkgs)) {
            return null;
        }

        $currentDistUrl = null;
        foreach ($pkgs as $p) {
            if (isset($p['dist']['url'])) {
                $currentDistUrl = $p['dist']['url'];
            }

            $ver = $p['version'] ?? null;
            $ref = $p['reference'] ?? ($p['dist']['reference'] ?? null);

            $versionMatches = ($ver === $version || $ver === "v{$version}" || ltrim((string)$ver, 'v') === ltrim($version, 'v'));
            $referenceMatches = ($reference && $ref === $reference);

            if ($versionMatches || $referenceMatches) {
                if ($currentDistUrl) {
                    if (!str_starts_with($currentDistUrl, 'http://') && !str_starts_with($currentDistUrl, 'https://')) {
                        return "{$proxyBaseClean}/" . ltrim($currentDistUrl, '/');
                    }
                    return $currentDistUrl;
                }
            }
        }

        return null;
    }

    /**
     * @return bool|null True if exists, false if 404/other, null if connection error or timeout
     */
    private function urlExistsInNexus(string $url): ?bool
    {
        $insecure = filter_var(getenv('COMPOSER_DIST_PROXY_INSECURE'), FILTER_VALIDATE_BOOLEAN)
            || filter_var(getenv('NEXUS_INSECURE'), FILTER_VALIDATE_BOOLEAN);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            if ($insecure) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            curl_exec($ch);

            if (curl_errno($ch)) {
                curl_close($ch);
                return null;
            }

            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        }

        $contextOptions = [
            'http' => [
                'method' => 'HEAD',
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ];
        if ($insecure) {
            $contextOptions['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }

        $context = stream_context_create($contextOptions);
        $res = @file_get_contents($url, false, $context);

        if ($res === false && empty($http_response_header)) {
            return null;
        }

        if (!empty($http_response_header)) {
            preg_match('#^HTTP/.*\s+(\d{3})\s+#i', $http_response_header[0], $matches);
            $code = isset($matches[1]) ? (int)$matches[1] : 0;
            return $code >= 200 && $code < 300;
        }
        return false;
    }

    private function triggerNexusMetadataIndexing(string $proxyBase, string $vendor, string $name): void
    {
        $urls = [
            "{$proxyBase}/p2/{$vendor}/{$name}.json",
            "{$proxyBase}/p/{$vendor}/{$name}.json",
        ];
        foreach ($urls as $url) {
            $this->fetchUrlQuietly($url, 1);
        }
    }

    private function fetchUrlQuietly(string $url, int $timeout = 2): ?string
    {
        $insecure = filter_var(getenv('COMPOSER_DIST_PROXY_INSECURE'), FILTER_VALIDATE_BOOLEAN)
            || filter_var(getenv('NEXUS_INSECURE'), FILTER_VALIDATE_BOOLEAN);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            if ($insecure) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            $res = curl_exec($ch);
            curl_close($ch);

            return is_string($res) ? $res : null;
        }

        $contextOptions = [
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ];
        if ($insecure) {
            $contextOptions['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }

        $context = stream_context_create($contextOptions);
        $res = @file_get_contents($url, false, $context);

        return is_string($res) ? $res : null;
    }
}
