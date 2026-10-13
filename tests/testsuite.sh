#!/bin/ash
set -euo pipefail

proxyUrl="${PROXY_URL:-${VELOCITA_URL:-}}"
proxyType="${PROXY_TYPE:-velocita}"
enableCmd="${proxyType}:enable"
disableCmd="${proxyType}:disable"

# Show versions
phpVersion=$(php -r 'echo PHP_VERSION;')
composerVersion=$(composer --version | cut -d' ' -f3)
echo
echo "PHP ${phpVersion} - Composer ${composerVersion} - Mode: ${proxyType} (${proxyUrl})"
echo '----------'
echo

cleanup() {
    rm -rf project vendor
    composer clear-cache
}

runInstall() {
    local outputPath="$1"
    mkdir -p "$(dirname "$outputPath")"
    cleanup
    composer install --no-interaction --no-autoloader --no-scripts --profile -vvv 2>&1 | tee "${outputPath}"
}

runCreateProject() {
    local packageName="$1"
    local outputPath="$2"
    mkdir -p "$(dirname "$outputPath")"
    cleanup
    composer create-project --no-interaction --profile -vvv "${packageName}" project 2>&1 | tee "${outputPath}"
}

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

assertOutputContains() {
    local outputPath="$1"
    local needle="$2"
    grep -qF -- "${needle}" "${outputPath}" || fail "expected '${needle}' in ${outputPath}"
}

assertConfigType() {
    local expectedType="$1"
    local configPath
    configPath="$(composer config -g home --no-plugins)/velocita.json"
    grep -qF "\"type\": \"${expectedType}\"" "${configPath}"         || fail "expected type '${expectedType}' in ${configPath}: $(cat "${configPath}")"
}

installPlugin() {
    composer global config --no-plugins allow-plugins.gmta/composer-velocita true
    composer global config repositories.velocita-src path /usr/src/velocita/
    composer global require gmta/composer-velocita @dev
}

enablePlugin() {
    composer ${enableCmd} "${proxyUrl}"
}

disablePlugin() {
    composer ${disableCmd}
}

echo '{"require":{"phpunit/phpunit":"9.6.10"}}' > composer.json

# Vanilla install
runInstall /output/vanilla-install-output.txt

# Configure Composer to allow plugins and HTTP proxies
composer config -g secure-http false
composer config -g allow-plugins.symfony/flex true
composer config -g allow-plugins.gmta/composer-velocita true

# Plugin install
installPlugin
enablePlugin
assertConfigType "${proxyType}"
runInstall "/output/${proxyType}-install-output.txt"
if [ "${proxyType}" = "nexus" ]; then
    assertOutputContains "/output/${proxyType}-install-output.txt" '[Velocita-Nexus] Rewriting URL'
else
    assertOutputContains "/output/${proxyType}-install-output.txt" 'mapped to'
fi

# Symfony Flex install
disablePlugin
if [[ "${phpVersion}" == 7.4.* ]]; then
    composer global config --no-plugins allow-plugins.symfony/flex true
    composer global require symfony/flex:1.20.2
else
    composer global config --no-plugins allow-plugins.symfony/flex true
    composer global require symfony/flex:2.3.3
fi
runInstall /output/flex-install-output.txt

# Plugin + Symfony Flex install
enablePlugin
runInstall "/output/${proxyType}-flex-install-output.txt"
composer global remove symfony/flex

# Vanilla create-project
if [[ "${phpVersion}" == 7.4.* ]]; then
    symfonyVersion="v5.4.99"
else
    symfonyVersion="v6.0.99"
fi
disablePlugin
runCreateProject symfony/skeleton:${symfonyVersion} /output/vanilla-create-project-output.txt

# Plugin + Symfony Flex create-project
enablePlugin
runCreateProject symfony/skeleton:${symfonyVersion} "/output/${proxyType}-create-project-output.txt"

# Enabling another proxy type without a URL must not reuse the configured URL
if [ "${proxyType}" = "nexus" ]; then
    otherType=velocita
else
    otherType=nexus
fi
if composer "${otherType}:enable"; then
    fail "${otherType}:enable without a URL should fail when ${proxyType} is configured"
fi
assertConfigType "${proxyType}"

# An unreachable Velocita instance must disable the plugin instead of breaking installs
if [ "${proxyType}" = "velocita" ]; then
    composer velocita:enable http://127.0.0.1:9/
    runInstall /output/velocita-unreachable-install-output.txt
    assertOutputContains /output/velocita-unreachable-install-output.txt 'Failed to retrieve remote config'
fi

echo 'Test suite passed.'
