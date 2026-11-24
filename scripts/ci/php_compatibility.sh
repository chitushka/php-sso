#!/bin/sh
set -eu

if [ "${SSO_ENV:-}" != test ]; then
    echo 'PHP compatibility checks require SSO_ENV=test' >&2
    exit 1
fi

composer check-platform-reqs
case "${1:-}" in
    '') php-fpm -t ;;
    --cli) php -v ;;
    *) echo 'Usage: php_compatibility.sh [--cli]' >&2; exit 1 ;;
esac
composer lint
composer test:unit
php scripts/ci/migration_check.php
php scripts/ci/prepare_test_database.php
php yii migrate --interactive=0
composer test:integration

export SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000
export SSO_OUTBOUND_HOST_ALLOWLIST=127.0.0.1
export SSO_TEST_BASE_URL=http://127.0.0.1:8081
export SSO_TEST_BACKCHANNEL_URL=http://127.0.0.1:8082
export SSO_TEST_PROVIDER_URL=http://127.0.0.1:8083

php -S 127.0.0.1:8081 -t web web/router.php >/tmp/sso-compat-app.log 2>&1 &
app_pid=$!
php -S 127.0.0.1:8082 -t tests/Fixtures/backchannel_logout tests/Fixtures/backchannel_logout/router.php >/tmp/sso-compat-backchannel.log 2>&1 &
backchannel_pid=$!
php -S 127.0.0.1:8083 -t tests/Fixtures/oidc_provider tests/Fixtures/oidc_provider/router.php >/tmp/sso-compat-provider.log 2>&1 &
provider_pid=$!
trap 'kill "$app_pid" "$backchannel_pid" "$provider_pid" 2>/dev/null || true' EXIT
sleep 1

for flow in oauth_flow oauth_client broker_mock_flow; do
    php scripts/ci/prepare_test_database.php
    php yii migrate --interactive=0
    if ! php "tests/Integration/$flow.php"; then
        cat /tmp/sso-compat-app.log /tmp/sso-compat-backchannel.log /tmp/sso-compat-provider.log >&2
        exit 1
    fi
done

echo 'PHP compatibility checks passed'
