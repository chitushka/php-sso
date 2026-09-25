# Migration notes: Go SSO -> PHP/Yii2

Этот документ описывает практический переход с рабочего Go-сервиса `D:\workspace\GolandProjects\sso` на PHP 7.4/Yii2 порт из этого репозитория.

## Область совместимости

PHP-порт сохраняет single-tenant модель, PostgreSQL-схему, namespace переменных `SSO_*`, cookie `sso_session`, Bearer JWT для API, публичные OAuth2/OIDC маршруты и основные admin API.

Совместимые публичные контракты:

- `GET /health/live`, `/health/ready`, `/health/version`.
- `GET /api/v1/bootstrap/status`, `POST /api/v1/bootstrap`.
- `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`.
- Login response использует Go `LoginResult`, а raw `session_token` совпадает с cookie `sso_session`; Yii signing envelope отключён, поэтому cookie можно передавать между Go/PHP-инстансами.
- Вложенный `user` и `GET /api/v1/auth/sessions` используют Go JSON wire-format для RFC3339 timestamps, пустых map и nullable `omitempty` полей.
- `GET /.well-known/openid-configuration`, `GET /.well-known/jwks.json`.
- `GET /oauth2/authorize`, `POST /oauth2/token`, `POST /oauth2/revoke`, `POST /oauth2/introspect`.
- `GET /oauth2/userinfo`, `GET|POST /oauth2/logout`, `GET|POST /oauth2/consent`.
- Admin API для users, roles, permissions, groups, audit, OAuth clients, LDAP providers и identity providers.
- Roles, Permissions и Groups API используют Go JSON wire-format дат и описаний; update ролей/групп сохраняет Go full-replacement и `updated_at` lifecycle.
- Audit events используют Go JSON wire-format для `created_at`, nullable actor и строковых network metadata.
- OAuth Clients API сохраняет Go lifecycle `updated_at` при изменении клиента и ротации секрета.
- Identity Providers API сохраняет Go lifecycle `updated_at`, RFC3339 wire-format дат и zero-value/full-replacement семантику POST/PUT; пустой PUT `client_secret` не стирает сохранённый секрет.
- Identity broker сохраняет custom query/fragment authorize URL без дублирования OAuth-параметров и использует Go-compatible `/login` callback error redirects.
- LDAP Providers и group mappings сохраняют Go lifecycle временных полей и RFC3339 wire-format дат; POST/PUT провайдеров используют Go zero-value/full-replacement семантику, сохраняя текущий bind password при пустом PUT-секрете.
- LDAP create/update не раскрывают ошибки PostgreSQL и возвращают стабильные Go 500 errors; duplicate group mapping возвращает 409 `mapping already exists`.
- LDAP provider DELETE можно безопасно повторять; mapping list order и audit actor/target fields совпадают с Go-сервисом.
- LDAP bind passwords, MFA secrets и identity provider client secrets используют общий с Go storage wire-format `enc:v1:<base64.RawStdEncoding(nonce || ciphertext || tag)>`. PHP читает существующие Go-записи и ранние PHP `v1:` записи, а новые ciphertext доступны Go-сервису при rolling migration или rollback.

Проверено текущим smoke flow:

- Authorization Code + PKCE.
- `client_secret_post` и `client_secret_basic`.
- refresh token rotation и reuse detection.
- revoke/introspect.
- OIDC Discovery/JWKS, RS256 `id_token`, UserInfo scope filtering.
- RP-initiated logout.
- consent-required/grant flow.
- базовые OAuth error contracts.
- RBAC enforcement для admin API.
- внешний RP client flow через `make integration-client`.

## Подготовка

До maintenance window запустить read-only preflight с issuer действующего Go-сервиса:

```bash
SSO_CUTOVER_EXPECTED_ISSUER="https://sso.example.com" docker compose run --rm app php yii cutover/check
```

Команда не изменяет БД и возвращает ненулевой exit code при блокерах. Она проверяет обязательные secrets и TTL, точное совпадение issuer, HTTPS cookie, соединение и критическую PostgreSQL-схему, `pgcrypto`, seed roles/permissions, а также расшифровку всех LDAP/MFA/broker secrets. Значения secrets в отчёт не выводятся. `WARN` по file cache допустим только для одного PHP-инстанса; пустой trusted-proxy список допустим только без reverse proxy.

Ранний PHP ciphertext с префиксом `v1:` читается PHP-портом, но не Go-сервисом, поэтому preflight считает его rollback-блокером. После резервной копии запустить сначала dry-run, затем apply с тем же `SSO_ENCRYPTION_KEY`, которым зашифрованы текущие записи:

```bash
docker compose run --rm app php yii cutover/rewrap-secrets
docker compose run --rm app php yii cutover/rewrap-secrets --apply=1
docker compose run --rm app php yii cutover/check
```

Apply отказывается работать с коротким или placeholder key. LDAP, MFA и identity provider secrets блокируются и обновляются одной транзакцией; при ошибке расшифровки, конкурентном изменении или неудачном round-trip откатывается весь набор. Plaintext и ciphertext в вывод не попадают. Повторный запуск идемпотентен и должен сообщить `0 secrets rewrapped`.

