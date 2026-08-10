#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "${SCRIPT_DIR}/.."

network="${DOCKER_NETWORK:-composer-nexus_default}"
velocitaUrl="${1:-http://velocita-proxy:8080/}"
nexusUrl="${2:-http://nexus-proxy:8081/repository/composer-proxy/}"

phpVersions=(7.4 8.0 8.1 8.2)
composerVersions=(2.2.21 2.4.4 2.5.8 2.6.5)
testImage=velocita-test-image

buildImage() {
    local phpVersion=$1
    local composerVersion=$2
    local userUid=$(id -u 2>/dev/null || echo 1000)

    docker pull "composer:${composerVersion}" >/dev/null 2>&1 || true
    docker pull "php:${phpVersion}-cli-alpine3.16" >/dev/null 2>&1 || true

    docker build \
        --build-arg PHP_VERSION="${phpVersion}" \
        --build-arg COMPOSER_VERSION="${composerVersion}" \
        --build-arg USER_UID="${userUid}" \
        -t "${testImage}:php-${phpVersion}-composer-${composerVersion}" \
        -f tests/Dockerfile.test \
        .
}

runTestSuite() {
    local phpVersion=$1
    local composerVersion=$2
    local proxyType=$3
    local proxyUrl=$4

    local outputDir="test-results/${proxyType}/php-${phpVersion}-composer-${composerVersion}"
    mkdir -p "${outputDir}"

    buildImage "${phpVersion}" "${composerVersion}"
    docker run -t \
        --network "${network}" \
        --env PROXY_TYPE="${proxyType}" \
        --env PROXY_URL="${proxyUrl}" \
        --env VELOCITA_URL="${proxyUrl}" \
        --mount type=bind,source=$(pwd)/${outputDir},target=/output \
        "${testImage}:php-${phpVersion}-composer-${composerVersion}"
}

echo "=== Running test matrix against Velocita Proxy (${velocitaUrl}) ==="
for phpVersion in "${phpVersions[@]}"; do
    for composerVersion in "${composerVersions[@]}"; do
        runTestSuite "${phpVersion}" "${composerVersion}" "velocita" "${velocitaUrl}"
    done
done

echo "=== Running test matrix against Nexus Proxy (${nexusUrl}) ==="
for phpVersion in "${phpVersions[@]}"; do
    for composerVersion in "${composerVersions[@]}"; do
        runTestSuite "${phpVersion}" "${composerVersion}" "nexus" "${nexusUrl}"
    done
done

echo 'All tests executed successfully for both Velocita and Nexus proxies!'
