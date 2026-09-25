.PHONY: build up down migrate lint cleanup cutover-check unit phpunit-integration integration integration-client integration-broker integration-ldap integration-ldap-external

build:
	docker compose build

up:
	docker compose up

down:
	docker compose down

migrate:
	docker compose run --rm app php yii migrate --interactive=0

lint:
	docker compose run --rm app composer lint

unit:
	docker compose run --rm --no-deps app vendor/bin/phpunit --testsuite Unit

phpunit-integration:
	docker compose run --rm app sh -lc "php yii migrate --interactive=0 && vendor/bin/phpunit --testsuite Integration"

cleanup:
	docker compose run --rm app php yii cleanup/run

cutover-check:
	docker compose run --rm app php yii cutover/check

integration:
	docker compose run --rm app sh -lc "php yii migrate --interactive=0 && SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t web web/router.php >/tmp/sso-php-integration.log 2>&1 & php -S 127.0.0.1:8082 -t tests/Fixtures/backchannel_logout tests/Fixtures/backchannel_logout/router.php >/tmp/sso-php-backchannel.log 2>&1 & sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 SSO_TEST_BACKCHANNEL_URL=http://127.0.0.1:8082 php tests/Integration/oauth_flow.php"

integration-client:
	docker compose run --rm app sh -lc "php yii migrate --interactive=0 && SSO_ISSUER=http://127.0.0.1:8081 SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t web web/router.php >/tmp/sso-php-client.log 2>&1 & sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 php tests/Integration/oauth_client.php"

integration-broker:
	docker compose run --rm app sh -lc "php yii migrate --interactive=0 && SSO_ISSUER=http://127.0.0.1:8081 SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t web web/router.php >/tmp/sso-php-broker-app.log 2>&1 & php -S 127.0.0.1:8082 -t tests/Fixtures/oidc_provider tests/Fixtures/oidc_provider/router.php >/tmp/sso-php-broker-idp.log 2>&1 & sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 SSO_TEST_PROVIDER_URL=http://127.0.0.1:8082 php tests/Integration/broker_mock_flow.php"

integration-ldap:
	docker compose --profile integration up -d --wait postgres ldap-test
	docker compose --profile integration run --rm --no-deps -e SSO_TEST_LDAP_HOST=ldap-test -e SSO_TEST_LDAP_PORT=389 -e SSO_TEST_LDAP_BIND_DN=cn=admin,dc=example,dc=org -e SSO_TEST_LDAP_BIND_PASSWORD=admin-password -e SSO_TEST_LDAP_BASE_DN=ou=people,dc=example,dc=org -e SSO_TEST_LDAP_LOGIN=fixture-user -e SSO_TEST_LDAP_PASSWORD=fixture-password -e SSO_TEST_LDAP_GROUP_DN=cn=sso-users,ou=groups,dc=example,dc=org -e SSO_TEST_LDAP_GROUP_ATTRIBUTE=departmentNumber app sh -lc "php yii migrate --interactive=0 && php tests/Integration/ldap_bind_flow.php"
	docker compose --profile integration stop ldap-test

integration-ldap-external:
	docker compose run --rm app sh -lc "php yii migrate --interactive=0 && php tests/Integration/ldap_bind_flow.php"
