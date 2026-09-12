.PHONY: build up down migrate lint cleanup integration integration-client

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

cleanup:
	docker compose run --rm app php yii cleanup/run

integration:
	docker compose run --rm app sh -lc "php yii migrate --interactive=0 && SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t public public/router.php >/tmp/sso-php-integration.log 2>&1 & sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 php tests/Integration/oauth_flow.php"

integration-client:
	docker compose run --rm app sh -lc "php yii migrate --interactive=0 && SSO_ISSUER=http://127.0.0.1:8081 SSO_RATE_LIMIT_PER_MINUTE=1000 SSO_RATE_LIMIT_BURST=1000 php -S 127.0.0.1:8081 -t public public/router.php >/tmp/sso-php-client.log 2>&1 & sleep 1 && SSO_TEST_BASE_URL=http://127.0.0.1:8081 php tests/Integration/oauth_client.php"
