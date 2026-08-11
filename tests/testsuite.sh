#!/bin/ash
set -eu

# Show versions
phpVersion=$(php -i | grep -m 1 'PHP Version' | cut -d' ' -f4)
composerVersion=$(composer --version | cut -d' ' -f3)
echo
echo "PHP ${phpVersion} - Composer ${composerVersion}"
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

installNexus() {
    composer global config repositories.nexus-src path /usr/src/nexus/
    composer global require threesixty-eu/composer-nexus @dev
}

enableNexus() {
    composer nexus:enable "${NEXUS_URL:-}"
}

disableNexus() {
    composer nexus:disable
}

echo '{"require":{"phpunit/phpunit":"9.6.10"}}' > composer.json

# Vanilla install
runInstall /output/vanilla-install-output.txt

# Configure Composer to allow plugins
composer config -g allow-plugins.symfony/flex true
composer config -g allow-plugins.threesixty-eu/composer-nexus true

# Nexus install
installNexus
enableNexus
runInstall /output/nexus-install-output.txt

# Symfony Flex install
disableNexus
if [[ "${phpVersion}" == 7.4.* ]]; then
    composer global require symfony/flex:1.20.2
else
    composer global require symfony/flex:2.3.3
fi
runInstall /output/flex-install-output.txt

# Nexus + Symfony Flex install
enableNexus
runInstall /output/nexus-flex-install-output.txt
composer global remove symfony/flex

# Vanilla create-project
if [[ "${phpVersion}" == 7.4.* ]]; then
    symfonyVersion="v5.4.99"
else
    symfonyVersion="v6.0.99"
fi
disableNexus
runCreateProject symfony/skeleton:${symfonyVersion} /output/vanilla-create-project-output.txt

# Nexus + Symfony Flex create-project
enableNexus
runCreateProject symfony/skeleton:${symfonyVersion} /output/nexus-create-project-output.txt
