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
make cleanup  # удалить истёкшие auth/OAuth артефакты
make integration # прогнать OAuth/OIDC smoke flow
```

## Конфигурация

Основные переменные находятся в `.env.example` и используют namespace `SSO_*`:

- `SSO_DATABASE_URL` — PostgreSQL DSN.
- `SSO_ISSUER` — публичный issuer URL для OAuth2/OIDC.
- `SSO_JWT_SECRET` — HMAC-секрет access token, минимум 32 символа.
- `SSO_ENCRYPTION_KEY` — ключ для будущего шифрования секретов, минимум 32 символа.
- `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL` — TTL в секундах.
- `SSO_RATE_LIMIT_PER_MINUTE`, `SSO_RATE_LIMIT_BURST` — лимит чувствительных auth/OAuth/bootstrap запросов.
- `SSO_CACHE_DRIVER` — `file` по умолчанию или `db` для PostgreSQL-backed Yii cache/rate limit при нескольких app-инстансах.
- `SSO_TRUSTED_PROXIES` — comma-separated IP/CIDR reverse proxy, которым разрешено задавать `X-Forwarded-For`.
- `SSO_MAX_BODY_BYTES` — максимальный размер JSON/form тела запроса.
- `SSO_SMTP_HOST`, `SSO_SMTP_PORT`, `SSO_SMTP_USERNAME`, `SSO_SMTP_PASSWORD`, `SSO_SMTP_FROM`, `SSO_SMTP_STARTTLS` — SMTP для reset/verification писем. Если `SSO_SMTP_HOST` или `SSO_SMTP_FROM` пустые, письмо пишется в лог приложения.

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
- Admin API для LDAP providers, test bind и LDAP group mappings.
- AES-256-GCM шифрование LDAP bind password в PostgreSQL.
- LDAP Search + Bind authentication при login с auto-provisioning LDAP-пользователей.
- Синхронизация LDAP group mappings в группы пользователя с `source=ldap`.
- MFA TOTP setup/enable/disable, MFA challenge при login и одноразовые recovery codes.
- `POST /api/v1/auth/password/forgot`, `/reset`, `/change` для password reset/change.
- `PUT /api/v1/auth/profile` для self-service обновления first/last name и attributes.
- `POST /api/v1/auth/email/request`, `/verify` для email verification.
- `GET /api/v1/broker/providers`, `/oauth2/broker/{code}/login`, `/oauth2/broker/{code}/callback` для external sign-in.
- Admin API `GET/POST/PUT/DELETE /api/v1/identity-providers` для Google/GitHub/OIDC federation.
- Trusted-proxy-aware client IP через `SSO_TRUSTED_PROXIES`.
- `php yii cleanup/run` для удаления истёкших sessions, authorization codes, refresh tokens, one-time tokens и login attempts.
- Опциональный PostgreSQL-backed Yii cache через `SSO_CACHE_DRIVER=db` для shared rate limit.
- CRUD-основа OAuth-клиентов.
- OAuth2 Authorization Code + PKCE, refresh token, revoke, introspect, client_credentials.
- Refresh token rotation с reuse detection: повтор старого refresh token отзывает всю token family.
- Public OAuth clients требуют PKCE S256; ошибки OAuth endpoints возвращаются в RFC-style формате `error` + `error_description`.
- OIDC Discovery, JWKS, UserInfo и RS256 ID Token.
- Vue-админка для bootstrap/login/external sign-in/forgot/reset password/account profile/MFA/email verification/OAuth clients/identity providers/users/roles/groups/LDAP/audit.
- UI-формы назначения ролей пользователям, групп пользователям, permissions ролям и ролей группам.

## Следующие работы

Актуальный порядок переноса зафиксирован в `PLAN.md`. Ближайшие задачи: расширение интеграционных проверок и сверка контрактов ошибок с Go-сервисом.

## Важные изменения

- 2026-09-07: создан начальный Yii2-порт SSO с Docker, PostgreSQL, миграцией схемы, базовой auth/OAuth/OIDC функциональностью и Vue UI. Внешний порт PostgreSQL установлен в `5433`, чтобы не конфликтовать с локальными БД.
- 2026-09-07: dev-сервер переведён на `public/router.php`, чтобы Yii2 корректно обрабатывал `/.well-known/*` маршруты.
- 2026-09-07: добавлен `.dockerignore`, а Dockerfile использует `composer.lock` для воспроизводимой установки Yii2.
- 2026-09-07: перенесены brute-force lockout и rate limiting из Go-сервиса; добавлено ограничение тела запроса 1 MiB.
- 2026-09-07: добавлен явный Yii2 `controllerMap` для сохранения маршрутов `oauth/*`.
- 2026-09-07: перенесены RBAC-проверка, Users/Roles/Permissions/Groups/Audit admin API и базовые Vue-вкладки для этих разделов.
- 2026-09-07: перенесены LDAP providers CRUD, LDAP test bind, LDAP group mappings и шифрование bind password.
- 2026-09-10: добавлены UI-формы RBAC-назначений для пользователей, ролей и групп.
- 2026-09-10: добавлен LDAP Search + Bind fallback для login и синхронизация LDAP group mappings.
- 2026-09-10: добавлены MFA TOTP, login challenge и одноразовые recovery codes.
- 2026-09-12: перенесены password forgot/reset/change и email verification; reset отзывает сессии, refresh tokens и ранее выданные Bearer JWT, reset/verification письма отправляются через SMTP или пишутся в лог.
- 2026-09-12: перенесён identity brokering для Google/GitHub/OIDC: CRUD identity providers, signed state, callback, безопасная линковка по verified email, JIT-provisioning federated users и кнопки external sign-in в Vue UI.
- 2026-09-12: перенесён production hardening для trusted proxy IP, cleanup expired records и shared rate limit через `SSO_CACHE_DRIVER=db`.
- 2026-09-12: добавлен self-service account profile update для `first_name`, `last_name` и `attributes` через API и Vue UI.
- 2026-09-12: hardened refresh token rotation: family сохраняется при rotation, reuse/проигранная гонка отзывает всю refresh family.
- 2026-09-12: добавлен `make integration` с HTTP smoke flow для bootstrap-ready OAuth/OIDC, PKCE, refresh rotation/reuse detection, RBAC enforcement и базовых OAuth error contracts.
- 2026-09-12: public OAuth clients теперь требуют PKCE S256 на authorize.
