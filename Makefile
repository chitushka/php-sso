.PHONY: build build-test runtime-check up up-development down migrate lint cs analyse unit phpunit-integration coverage audit verify-assets verify-docs secret-scan migration-check quality cleanup integration integration-client integration-broker integration-ldap integration-ldap-external ci cutover-check cutover-rewrap-dry-run cutover-rewrap cutover-rotate-key-dry-run cutover-rotate-key cutover-generate-local-key cutover-configure-local-secure-runtime cutover-rehearse-rollback cutover-maintenance-on cutover-maintenance-off cutover-smoke cutover-smoke-authenticated cutover-smoke-authenticated-local cutover-smoke-local

TEST_RUN = docker compose --profile test run --rm test
TEST_RUN_NODEPS = docker compose --profile test run --rm --no-deps test

build:
	docker compose build app web

build-test:
	docker compose --profile test build test

runtime-check:
	docker run --rm --entrypoint sh sso-php-app:latest -c 'test "$$(id -u)" = 33 && test ! -e /app/tests && test ! -e /app/composer.json && test ! -e /app/vendor/bin/phpunit'

up:
	docker compose --profile operations up -d

up-development:
	docker compose --env-file .env.development --profile operations up -d

down:
	docker compose --profile operations down

migrate:
	docker compose --profile operations run --rm migrate

lint:
	$(TEST_RUN_NODEPS) composer lint

cs:
	$(TEST_RUN_NODEPS) composer cs

analyse:
	$(TEST_RUN_NODEPS) composer analyse

unit:
	$(TEST_RUN_NODEPS) composer test:unit

phpunit-integration:
	$(TEST_RUN) sh -lc "php scripts/ci/prepare_test_database.php && php yii migrate --interactive=0 && composer test:integration"

coverage:
	$(TEST_RUN) sh -lc "php scripts/ci/prepare_test_database.php && php yii migrate --interactive=0 && composer test:coverage && composer verify:coverage"

audit:
	$(TEST_RUN_NODEPS) composer security:audit

verify-assets:
	$(TEST_RUN_NODEPS) composer verify:assets

verify-docs:
	$(TEST_RUN_NODEPS) composer verify:docs

secret-scan:
	$(TEST_RUN_NODEPS) composer verify:secrets

migration-check:
	$(TEST_RUN) php scripts/ci/migration_check.php

quality: lint cs analyse verify-assets verify-docs secret-scan

cleanup:
	docker compose run --rm app php yii cleanup/run

integration:
	$(TEST_RUN) sh -lc "php scripts/ci/prepare_test_database.php && php yii migrate --interactive=0 && (SSO_OUTBOUND_HOST_ALLOWLIST=127.0.0.1 SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t web web/router.php >/tmp/sso-php-integration.log 2>&1 &) && (php -S 127.0.0.1:8082 -t tests/Fixtures/backchannel_logout tests/Fixtures/backchannel_logout/router.php >/tmp/sso-php-backchannel.log 2>&1 &) && sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 SSO_TEST_BACKCHANNEL_URL=http://127.0.0.1:8082 php tests/Integration/oauth_flow.php || { status=$$?; cat /tmp/sso-php-integration.log /tmp/sso-php-backchannel.log; exit $$status; }"

integration-client:
	$(TEST_RUN) sh -lc "php scripts/ci/prepare_test_database.php && php yii migrate --interactive=0 && (SSO_ISSUER=http://127.0.0.1:8081 SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t web web/router.php >/tmp/sso-php-client.log 2>&1 &) && sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 php tests/Integration/oauth_client.php || { status=$$?; cat /tmp/sso-php-client.log; exit $$status; }"

