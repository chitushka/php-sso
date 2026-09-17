# SSO PHP

PHP 7.4/Yii2 перенос рабочего Go SSO сервиса из `D:\workspace\GolandProjects\sso`. Проект сохраняет single-tenant подход, PostgreSQL, OAuth2/OIDC контракты и административный UI на VueJS.

## Быстрый старт

```bash
cp .env.example .env
docker compose build
docker compose up
```

Локальные PHP и Composer для разработки не требуются: проект запускается и проверяется в Docker-контейнере `app`.

Приложение будет доступно на `http://localhost:8080`.
PostgreSQL публикуется на `localhost:5433`, внутри Docker используется `postgres:5432`.

Полезные команды:

```bash
make build    # собрать Docker-образ
make up       # запустить app + PostgreSQL
make migrate  # применить Yii2-миграции
make lint     # проверить PHP-синтаксис
make unit     # прогнать PHPUnit unit tests
make phpunit-integration # прогнать PHPUnit integration tests против PostgreSQL
make cleanup  # удалить истёкшие auth/OAuth артефакты
make integration # прогнать OAuth/OIDC smoke flow с test-local rate limit
make integration-client # прогнать внешний OAuth/OIDC RP client flow
make integration-broker # прогнать broker callback flow с локальным mock OIDC provider
make integration-ldap # прогнать LDAP bind/search flow, если заданы SSO_TEST_LDAP_*
```

Если `make` недоступен, используйте прямые Docker-команды:

