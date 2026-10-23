# SSO PHP

Самостоятельный single-tenant SSO/Identity Provider на PHP 8.4, Yii2, PostgreSQL 17, nginx и Vue 3. Сервис поддерживает локальную, LDAP- и федеративную аутентификацию, OAuth 2.0/OpenID Connect, MFA, управление сессиями, RBAC, аудит и административный интерфейс.

## Возможности

- Локальная, LDAP- и федеративная аутентификация, bootstrap первого администратора.
- HttpOnly SSO-сессии, Bearer JWT, TOTP MFA, recovery codes и управление сессиями.
- OAuth 2.0 Authorization Code с PKCE S256, refresh rotation, client credentials, revoke, introspect и consent.
- OIDC Discovery, JWKS, RS256 ID Token, UserInfo и RP/back-channel logout.
- Управление пользователями, ролями, permissions, группами, OAuth-клиентами и провайдерами; аудит различает user, OAuth client и system principals.
- LDAP group mappings строго привязаны к provider, а обновление LDAP provider сохраняет все пропущенные поля и существующий пароль.
- Machine-токены `client_credentials` ограничены явным allowlist операций чтения, LDAP test и ротации signing key; остальные изменения требуют пользователя.
- Access tokens разделены по назначению (`first_party_access`, `oauth_access`, `client_credentials`) и всегда проверяются по issuer, audience, временным claims, состоянию principal и revocation.
- Vue admin UI, health probes, Prometheus metrics, cleanup и ротация зашифрованных секретов.

## Быстрый старт

Все команды выполняются в Docker; локальные PHP и Composer не требуются.

```bash
cp .env.development.example .env.development
make build
make up-development
```

Приложение доступно на `http://localhost:8080`, локальный HTTPS proxy — на `https://localhost:8443`, PostgreSQL — на `localhost:5433`.

Порты `8080` и `5433` публикует только `docker-compose.development.yml`, который автоматически подключает `make up-development`. Базовый production Compose публикует только TLS entrypoint `8443`; внутренние HTTP и PostgreSQL доступны лишь в Docker networks.

`.env.development.example` предназначен только для разработки. `.env.example` задаёт fail-closed production-конфигурацию: перед запуском необходимы уникальные secrets, нестандартный пароль БД, HTTPS issuer и PostgreSQL `sslmode=verify-full` с доверенным CA. Production secrets следует передавать через `*_FILE` и read-only mounts. Production запускается через `make up` без development override.

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

Базовые команды без `make`:

```bash
docker compose --profile test run --rm --no-deps test composer lint
docker compose --profile test run --rm --no-deps test composer test:unit
docker compose --profile test run --rm test sh -lc "php yii migrate --interactive=0 && composer test:integration"
```

Test service всегда задаёт отдельный `SSO_ENV=test` и не зависит от локального `.env`. Production-проверки намеренно запрещают невалидные TTL, лимиты и URL, слабые secrets, стандартные credentials и PostgreSQL без `sslmode=verify-full` и доверенного CA.

## Конфигурация

Полный шаблон и пояснения находятся в `.env.example`. Основные группы настроек:

- `SSO_ENV`, `SSO_ISSUER`, `SSO_API_AUDIENCE` — среда и публичные OAuth/OIDC identifiers.
- `SSO_DATABASE_URL` — PostgreSQL DSN; production требует `sslmode=verify-full` и читаемый `sslrootcert`.
- `SSO_JWT_SECRET`, `SSO_ENCRYPTION_KEY` — независимые secrets длиной не менее 32 символов.
- `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL` — сроки жизни артефактов.
- `SSO_COOKIE_SECURE`, `SSO_MAX_BODY_BYTES`, `SSO_RATE_LIMIT_*` — browser/request security и rate limits.
- `SSO_TRUSTED_PROXIES` — точный список IP/CIDR reverse proxies, от которых принимаются `X-Forwarded-For`, `Host`, `Proto` и `Port`; заголовки от остальных peers игнорируются.
- `SSO_OUTBOUND_*`, `SSO_LDAP_*`, `SSO_SMTP_*` — политики и ограничения внешних соединений.
- `SSO_AUDIT_RETENTION_DAYS`, `SSO_CLEANUP_*` — retention и cleanup scheduler.
- `SSO_METRICS_ALLOWED_CIDRS`, `SSO_METRICS_BEARER_TOKEN` — защита `/metrics`.

Не коммитьте `.env`, ключи, сертификаты, client secrets и резервные копии.

## Эксплуатация

Основные операции:

```bash
make cleanup
make deployment-check
make db-invariant-check
make encryption-key-rotate-dry-run
make encryption-key-rotate
make backup-restore-test
make smoke
make smoke-authenticated
```

### Развёртывание и изменение схемы

1. Зафиксируйте версию или digest образа, создайте резервную копию и проверьте её через `make backup-restore-test`.
2. Для изменений, несовместимых с параллельной записью, включите maintenance mode и остановите writers.
3. Примените миграции отдельной operations-задачей:

   ```bash
   docker compose --profile operations run --rm migrate
   ```

