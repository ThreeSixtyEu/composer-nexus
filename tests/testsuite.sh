#!/bin/ash
set -euo pipefail

proxyUrl="${PROXY_URL:-${VELOCITA_URL:-}}"
proxyType="${PROXY_TYPE:-velocita}"
enableCmd="${proxyType}:enable"
disableCmd="${proxyType}:disable"

# Show versions
phpVersion=$(php -i | grep -m 1 'PHP Version' | cut -d' ' -f4)
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
    cleanup
    composer install --no-interaction --no-autoloader --no-scripts --profile -vvv 2>&1 | tee "${outputPath}"
}

runCreateProject() {
    local packageName="$1"
    local outputPath="$2"
    cleanup
    composer create-project --no-interaction --profile -vvv "${packageName}" project 2>&1 | tee "${outputPath}"
}

installPlugin() {
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
runInstall "/output/${proxyType}-install-output.txt"

# Symfony Flex install
disablePlugin
if [[ "${phpVersion}" == 7.4.* ]]; then
    composer global require symfony/flex:1.20.2
else
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