1. Зафиксировать версию Go-сервиса, от которой выполняется переход, и сохранить дамп PostgreSQL:

   ```bash
   pg_dump "$SSO_DATABASE_URL" > sso-before-php-cutover.sql
   ```

2. Сравнить production `.env` с `.env.example` PHP-порта. Обязательные значения:

   - `SSO_DATABASE_URL`
   - `SSO_ISSUER`
   - `SSO_JWT_SECRET`
   - `SSO_ENCRYPTION_KEY`
   - `SSO_ACCESS_TOKEN_TTL`
   - `SSO_SESSION_TTL`
   - `SSO_REFRESH_TOKEN_TTL`
   - `SSO_TRUSTED_PROXIES`
   - `SSO_CACHE_DRIVER`
   - SMTP-переменные, если включены password reset/email verification письма

3. Проверить, что `SSO_ISSUER` остаётся тем же публичным URL. Изменение issuer ломает доверие OIDC-клиентов к уже настроенному провайдеру.

   `SSO_JWT_SECRET` и `SSO_ENCRYPTION_KEY` должны в точности совпадать с Go-инстансами. Замена encryption key сделает существующие LDAP/MFA/broker secrets нерасшифровываемыми; PHP намеренно завершает такую операцию ошибкой, а не подменяет секрет пустой строкой.

4. Для production с несколькими app-инстансами включить `SSO_CACHE_DRIVER=db`, чтобы rate limit использовал PostgreSQL-backed Yii cache.

5. Убедиться, что reverse proxy IP/CIDR перечислены в `SSO_TRUSTED_PROXIES`; иначе `X-Forwarded-For` будет игнорироваться намеренно.

## Миграции БД

PHP-порт содержит стартовую Yii2-миграцию, соответствующую Go-миграциям `000001-000010`, и отдельную миграцию таблицы `cache` для DB-backed rate limit.

Для перехода на существующей базе:

```bash
docker compose run --rm app php yii migrate --interactive=0
```

Если база уже создана Go-сервисом и схема актуальна, Yii2 может потребовать отметить стартовую миграцию как применённую вручную. Делать это нужно только после проверки структуры таблиц и индексов:

```bash
docker compose run --rm app php yii migrate/mark m260907_000001_init_sso_schema --interactive=0
docker compose run --rm app php yii migrate --interactive=0
```

## Cutover

1. Остановить запись в Go-сервис или перевести его в maintenance window.
2. Сделать финальный `pg_dump`.
3. Запустить PHP-миграции против production PostgreSQL.
4. Поднять PHP-приложение с тем же `SSO_ISSUER`.
5. Проверить health endpoints:

   ```bash
   curl -fsS "$SSO_ISSUER/health/live"
   curl -fsS "$SSO_ISSUER/health/ready"
   curl -fsS "$SSO_ISSUER/.well-known/openid-configuration"
   ```

6. Переключить reverse proxy на PHP app.
7. Выполнить smoke-проверки:

   - login/logout локального администратора;
   - `GET /api/v1/auth/me`;
   - Authorization Code + PKCE из реального клиента;
   - refresh token;
   - `/oauth2/userinfo`;
   - `/oauth2/logout`;
   - вход через LDAP или identity provider, если они используются.

## Rollback

Rollback должен возвращать трафик на Go-сервис и базу из дампа, сделанного перед cutover.

Если PHP-приложение уже принимало production-записи, простой возврат трафика без восстановления дампа рискован: новые сессии, refresh tokens, audit logs, one-time tokens и изменения пользователей могли появиться только после переключения.

Безопасный rollback:

1. Остановить PHP-приложение.
2. Восстановить `sso-before-php-cutover.sql` в PostgreSQL.
3. Вернуть reverse proxy на Go-сервис.
4. Проверить `/health/ready`, login и один OAuth flow.

## Известные отличия

- Go-сервис поддерживал `SSO_MIGRATE_ON_START`; PHP-порт применяет миграции явной командой `php yii migrate --interactive=0`.
- Go-конфигурация использовала duration-строки вроде `15m`/`720h`; PHP-порт ожидает TTL в секундах.
- Prometheus `/metrics` перенесён; имена route labels совпадают с chi-шаблонами Go.
- UI в PHP-порте встроен в Yii2 layout и не требует отдельной Vite-сборки.
- Встроенный `make integration` использует высокий test-local rate limit только для расширенного smoke flow; production rate limit задаётся обычными `SSO_RATE_LIMIT_PER_MINUTE` и `SSO_RATE_LIMIT_BURST`.

## Перед production

- Заменить placeholder secrets.
- Проверить, что `.env` не попадает в Git.
- Указать `SSO_CUTOVER_EXPECTED_ISSUER` и добиться завершения `make cutover-check` без FAIL.
- Прогнать `composer lint`.
- Прогнать `make integration` в окружении с Docker и `make`.
- Прогнать `make integration-client` в окружении с Docker и `make`, затем проверить внешний OAuth/OIDC flow настоящим production-клиентом.
- Настроить регулярный `php yii cleanup/run`.
- Проверить SMTP-доставку reset/verification писем.