integration-broker:
	$(TEST_RUN) sh -lc "php scripts/ci/prepare_test_database.php && php yii migrate --interactive=0 && (SSO_ISSUER=http://127.0.0.1:8081 SSO_OUTBOUND_HOST_ALLOWLIST=127.0.0.1 SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t web web/router.php >/tmp/sso-php-broker-app.log 2>&1 &) && (php -S 127.0.0.1:8082 -t tests/Fixtures/oidc_provider tests/Fixtures/oidc_provider/router.php >/tmp/sso-php-broker-idp.log 2>&1 &) && sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 SSO_TEST_PROVIDER_URL=http://127.0.0.1:8082 php tests/Integration/broker_mock_flow.php || { status=$$?; cat /tmp/sso-php-broker-app.log /tmp/sso-php-broker-idp.log; exit $$status; }"

integration-ldap:
	docker compose --profile integration up -d --wait postgres ldap-test
	docker compose --profile test --profile integration run --rm --no-deps -e SSO_LDAP_ALLOW_INSECURE=true -e SSO_LDAP_ALLOWED_HOSTS=ldap-test -e SSO_TEST_LDAP_HOST=ldap-test -e SSO_TEST_LDAP_PORT=389 -e SSO_TEST_LDAP_BIND_DN=cn=admin,dc=example,dc=org -e SSO_TEST_LDAP_BIND_PASSWORD=admin-password -e SSO_TEST_LDAP_BASE_DN=ou=people,dc=example,dc=org -e SSO_TEST_LDAP_LOGIN=fixture-user -e SSO_TEST_LDAP_PASSWORD=fixture-password -e SSO_TEST_LDAP_GROUP_DN=cn=sso-users,ou=groups,dc=example,dc=org -e SSO_TEST_LDAP_GROUP_ATTRIBUTE=departmentNumber test sh -lc "php scripts/ci/prepare_test_database.php && php yii migrate --interactive=0 && php tests/Integration/ldap_bind_flow.php"
	docker compose --profile integration stop ldap-test

integration-ldap-external:
	$(TEST_RUN) sh -lc "php yii migrate --interactive=0 && php tests/Integration/ldap_bind_flow.php"

ci: build build-test runtime-check quality migration-check unit phpunit-integration coverage integration integration-client integration-broker integration-ldap audit

cutover-check:
	docker compose run --rm app php yii cutover/check

cutover-rewrap-dry-run:
	docker compose run --rm app php yii cutover/rewrap-secrets

cutover-rewrap:
	docker compose run --rm app php yii cutover/rewrap-secrets --apply=1

cutover-rotate-key-dry-run:
	docker compose run --rm -e SSO_OLD_ENCRYPTION_KEY -e SSO_NEW_ENCRYPTION_KEY app php yii cutover/rotate-encryption-key

cutover-rotate-key:
	docker compose run --rm -e SSO_OLD_ENCRYPTION_KEY -e SSO_NEW_ENCRYPTION_KEY app php yii cutover/rotate-encryption-key --apply=1

cutover-generate-local-key:
	docker compose run --rm app php yii cutover/generate-local-encryption-key

cutover-configure-local-secure-runtime:
	docker compose run --rm app php yii cutover/configure-local-secure-runtime

cutover-rehearse-rollback:
	docker compose --profile cutover run --rm rollback-rehearsal

cutover-maintenance-on:
	docker compose --profile cutover run --rm maintenance-on

cutover-maintenance-off:
	docker compose --profile cutover run --rm maintenance-off

cutover-smoke:
	docker compose --profile test run --rm --no-deps test php tests/Integration/cutover_smoke.php

cutover-smoke-authenticated:
	docker compose --profile test run --rm --no-deps test php tests/Integration/cutover_authenticated_smoke.php

cutover-smoke-authenticated-local:
	docker compose --profile test run --rm --no-deps test php tests/Integration/cutover_authenticated_smoke_local.php

cutover-smoke-local:
	docker compose --profile test run --rm --no-deps -e SSO_TEST_BASE_URL=https://https-proxy:8443 -e SSO_TEST_EXPECTED_ISSUER=https://localhost:8443 -e SSO_TEST_TLS_INSECURE=true test php tests/Integration/cutover_smoke.php