4. До запуска новой версии выполните `make deployment-check`: проверка fail-closed сверяет обязательные таблицы, столбцы, constraints, triggers и индексы, а также диагностирует нарушения DB-модели пользователей, OAuth clients и signing keys.
5. Запустите приложение, проверьте readiness, `make smoke` и `make smoke-authenticated`, затем выключите maintenance mode.

Перед миграциями нормализации email необходимо заранее устранить адреса, совпадающие после `trim + lowercase`: такие конфликты намеренно не разрешаются автоматически. Откат приложения и обратимые миграции выполняются только после остановки writers; необратимые изменения восстанавливаются из проверенной резервной копии.

Если `deployment-check` сообщает `database.invariants` как repairable drift, сначала сохраните backup и остановите writers. `make db-invariant-check` повторно показывает только безопасные счётчики, а `make db-invariant-repair` атомарно нормализует данные: блокирует учётные записи без пригодного способа входа, отключает confidential clients без известного secret, удаляет неподдерживаемые signing keys и восстанавливает ровно один active RS256 key. Команда не печатает hashes, secrets или key material; после неё обязательны повторные `make deployment-check` и smoke-проверки.

Миграция усиления MFA удаляет старые 40-битные recovery codes: преобразовать быстрый SHA-256 hash в безопасный Argon2id без исходного кода невозможно. После обновления пользователи с включённым MFA должны войти с TOTP и выпустить новый набор recovery codes. Enable и disable MFA отзывают все access/refresh tokens и browser sessions, поэтому после успешной операции требуется повторный вход.

### Резервное копирование и восстановление

- Создавайте ежедневный зашифрованный логический dump и храните его в отдельном fault domain; рекомендуемое хранение — 35 ежедневных и 12 ежемесячных копий.
- Не реже раза в квартал выполняйте restore drill во временную БД и проверяйте критические таблицы, ограничения и возможность запуска приложения.
- При восстановлении включите maintenance mode, остановите writers, восстановите БД, примените только требуемые миграции, выполните smoke-проверки и лишь затем верните трафик.
- Целевые значения: RPO не более 24 часов и RTO не более 4 часов; более строгие требования должны обеспечиваться инфраструктурой и более частыми копиями.

### Наблюдаемость и регламент

Приложение пишет структурированные JSON-логи в stderr и связывает их через `X-Request-ID`. `/metrics` должен быть закрыт CIDR allowlist и/или bearer token. Cleanup удаляет в том числе истёкшие одноразовые состояния federation broker. Настройте оповещения как минимум на недоступность readiness, рост 5xx и ошибок входа, исчерпание rate limit, просроченный cleanup, ошибки отправки почты и приближение срока действия signing keys.

Раздел `Operations` административного UI показывает fail-closed operational summary для БД, signing keys и cleanup. `ready` требует успешной пробной подписи active key и cleanup не старше двух `SSO_CLEANUP_INTERVAL`; компонентные статусы не содержат тексты внутренних ошибок. Кнопка ротации создаёт новый active key, а прежний оставляет в JWKS со статусом `retiring` до завершения grace period. В разделе аккаунта пользователь может отозвать OAuth consent, а в карточке пользователя администратор — проверить и удалить federated identity link, если это не последний доступный способ входа.

Signing/JWT keys и credentials БД, SMTP и LDAP следует ротировать не реже раза в 90 дней; encryption key — не реже раза в год и после инцидента. Перед сменой encryption key обязательны backup и dry run; apply выполняется без параллельных writers, а прежний ключ сохраняется до проверки новой конфигурации и резервной копии.

## Сообщение об уязвимости

Не публикуйте secrets и эксплуатационные детали в открытом issue. Передайте сообщение владельцам проекта по приватному каналу, указав затронутую версию, воспроизводимый сценарий, ожидаемое влияние и безопасный способ проверки.

## Документация

- [ARCHITECTURE.md](ARCHITECTURE.md) — границы системы, security model и нормативный HTTP/API-контракт.
- [Administrative/account OpenAPI](contracts/openapi-admin.json) и [OAuth/OIDC OpenAPI](contracts/openapi-oauth.json) — машиночитаемые OpenAPI 3.1 контракты; каждый administrative/account resource, input, array и page описан строгой отдельной schema, `composer verify:contracts` проверяет синхронность контрактов с router, status codes, response schemas и security schemes, включая обязательные `401/403` для permission-protected административных операций и `429` для всех `rateLimitedRoutes`, а deployment smoke валидирует реальные ключевые request/response payload по этим схемам.
- Administrative list responses содержат `items` и `pagination`, а ошибки `/api/v1` — строковые `error` и стабильный машинный `code`; точные схемы и HTTP statuses определены в OpenAPI.
- [PLAN.md](PLAN.md) — только незавершённые и явно отложенные работы.
- [AGENTS.md](AGENTS.md) — правила разработки и сопровождения репозитория.

## Цепочка поставки

CI собирает production/test images, проверяет миграции fresh/upgrade/rollback, PSR-12, PHPStan, unit/integration/coverage и protocol smoke flows. Также обязательны Composer audit, secret/document/asset checks, SPDX SBOM и vulnerability scan. Production image собирается без dev dependencies и тестовых файлов; для обновления базовых образов используйте pull build и фиксируйте проверяемую версию.
