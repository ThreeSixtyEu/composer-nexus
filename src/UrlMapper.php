<?php

declare(strict_types=1);

namespace GMTA\Velocita\Composer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PreFileDownloadEvent;
use GMTA\Velocita\Composer\Config\MirrorMapping;

use function array_key_exists;
use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
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
use function strlen;
use function strncmp;
use function strpos;
use function strtolower;
use function trim;

use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const PHP_URL_HOST;
use const PHP_VERSION_ID;

class UrlMapper
{
    private const GITHUB_REGEX = '#^https://api.github.com/repos/(?<package>.+)/zipball/(?<hash>[0-9a-f]+)$#i';

    /**
     * @var MirrorMapping[]
     */
    private array $mappings;
    private string $rootUrl;
    private ?IOInterface $io;

    /**
     * @var array<string, string|null>
     */
    private static array $metadataCache = [];

    /**
     * @param MirrorMapping[] $mappings
     */
    public function __construct(string $rootUrl, array $mappings, ?IOInterface $io = null)
    {
        $this->mappings = $mappings;
        $this->rootUrl = rtrim($rootUrl, '/');
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

    public function rewriteNexusDownloadUrl(PreFileDownloadEvent $event): void
    {
        $url = $event->getProcessedUrl();
        if ($this->rootUrl === '' || !self::isHttpUrl($url) || self::isPatchUrl($url)) {
            return;
        }

        $package = self::getEventPackage($event);
        if ($package === null || !self::contains($package->getName(), '/')) {
            return;
        }

        // Only rewrite the package's dist archive, not auxiliary downloads from other hosts
        $distUrl = $package->getDistUrl();
        if ($distUrl !== null && $distUrl !== '') {
            $distHost = parse_url($this->applyGitHubShortcut($distUrl), PHP_URL_HOST);
            $urlHost = parse_url($this->applyGitHubShortcut($url), PHP_URL_HOST);
            if ($distHost && $urlHost && $distHost !== $urlHost) {
                return;
            }
        }

        [$vendor, $name] = explode('/', $package->getName(), 2);
        $version = $package->getPrettyVersion();

        $reference = $package->getDistReference() ?: $package->getSourceReference();
        $matches = [];
        if (!$reference && preg_match('#/zipball/([a-f0-9]+)#i', $url, $matches) === 1) {
            $reference = $matches[1];
        }

        // Prefer the dist URL from Nexus' metadata, which handles version aliases and versions sharing a commit
        $nexusUrl = $this->resolveNexusDistUrl($vendor, $name, $version, $reference)
            ?? $this->getDefaultNexusDistUrl($vendor, $name, $version);

        if ($this->io !== null) {
            $this->io->writeError(
                sprintf('[Velocita-Nexus] Rewriting URL %s to %s', $url, $nexusUrl),
                true,
                IOInterface::DEBUG
            );
        }
        $event->setProcessedUrl($nexusUrl);
    }

    private static function getEventPackage(PreFileDownloadEvent $event): ?PackageInterface
    {
        $context = $event->getContext();
        if ($context instanceof PackageInterface) {
            return $context;
        }
        if (is_array($context) && ($context['package'] ?? null) instanceof PackageInterface) {
            return $context['package'];
        }
        return null;
    }

    /**
     * @return non-empty-string
     */
    private function getDefaultNexusDistUrl(string $vendor, string $name, string $version): string
    {
        return sprintf(
            '%1$s/%2$s/%3$s%5$s/%4$s/%2$s-%3$s%5$s-%4$s',
            $this->rootUrl,
            rawurlencode($vendor),
            rawurlencode($name),
            rawurlencode($version),
            self::startsWith($version, 'dev-') ? '~dev' : ''
        );
    }

    /**
     * @return non-empty-string|null
     */
    private function resolveNexusDistUrl(string $vendor, string $name, string $version, ?string $reference): ?string
    {
        $distUrl = null;
        $referenceDistUrl = null;
        foreach ($this->getNexusPackageEntries($vendor, $name) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // p2 metadata is minified: a missing dist key means "same as the previous version"
            if (array_key_exists('dist', $entry)) {
                $distUrl = self::getDistUrl($entry['dist']);
            }
            if ($distUrl === null) {
                continue;
            }

            // An exact version match wins; a matching commit reference is only used as a fallback
            if (self::versionsMatch($entry['version'] ?? null, $version)) {
                return $this->getAbsoluteNexusUrl($distUrl);
            }
            if ($referenceDistUrl === null && $reference && self::getEntryReference($entry) === $reference) {
                $referenceDistUrl = $distUrl;
            }
        }

        return $referenceDistUrl === null ? null : $this->getAbsoluteNexusUrl($referenceDistUrl);
    }

    /**
     * @return array<mixed>
     */
    private function getNexusPackageEntries(string $vendor, string $name): array
    {
        $metadataUrl = sprintf('%s/p2/%s/%s.json', $this->rootUrl, rawurlencode($vendor), rawurlencode($name));
        if (!array_key_exists($metadataUrl, self::$metadataCache)) {
            self::$metadataCache[$metadataUrl] = $this->fetchUrlQuietly($metadataUrl);
        }

        $json = self::$metadataCache[$metadataUrl];
        if ($json === null) {
            return [];
        }

        $data = json_decode($json, true);
        $packages = is_array($data) ? ($data['packages'] ?? null) : null;
        $entries = is_array($packages) ? ($packages[$vendor . '/' . $name] ?? null) : null;
        return is_array($entries) ? $entries : [];
    }

    /**
     * @param mixed $dist
     * @return non-empty-string|null
     */
    private static function getDistUrl($dist): ?string
    {
        $url = is_array($dist) ? ($dist['url'] ?? null) : null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param mixed $candidate
     */
    private static function versionsMatch($candidate, string $version): bool
    {
        return is_string($candidate) && ltrim($candidate, 'v') === ltrim($version, 'v');
    }

    /**
     * @param array<mixed> $entry
     */
    private static function getEntryReference(array $entry): ?string
    {
        $reference = $entry['reference'] ?? null;
        if (is_string($reference)) {
            return $reference;
        }
        $dist = $entry['dist'] ?? null;
        $reference = is_array($dist) ? ($dist['reference'] ?? null) : null;
        return is_string($reference) ? $reference : null;
    }

    /**
     * @param non-empty-string $url
     * @return non-empty-string
     */
    private function getAbsoluteNexusUrl(string $url): string
    {
        return self::isHttpUrl($url) ? $url : $this->rootUrl . '/' . ltrim($url, '/');
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
            if (PHP_VERSION_ID < 80000) {
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

    private static function isHttpUrl(string $url): bool
    {
        return self::startsWith($url, 'http://') || self::startsWith($url, 'https://');
    }

    private static function isPatchUrl(string $url): bool
    {
        // Skip patch and diff files (e.g. cweagans/composer-patches)
        $lowerUrl = strtolower($url);
        return self::contains($lowerUrl, '.patch') || self::contains($lowerUrl, '.diff');
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
