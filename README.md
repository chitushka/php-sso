# SSO PHP

Самостоятельный single-tenant SSO/Identity Provider на PHP 8.4, Yii2, PostgreSQL 17, nginx и Vue 3. Сервис поддерживает локальную, LDAP- и федеративную аутентификацию, OAuth 2.0/OpenID Connect, MFA, сессии, RBAC, аудит и административный интерфейс.

Активный backlog находится только в [PLAN.md](PLAN.md). Требования определяются документацией проекта и применимыми стандартами, без сравнения с реализациями на других языках.

## Возможности

- Bootstrap первого администратора и локальная аутентификация.
- HttpOnly SSO-сессии, Bearer JWT, просмотр и отзыв сессий.
- CSRF-защита cookie-authenticated операций, строгие security headers и no-store для auth/token responses.
- TOTP MFA, одноразовые recovery codes, password reset и email verification.
- Атомарная выдача и отзыв auth-артефактов вместе с обязательным audit; конкурентно безопасные lockout, одноразовые tokens и refresh rotation.
- LDAP Search + Bind, JIT provisioning и LDAP group mappings.
- Федеративный вход через настраиваемые OIDC/OAuth identity providers.
- OAuth 2.0 Authorization Code с обязательным PKCE S256/state, consent management, refresh rotation/reuse detection, client credentials, revoke и introspect.
- OIDC Discovery, JWKS, RS256 ID Token, UserInfo и RP/back-channel logout.
- Users, roles, permissions, groups, OAuth clients, providers и audit API.
- Нормализованные уникальные email, повторное подтверждение после смены адреса и защита последнего активного администратора.
- Vue admin UI, Prometheus metrics, health probes, cleanup и ротация зашифрованных секретов.

## Быстрый старт

Все команды выполняются в Docker. Локальные PHP и Composer не требуются.

```bash
cp .env.development.example .env.development
make build
make up-development
```

Приложение доступно на `http://localhost:8080`, локальный HTTPS proxy — на `https://localhost:8443`, PostgreSQL публикуется на `localhost:5433`.

`.env.development.example` предназначен только для локальной разработки. `.env.example`
задаёт fail-closed production-профиль: до запуска обязательны уникальные secrets,
нестандартный пароль БД, HTTPS issuer и PostgreSQL `sslmode=verify-full` с доверенным CA.
Production secrets рекомендуется передавать через `*_FILE` и read-only secret mounts.

## Проверки

```bash
make build
make build-test
make quality
make migration-check
make unit
make phpunit-integration
make coverage
make integration
make integration-client
make integration-broker
make integration-ldap
make audit
```

Эквивалентные базовые команды без `make`:

```bash
docker compose --profile test run --rm --no-deps test composer lint
docker compose --profile test run --rm --no-deps test composer test:unit
docker compose --profile test run --rm test sh -lc "php yii migrate --interactive=0 && composer test:integration"
```

Test service всегда задаёт отдельный `SSO_ENV=test` и не зависит от локального `.env`.
Production-проверки намеренно запрещают невалидные TTL/лимиты/URL, `sslmode` кроме
`verify-full`, отсутствующий CA, placeholder secrets и стандартные credentials.

## Основная конфигурация

- `SSO_ENV` — `prod` включает fail-closed validation.
- `SSO_DATABASE_URL` — PostgreSQL DSN; production требует `sslmode=verify-full` и читаемый `sslrootcert`.
- `SSO_ISSUER`, `SSO_API_AUDIENCE` — публичные OAuth/OIDC идентификаторы.
- `SSO_JWT_SECRET`, `SSO_ENCRYPTION_KEY` — независимые secrets длиной не менее 32 символов.
- `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL` — TTL в секундах.
- `SSO_COOKIE_SECURE`, `SSO_MAX_BODY_BYTES` — browser/request security.
- `SSO_RATE_LIMIT_PER_MINUTE`, `SSO_RATE_LIMIT_BURST` — лимиты чувствительных endpoints.
- `SSO_TRUSTED_PROXIES` — доверенные IP/CIDR reverse proxies.
- `SSO_OUTBOUND_HOST_ALLOWLIST`, `SSO_OUTBOUND_CONNECT_TIMEOUT`, `SSO_OUTBOUND_READ_TIMEOUT`, `SSO_OUTBOUND_MAX_RESPONSE_BYTES` — outbound HTTP policy и resource limits.
- `SSO_LDAP_ALLOWED_HOSTS`, `SSO_LDAP_CA_CERT`, `SSO_LDAP_ALLOW_INSECURE`, `SSO_LDAP_NETWORK_TIMEOUT` — LDAP transport policy.
- `SSO_SMTP_*` — SMTP transport, credentials и CA.
- `SSO_AUDIT_RETENTION_DAYS`, `SSO_CLEANUP_BATCH_SIZE` — retention и cleanup.
- `SSO_CLEANUP_INTERVAL` — интервал scheduler в секундах.
- `SSO_METRICS_ALLOWED_CIDRS`, `SSO_METRICS_BEARER_TOKEN` — защита `/metrics`.

Полный шаблон находится в `.env.example`. Не коммитьте `.env`, ключи, сертификаты и client secrets.

## Эксплуатация

```bash
make cleanup
make cutover-check
make cutover-rotate-key-dry-run
make cutover-rotate-key
make cutover-rehearse-rollback
make cutover-smoke
make cutover-smoke-authenticated
```

Имена части maintenance-команд пока содержат исторический префикс `cutover`; их нейтральное переименование без потери функций включено в план. Актуальный порядок deployment, backup, maintenance и restore описан в [MIGRATION_NOTES.md](MIGRATION_NOTES.md).
SLO, alerts, cleanup scheduler, backup retention, restore drill и ротация ключей описаны
в [OPERATIONS.md](OPERATIONS.md).

## Документация

- [PLAN.md](PLAN.md) — единый список незавершённых работ.
- [ARCHITECTURE.md](ARCHITECTURE.md) — границы системы и ключевые решения.
- [ENDPOINT_CONTRACTS.md](ENDPOINT_CONTRACTS.md) — собственный HTTP/API контракт.
- [MIGRATION_NOTES.md](MIGRATION_NOTES.md) — deployment, schema upgrade, backup и restore.
- [SECURITY.md](SECURITY.md) — модель угроз и browser/transport/OAuth security profile.
- [OPERATIONS.md](OPERATIONS.md) — SLO, мониторинг, backup/restore и ротация секретов.
- [VENDORED_ASSETS.md](VENDORED_ASSETS.md) — происхождение и обновление сторонних web-ресурсов.

## Текущее качество

CI воспроизводимо собирает production/test images, проверяет миграции fresh/upgrade/rollback,
lint, PSR-12, PHPStan, unit/integration/coverage и protocol smoke flows. Также обязательны
Composer audit, secret/document/asset checks, SPDX SBOM и vulnerability scan. Актуальные
подтверждённые результаты локального прогона фиксируются в `PLAN.md`.
