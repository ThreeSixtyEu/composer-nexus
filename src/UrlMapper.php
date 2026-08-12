<?php

declare(strict_types=1);

namespace GMTA\Velocita\Composer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PreFileDownloadEvent;
use GMTA\Velocita\Composer\Config\MirrorMapping;

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
use function rawurlencode;
use function rtrim;
use function sprintf;
use function strncmp;
use function strpos;
use function strtolower;
use function trim;
use const FILTER_VALIDATE_BOOLEAN;
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

    private static bool $proxyOffline = false;
    /**
     * @var array<string, string|null>
     */
    private static array $metadataCache = [];
    private static bool $insecureWarned = false;

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
        if (self::$proxyOffline || empty($this->rootUrl)) {
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
        if (!empty($this->mappings)) {
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

            // Verify this download is actually the package dist archive, not a patch or auxiliary file
            $distUrl = $package->getDistUrl();
            if ($distUrl) {
                $distHost = parse_url($distUrl, PHP_URL_HOST);
                $urlHost = parse_url($url, PHP_URL_HOST);
                if ($distHost && $urlHost && $distHost !== $urlHost) {
                    return;
                }
            }

            if (self::contains($packageName, '/')) {
                [$vendor, $name] = explode('/', $packageName, 2);
                $vEnc = rawurlencode($vendor);
                $nEnc = rawurlencode($name);
                $verEnc = rawurlencode($version);
                $defaultNexusUrl = "{$this->rootUrl}/{$vEnc}/{$nEnc}/{$verEnc}/{$vEnc}-{$nEnc}-{$verEnc}.zip";

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
                        $this->io->writeError('<warning>[Velocita-Nexus] Proxy server appears offline or timed out. Disabling proxy for remaining packages.</warning>');
                    }
                    return;
                }

                if ($exists === true && $nexusUrl !== '') {
                    if ($this->io) {
                        $this->io->writeError('<info>[Velocita-Nexus] Intercepted URL:</info> ' . $url, true, IOInterface::DEBUG);
                        $this->io->writeError('<info>[Velocita-Nexus] Rewriting URL to Nexus Proxy:</info> ' . $nexusUrl, true, IOInterface::DEBUG);
                    }
                    $event->setProcessedUrl($nexusUrl);
                    return;
                }

                // Step 2: If missing in Nexus, request metadata to trigger caching, then fall back immediately
                if ($this->io) {
                    $this->io->writeError('<info>[Velocita-Nexus] Package missing in Nexus proxy. Triggering cache warmup for ' . $packageName . ' (' . $version . ')</info>', true, IOInterface::DEBUG);
                }
                $this->triggerNexusMetadataIndexing($this->rootUrl, $vendor, $name);

                // We no longer sleep or wait for Nexus to finish downloading. Fall back to original URL immediately.
                if ($this->io) {
                    $this->io->writeError('<comment>[Velocita-Nexus] Falling back to original URL to avoid blocking:</comment> ' . $url, true, IOInterface::DEBUG);
                }
                return;
            }
        }
    }

    private function resolveNexusDistUrl(string $proxyBaseClean, string $vendor, string $name, string $version, ?string $reference = null): ?string
    {
        $vEnc = rawurlencode($vendor);
        $nEnc = rawurlencode($name);
        $metadataUrl = "{$proxyBaseClean}/p2/{$vEnc}/{$nEnc}.json";

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
                    if (!self::startsWith($currentDistUrl, 'http://') && !self::startsWith($currentDistUrl, 'https://')) {
                        return "{$proxyBaseClean}/" . ltrim($currentDistUrl, '/');
                    }
                    return $currentDistUrl;
                }
            }
        }

        return null;
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, \strlen($needle)) === 0;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }

    /**
     * @return bool|null True if exists, false if 404/other, null if connection error or timeout
     */
    private function urlExistsInNexus(string $url): ?bool
    {
        $insecure = filter_var(getenv('COMPOSER_DIST_PROXY_INSECURE'), FILTER_VALIDATE_BOOLEAN)
            || filter_var(getenv('VELOCITA_INSECURE'), FILTER_VALIDATE_BOOLEAN);

        if ($insecure && !self::$insecureWarned && $this->io) {
            self::$insecureWarned = true;
            $this->io->writeError('<warning>[Velocita-Nexus] Insecure TLS mode enabled (COMPOSER_DIST_PROXY_INSECURE / VELOCITA_INSECURE active).</warning>');
        }

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
        $vEnc = rawurlencode($vendor);
        $nEnc = rawurlencode($name);
        $urls = [
            "{$proxyBase}/p2/{$vEnc}/{$nEnc}.json",
            "{$proxyBase}/p/{$vEnc}/{$nEnc}.json",
        ];
        foreach ($urls as $url) {
            $this->fetchUrlQuietly($url, 1);
        }
    }

    private function fetchUrlQuietly(string $url, int $timeout = 2): ?string
    {
        $insecure = filter_var(getenv('COMPOSER_DIST_PROXY_INSECURE'), FILTER_VALIDATE_BOOLEAN)
            || filter_var(getenv('VELOCITA_INSECURE'), FILTER_VALIDATE_BOOLEAN);

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
