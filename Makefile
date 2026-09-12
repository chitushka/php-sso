.PHONY: build up down migrate lint cleanup

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
