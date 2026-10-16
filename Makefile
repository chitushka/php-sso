.PHONY: build build-test runtime-check up up-development down migrate lint cs analyse unit phpunit-integration coverage audit verify-assets verify-docs secret-scan migration-check quality cleanup integration integration-client integration-broker integration-ldap integration-ldap-external ci deployment-check db-invariant-check db-invariant-repair encryption-key-rotate-dry-run encryption-key-rotate local-encryption-key-generate local-secure-runtime-configure backup-restore-test maintenance-on maintenance-off smoke smoke-authenticated smoke-authenticated-local smoke-local

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
	docker compose -f docker-compose.yml -f docker-compose.development.yml --env-file .env.development --profile operations up -d

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

deployment-check:
	docker compose run --rm app php yii deployment/check

db-invariant-check:
	docker compose run --rm app php yii deployment/repair-db-invariants

db-invariant-repair:
	docker compose run --rm app php yii deployment/repair-db-invariants --apply=1

encryption-key-rotate-dry-run:
	docker compose run --rm -e SSO_OLD_ENCRYPTION_KEY -e SSO_NEW_ENCRYPTION_KEY app php yii deployment/rotate-encryption-key

encryption-key-rotate:
	docker compose run --rm -e SSO_OLD_ENCRYPTION_KEY -e SSO_NEW_ENCRYPTION_KEY app php yii deployment/rotate-encryption-key --apply=1

local-encryption-key-generate:
	docker compose run --rm app php yii deployment/generate-local-encryption-key

local-secure-runtime-configure:
	docker compose run --rm app php yii deployment/configure-local-secure-runtime

backup-restore-test:
	docker compose --profile operations run --rm backup-restore-test

maintenance-on:
	docker compose --profile operations run --rm maintenance-on

maintenance-off:
	docker compose --profile operations run --rm maintenance-off

smoke:
	docker compose --profile test run --rm --no-deps test php tests/Integration/deployment_smoke.php

smoke-authenticated:
	docker compose --profile test run --rm --no-deps test php tests/Integration/deployment_authenticated_smoke.php

smoke-authenticated-local:
	docker compose --profile test run --rm --no-deps test php tests/Integration/deployment_authenticated_smoke_local.php

smoke-local:
	docker compose --profile test run --rm --no-deps -e SSO_TEST_BASE_URL=https://https-proxy:8443 -e SSO_TEST_EXPECTED_ISSUER=https://localhost:8443 -e SSO_TEST_TLS_INSECURE=true test php tests/Integration/deployment_smoke.php
