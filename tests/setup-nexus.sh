#!/bin/sh
set -e

NEXUS_URL="${NEXUS_URL:-http://nexus-proxy:8081}"
echo "Waiting for Nexus 3 to start at ${NEXUS_URL}..."

until curl -s -f "${NEXUS_URL}/service/rest/v1/status" > /dev/null 2>&1; do
    echo "Nexus is still initializing, waiting 5s..."
    sleep 5
done

echo "Nexus 3 is UP!"

# Get admin password
PASSWORD_FILE="/nexus-data/admin.password"
if [ -f "$PASSWORD_FILE" ]; then
    ADMIN_PASS=$(cat "$PASSWORD_FILE")
    echo "Found initial admin password."
else
    ADMIN_PASS="admin123"
    echo "Using default admin password."
fi

# Enable Anonymous Access
echo "Enabling anonymous access in Nexus..."
curl -s -u "admin:${ADMIN_PASS}" -X PUT "${NEXUS_URL}/service/rest/v1/security/anonymous" \
    -H "Content-Type: application/json" \
    -d '{"enabled": true, "userId": "anonymous", "realmName": "NexusAuthorizingRealm"}' || true

# Accept EULA
echo "Accepting EULA in Nexus..."
curl -s -u "admin:${ADMIN_PASS}" -X POST "${NEXUS_URL}/service/rest/v1/system/eula" \
    -H "Content-Type: application/json" \
    -d "@/tests/eula.json" || true

# Check if composer-proxy repository already exists
REPO_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -u "admin:${ADMIN_PASS}" "${NEXUS_URL}/service/rest/v1/repositories/composer-proxy" || true)

if [ "$REPO_STATUS" = "200" ]; then
    echo "Composer proxy repository 'composer-proxy' already exists."
else
    echo "Creating Composer proxy repository 'composer-proxy'..."
    curl -s -f -u "admin:${ADMIN_PASS}" -X POST "${NEXUS_URL}/service/rest/v1/repositories/composer/proxy" \
        -H "Content-Type: application/json" \
        -d "@/tests/nexus-repo.json"
    echo "Successfully created Composer proxy repository 'composer-proxy'."
fi

echo "Nexus 3 setup complete!"
