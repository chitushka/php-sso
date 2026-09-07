# SSO PHP

PHP 7.4/Yii2 перенос рабочего Go SSO сервиса из `D:\workspace\GolandProjects\sso`. Проект сохраняет single-tenant подход, PostgreSQL, OAuth2/OIDC контракты и административный UI на VueJS.

## Быстрый старт

```bash
cp .env.example .env
docker compose build
docker compose up
```

Приложение будет доступно на `http://localhost:8080`.
PostgreSQL публикуется на `localhost:5433`, внутри Docker используется `postgres:5432`.

Полезные команды:

```bash
make build    # собрать Docker-образ
make up       # запустить app + PostgreSQL
make migrate  # применить Yii2-миграции
make lint     # проверить PHP-синтаксис
```

## Конфигурация

Основные переменные находятся в `.env.example` и используют namespace `SSO_*`:

- `SSO_DATABASE_URL` — PostgreSQL DSN.
- `SSO_ISSUER` — публичный issuer URL для OAuth2/OIDC.
- `SSO_JWT_SECRET` — HMAC-секрет access token, минимум 32 символа.
- `SSO_ENCRYPTION_KEY` — ключ для будущего шифрования секретов, минимум 32 символа.
- `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL` — TTL в секундах.
- `SSO_RATE_LIMIT_PER_MINUTE`, `SSO_RATE_LIMIT_BURST` — лимит чувствительных auth/OAuth/bootstrap запросов.
- `SSO_MAX_BODY_BYTES` — максимальный размер JSON/form тела запроса.

Для production замените все placeholder-секреты.

## Уже перенесено

- Yii2/Docker/PostgreSQL каркас.
- Стартовая PostgreSQL-схема из Go-миграций `000001-000010`.
- `GET /health/live`, `GET /health/ready`, `GET /health/version`.
- `GET /api/v1/bootstrap/status`, `POST /api/v1/bootstrap`.
- `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`.
- Просмотр и отзыв пользовательских сессий.
- Brute-force lockout по паре login/IP и rate limit для чувствительных endpoint-ов.
- RBAC-проверка admin API с прямыми ролями и ролями через группы.
- Admin API для users, roles, permissions, groups и audit.
- CRUD-основа OAuth-клиентов.
- OAuth2 Authorization Code + PKCE, refresh token, revoke, introspect, client_credentials.
- OIDC Discovery, JWKS, UserInfo и RS256 ID Token.
- Базовая Vue-админка для bootstrap/login/OAuth clients.

## Следующие работы

Актуальный порядок переноса зафиксирован в `PLAN.md`. Ближайшие задачи: LDAP providers API, детальные UI-формы назначения ролей/групп, LDAP Search + Bind, MFA TOTP, password reset/email verification и identity brokering.

## Важные изменения

- 2026-09-07: создан начальный Yii2-порт SSO с Docker, PostgreSQL, миграцией схемы, базовой auth/OAuth/OIDC функциональностью и Vue UI. Внешний порт PostgreSQL установлен в `5433`, чтобы не конфликтовать с локальными БД.
- 2026-09-07: dev-сервер переведён на `public/router.php`, чтобы Yii2 корректно обрабатывал `/.well-known/*` маршруты.
- 2026-09-07: добавлен `.dockerignore`, а Dockerfile использует `composer.lock` для воспроизводимой установки Yii2.
- 2026-09-07: перенесены brute-force lockout и rate limiting из Go-сервиса; добавлено ограничение тела запроса 1 MiB.
- 2026-09-07: добавлен явный Yii2 `controllerMap` для сохранения маршрутов `oauth/*`.
- 2026-09-07: перенесены RBAC-проверка, Users/Roles/Permissions/Groups/Audit admin API и базовые Vue-вкладки для этих разделов.