```bash
docker compose run --rm app php yii migrate --interactive=0
docker compose run --rm app composer lint
docker compose run --rm app php yii help
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
- `GET /metrics` в Prometheus text format с dependency-free HTTP counters через Yii cache.
- `GET /api/v1/bootstrap/status`, `POST /api/v1/bootstrap`.
- `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`.
- Просмотр и отзыв пользовательских сессий.
- Brute-force lockout по паре login/IP и rate limit для чувствительных endpoint-ов.
- RBAC-проверка admin API с прямыми ролями и ролями через группы.
- Admin API для users, roles, permissions, groups и audit, включая Go-compatible ошибки создания users/roles/groups и валидацию audit-фильтров.
- Admin API для LDAP providers, test bind и LDAP group mappings.
- AES-256-GCM шифрование LDAP bind password в PostgreSQL.
- LDAP Search + Bind authentication при login с auto-provisioning LDAP-пользователей.
- Синхронизация LDAP group mappings в группы пользователя с `source=ldap`.
- MFA TOTP setup/enable/disable с Go-compatible `enroll`/`activate` алиасами, MFA challenge при login и одноразовые recovery codes.
- `POST /api/v1/auth/password/forgot`, `/reset`, `/change` для password reset/change.
- `PUT /api/v1/auth/profile` для self-service обновления first/last name и attributes.
- `POST /api/v1/auth/email/request`, `/verify` для email verification.
- `GET /api/v1/broker/providers`, `/oauth2/broker/{code}/login`, `/oauth2/broker/{code}/callback` для external sign-in.
- Admin API `GET/POST/PUT/DELETE /api/v1/identity-providers` для Google/GitHub/OIDC federation.
- Trusted-proxy-aware client IP через `SSO_TRUSTED_PROXIES`.
- `php yii cleanup/run` для удаления истёкших sessions, authorization codes, refresh tokens, one-time tokens и login attempts.
- Опциональный PostgreSQL-backed Yii cache через `SSO_CACHE_DRIVER=db` для shared rate limit.
- CRUD-основа OAuth-клиентов.
- OAuth2 Authorization Code + PKCE, одноразовый authorization code, `client_secret_post`/`client_secret_basic`, consent flow, refresh token, revoke, introspect, client_credentials.
- Refresh token rotation с reuse detection: повтор старого refresh token отзывает всю token family.
- Public OAuth clients требуют PKCE S256; ошибки OAuth endpoints возвращаются в RFC-style формате `error` + `error_description`.
- OIDC Discovery, JWKS, UserInfo и RS256 ID Token с проверкой issuer/audience/nonce claims в smoke flow.
- OIDC UserInfo scope filtering: `profile` раскрывает profile claims, `email` раскрывает email.
- RP-initiated logout проверяет `id_token_hint`, registered `post_logout_redirect_uri` и отзывает refresh tokens пользователя.
- PHPUnit unit tests для password policy и trusted-proxy IP resolution.
- PHPUnit integration tests против PostgreSQL для OAuth client service, Authorization Code + PKCE token exchange, refresh rotation/reuse/revoke, OIDC ID token/JWKS/UserInfo/RP logout, auth login/session/JWT/logout, account reset/email/profile security flows, MFA, RBAC, identity provider и LDAP admin flows, PostgreSQL array-полей и audit side effects.
- Local mock OIDC broker smoke `make integration-broker` для внешнего sign-in callback без реального внешнего провайдера.
- Optional LDAP bind/search smoke `make integration-ldap` для реального LDAP test fixture через `SSO_TEST_LDAP_*`.
- Vue-админка для bootstrap/login/external sign-in/forgot/reset password/account profile/MFA/email verification/OAuth clients/identity providers/users/roles/groups/LDAP/audit.
- UI-формы назначения ролей пользователям, групп пользователям, permissions ролям и ролей группам.

## Следующие работы

Актуальный порядок переноса зафиксирован в `PLAN.md`. Заметки для перехода с Go-сервиса находятся в `MIGRATION_NOTES.md`. Ближайшие задачи: дальнейшая сверка endpoint-контрактов с Go-сервисом и запуск `make integration-ldap` против реального LDAP test fixture при наличии секретов.

## Важные изменения

- 2026-09-17: Audit API выровнен с Go по строгой проверке actor UUID, поддержке дробных секунд в RFC3339-фильтрах и pagination semantics: невалидный или превышающий 200 `limit` сбрасывается к 50.
- 2026-09-17: OAuth client create/update semantics выровнены с Go: create возвращает `{client, client_secret}`, пропущенные enabled/array-поля получают zero values, update выполняет полную замену разрешённых полей и сохраняет `type`; UI явно создаёт включённых клиентов.
- 2026-09-17: OAuth consent JSON-контракт выровнен с Go: разрешены только `client_id` и `scope`, а malformed/non-object/unknown-field body возвращает RFC-style 400 `invalid_request` с описанием `invalid json body`.
- 2026-09-17: OAuth clients, Identity Providers и LDAP provider/test/group-mapping JSON payloads выровнены с Go `DisallowUnknownFields`; OAuth client update больше не принимает отсутствующее в Go поле `type`.
- 2026-09-17: Users/Roles/Groups CRUD и assignment JSON payloads выровнены с Go `DisallowUnknownFields`; user update больше не принимает отсутствующие в Go поля `password` и `email_verified`, а лишние ключи возвращают 400 `invalid json body`.
- 2026-09-17: Auth/account/bootstrap JSON payloads выровнены с Go `DisallowUnknownFields`: неизвестные поля возвращают 400 `invalid json body`; Vue MFA flow переведён с `challenge_token`/`secret` на `mfa_token` и `/enroll`/`/activate`.
- 2026-09-17: JSON body parsing выровнен с Go: malformed JSON и JSON-массивы/скаляры на object endpoints возвращают компактный 400 `invalid json body`, при этом сохраняется исходный порядок path UUID и body validation.
- 2026-09-17: LDAP provider PUT/DELETE и group-mapping path UUID выровнены с Go: API возвращает `invalid id`/`invalid mapping id`, корректные отсутствующие UUID дают 404, а delete mapping сохраняет исходную семантику без проверки parent provider ID.
- 2026-09-17: Identity Provider PUT/DELETE path UUID выровнены с Go: невалидный ID возвращает 400 `invalid id` вместо SPA fallback, корректный отсутствующий UUID — 404 `provider not found`.
- 2026-09-17: OAuth client GET/PUT/DELETE/rotate-secret path UUID выровнены с Go: невалидный ID возвращает 400 `invalid id` вместо SPA fallback, отсутствующий клиент — 404 `client not found`, а rotate-secret сохраняет отдельную ошибку `confidential client not found`.
- 2026-09-17: User-role list/assign/remove path/body UUID выровнены с Go; API возвращает `invalid user id`, `invalid role id` и `invalid json body` до обращения к PostgreSQL.
- 2026-09-17: Groups CRUD, group-role и user-group path/body UUID выровнены с Go; составные маршруты возвращают точные `invalid group id`, `invalid user id`, `invalid role id` и `invalid json body`.
- 2026-09-17: Roles CRUD и permission assignment path/body UUID выровнены с Go: API возвращает точные `invalid role id`, `invalid permission id` и `invalid json body` вместо SPA fallback или PostgreSQL-ошибки.
- 2026-09-17: Users GET/PUT/DELETE path UUID и pagination limit выровнены с Go: невалидный ID возвращает 400 JSON вместо SPA fallback, отсутствующий пользователь — 404, максимальный limit равен 100.
- 2026-09-17: `tokens_invalid_before` выровнен с Go-семантикой: password reset и revoke-all отзывают first-party JWT и refresh tokens, но внешний OAuth access token с `client_id` продолжает работать до `exp`; блокировка аккаунта по-прежнему отзывает все типы токенов.
- 2026-09-17: MFA activate/disable lifecycle выровнен с Go-контрактом, включая ошибки повторных операций, успешный статус `mfa_disabled` и сохранение текущего Bearer JWT; устранена также секундная гонка revoke-all с немедленным повторным login.
- 2026-09-17: MFA login verification выровнен с Go-контрактом: invalid token и invalid code различаются, неверные коды участвуют в login/IP lockout, успешная MFA-проверка сбрасывает попытки; поведение закреплено PostgreSQL PHPUnit и HTTP smoke-тестами.
- 2026-09-17: Session API выровнен с Go-сервисом: список показывает только активные неистёкшие сессии и включает `user_id`, невалидный session UUID возвращает 400, а list/revoke/revoke-all и инвалидизация Bearer JWT закреплены PHPUnit и HTTP smoke-проверками.
- 2026-09-07: создан начальный Yii2-порт SSO с Docker, PostgreSQL, миграцией схемы, базовой auth/OAuth/OIDC функциональностью и Vue UI. Внешний порт PostgreSQL установлен в `5433`, чтобы не конфликтовать с локальными БД.
- 2026-09-13: проект переведён на стандартный Yii2 web root `web/`; dev-сервер использует `web/router.php`, чтобы Yii2 корректно обрабатывал `/.well-known/*` маршруты.
- 2026-09-13: документация закрепляет Docker-контейнер `app` как основной способ запуска и проверки проекта без локального PHP/Composer.
- 2026-09-16: добавлена PHPUnit-инфраструктура `make unit` для unit-проверок password policy и trusted-proxy IP resolution.
- 2026-09-16: добавлен `make phpunit-integration` с PostgreSQL fixture-проверками `OAuthService` для OAuth clients, array-полей и audit side effects.
- 2026-09-16: PostgreSQL PHPUnit-набор расширен проверкой `AuthService` для local login, brute-force reset, session lookup, Bearer JWT `currentUser` и logout revoke.
- 2026-09-16: PostgreSQL PHPUnit-набор расширен проверкой `OAuthService` для Authorization Code + PKCE token exchange, refresh token issuance и запрета повторного использования authorization code.
- 2026-09-16: PostgreSQL PHPUnit-набор расширен проверкой refresh token rotation, reuse detection с отзывом family и revoke refresh token.
- 2026-09-16: PostgreSQL PHPUnit-набор расширен проверкой OIDC ID token hint, JWKS, UserInfo scope filtering и RP-initiated logout с redirect validation и отзывом refresh tokens.
- 2026-09-16: PostgreSQL PHPUnit-набор расширен проверкой `AccountService`: password reset token consumption, отзыв sessions/refresh/JWT, email verification pending accounts и profile attributes validation.
- 2026-09-16: PostgreSQL PHPUnit-набор расширен проверками `MfaService`, `RbacService`, identity providers и LDAP providers/group mappings; оставшиеся сетевые LDAP/OIDC callback сценарии остаются в smoke/manual проверках.
- 2026-09-16: добавлен `make integration-broker` с локальным mock OIDC provider; исправлен broker callback route, чтобы path-параметр provider не конфликтовал с OAuth query `code`.
- 2026-09-16: добавлен optional `make integration-ldap`, который проверяет LDAP service bind, search + user bind, provisioning, group mapping и audit при заданных `SSO_TEST_LDAP_*`.
- 2026-09-13: MFA API выровнен с Go-контрактом: добавлены `/api/v1/auth/mfa/enroll`, `/activate`, ответ `mfa_token`, сохранение enrollment secret до активации и smoke-проверка этих маршрутов.
- 2026-09-13: перенесён Go-compatible `/metrics` endpoint с Prometheus text метриками HTTP-запросов, inflight gauge и суммарной длительностью.
- 2026-09-13: Users API выровнен с Go-контрактом для создания пользователей: пустые username/email возвращают 400 `username and email are required`, конфликт username возвращает 409 `user already exists`.
- 2026-09-13: RBAC API выровнен с Go-контрактом для создания ролей и групп: пустые code/name возвращают 400 с точным сообщением, конфликт code возвращает 409 `role already exists` или `group already exists`.
- 2026-09-13: Audit API выровнен с Go-контрактом фильтров: невалидный `actor`, `from` и `to` возвращают точные 400-ошибки до выполнения запроса.
- 2026-09-13: Identity providers API выровнен с Go-контрактом создания: обязательные `code`/`client_id`, точная ошибка OIDC URL и 409 `provider already exists` для конфликта code.
- 2026-09-07: добавлен `.dockerignore`, а Dockerfile использует `composer.lock` для воспроизводимой установки Yii2.
- 2026-09-07: перенесены brute-force lockout и rate limiting из Go-сервиса; добавлено ограничение тела запроса 1 MiB.
- 2026-09-07: добавлен явный Yii2 `controllerMap` для сохранения маршрутов `oauth/*`.
- 2026-09-07: перенесены RBAC-проверка, Users/Roles/Permissions/Groups/Audit admin API и базовые Vue-вкладки для этих разделов.
- 2026-09-07: перенесены LDAP providers CRUD, LDAP test bind, LDAP group mappings и шифрование bind password.
- 2026-09-10: добавлены UI-формы RBAC-назначений для пользователей, ролей и групп.
- 2026-09-10: добавлен LDAP Search + Bind fallback для login и синхронизация LDAP group mappings.
- 2026-09-10: добавлены MFA TOTP, login challenge и одноразовые recovery codes.
- 2026-09-12: перенесены password forgot/reset/change и email verification; reset отзывает сессии, refresh tokens и ранее выданные first-party Bearer JWT, reset/verification письма отправляются через SMTP или пишутся в лог.
- 2026-09-12: перенесён identity brokering для Google/GitHub/OIDC: CRUD identity providers, signed state, callback, безопасная линковка по verified email, JIT-provisioning federated users и кнопки external sign-in в Vue UI.
- 2026-09-12: перенесён production hardening для trusted proxy IP, cleanup expired records и shared rate limit через `SSO_CACHE_DRIVER=db`.
- 2026-09-12: добавлен self-service account profile update для `first_name`, `last_name` и `attributes` через API и Vue UI.
- 2026-09-12: hardened refresh token rotation: family сохраняется при rotation, reuse/проигранная гонка отзывает всю refresh family.
- 2026-09-12: добавлен `make integration` с HTTP smoke flow для bootstrap-ready OAuth/OIDC, PKCE, UserInfo scope filtering, RP logout validation, refresh rotation/reuse detection, RBAC enforcement и базовых OAuth error contracts.
- 2026-09-12: интеграционный OAuth/OIDC smoke flow расширен проверками Discovery/JWKS, RS256 `id_token` claims, одноразовости authorization code, introspection active/inactive token, revocation refresh token, `client_secret_basic`, client_credentials token contract, consent-required/grant сценария и OAuth error contracts для authorize/revoke/introspect.
- 2026-09-12: подготовлены `MIGRATION_NOTES.md` для cutover с Go-сервиса на PHP/Yii2: совместимость контрактов, миграции БД, checklist переключения и rollback.
- 2026-09-12: добавлен `make integration-client` с dependency-free внешним OAuth/OIDC RP client flow через Discovery, Authorization Code + PKCE, `client_secret_basic`, UserInfo, refresh token и RP logout.
- 2026-09-12: public OAuth clients теперь требуют PKCE S256 на authorize.
- 2026-09-12: OIDC discovery дополнен revocation/introspection metadata, UserInfo фильтрует claims по OAuth scope.
- 2026-09-12: RP-initiated logout валидирует `id_token_hint` и registered `post_logout_redirect_uri`, отзывает SSO-сессию и refresh tokens пользователя.
