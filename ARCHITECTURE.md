# Архитектура проекта

## Назначение и границы

SSO PHP — самостоятельный single-tenant Identity Provider. Система отвечает за аутентификацию пользователей, SSO-сессии, OAuth 2.0/OpenID Connect, MFA, LDAP и внешнюю федерацию, RBAC, аудит и административное управление.

Multi-tenancy/realms, SCIM, SAML и high-assurance hardware authentication не входят в текущий контракт. Их добавление требует отдельного продуктового решения и threat model.

## Технологический стек и размещение кода

- PHP 8.4, PHP-FPM и Yii2.
- PostgreSQL 17 с `pgcrypto`.
- nginx и Docker Compose.
- Self-hosted Vue 3 в Yii layout.

```text
HTTP Controller -> Application Service -> ActiveRecord/PostgreSQL
                                   -> LDAP/SMTP/HTTP provider
```

- `controllers/` — маршрутизация, HTTP validation, authentication/authorization и представление результата.
- `services/` — auth, account, OAuth/OIDC, MFA, RBAC, LDAP, broker, mail, audit и operational use cases.
- `models/` — ActiveRecord-представления таблиц.
- `components/` — request parsing, trusted proxy, RFC3339, maintenance и metric route helpers.
- `migrations/` — схема и seed permissions.
- `views/admin/`, `web/js/`, `web/css/` — Vue admin UI.
- `commands/` — cleanup, readiness и обслуживание секретов.

Production image собирается отдельным multi-stage target без dev dependencies, Composer и тестовых файлов. Runtime работает без root, с read-only root filesystem и выделенными tmpfs. Миграции запускаются отдельной operations-задачей; приложение не применяет их при старте. Production-конфигурация проверяется fail-closed.

## Доменные модули

- Authentication: local password, LDAP fallback, federated login, SSO cookie и Bearer JWT.
- Account: profile, password change/reset, email verification и session management.
- MFA: TOTP enrollment/verification, replay counter и recovery codes.
- OAuth/OIDC Provider: clients, authorization code + PKCE, refresh tokens, client credentials, revoke, introspect, consent, Discovery, JWKS, ID Token, UserInfo и logout.
- Federation Broker: external providers, signed state, callback, identity linking и JIT provisioning.
- Authorization: direct roles, group roles, permissions и scope checks.
- Administration: users, roles, groups, clients, providers, LDAP mappings и audit.
- Operations: health, metrics, cleanup, maintenance, secret re-encryption, backup rehearsal и smoke checks.

Контроллеры получают application services через Yii DI и не содержат бизнес-правил. Крупные домены разделены по use cases: lifecycle пользователей отделён от HTTP, управление OAuth-клиентами и identity providers — от protocol flow, LDAP provider administration — от LDAP authentication, а роли, группы и проверка permissions представлены отдельными границами. Application services не обращаются к глобальному Yii application: БД, настройки и текущий request доступны через заменяемый `ApplicationRuntimeInterface`, а Yii-адаптер находится в инфраструктурном слое `components/`. Время и криптографическая случайность доступны через `ClockInterface` и `RandomGeneratorInterface`.

Auth, OAuth/OIDC, broker, LDAP test и новые изменяющие use cases возвращают типизированный `ServiceResult` с `ServiceError`. Полезная нагрузка хранится в `data`, а транспортные cookies и redirect — отдельно в `metadata`. Контроллер преобразует результат в публичный HTTP/OAuth envelope: прикладной API раскрывает стабильный машинный error code, а OAuth/OIDC endpoints используют нормативный protocol error.

## Доверительные границы

Доверенными считаются собственная PostgreSQL, явно настроенный reverse proxy и явно разрешённые LDAP, SMTP и OIDC endpoints. Ввод браузера и OAuth-клиента, forwarded headers от прочих адресов, DNS-ответы и данные внешних провайдеров недоверенны и валидируются до использования. Компрометация host, root-доступа или самой БД находится за пределами прикладных средств защиты и требует инфраструктурных мер.

Исходящие HTTP-запросы проходят allowlist, DNS/IP validation, запрет private/link-local targets, redirect revalidation, connect/read timeouts и ограничение размера ответа. LDAP требует разрешённый host и TLS с проверяемым CA; небезопасный режим допустим только вне production. SMTP использует проверяемый TLS, если не выбран явно локальный development transport.

## Данные, секреты и транзакции

PostgreSQL хранит пользователей, сессии, auth artifacts, OAuth clients, signing keys, RBAC, LDAP/provider configuration, audit, rate-limit buckets и metric counters.

Email нормализуется как `trim + lowercase` и уникален без учёта регистра. Его смена атомарно сбрасывает `email_verified`, удаляет старые verification tokens и отзывает sessions, refresh tokens и user JWT. Блокировка и soft delete пользователя также отзывают credentials.

Сырые session, authorization, refresh, one-time и recovery tokens не хранятся: сохраняются SHA-256 hashes. Пароли и confidential client secrets хэшируются Argon2id. LDAP passwords, MFA secrets, provider secrets и OIDC private keys шифруются AES-256-GCM ключом `SSO_ENCRYPTION_KEY`. Signing, JWT и encryption keys имеют разные назначения и не переиспользуются.

Создание security-артефакта и обязательного audit event выполняется в одной PostgreSQL-транзакции. Ошибка аудита отменяет use case. Одноразовые артефакты и refresh rotation потребляются условным UPDATE или под row lock, поэтому конкурентно успешным может быть только один запрос. Login lockout обновляется атомарным UPSERT.

## HTTP/API-контракт

Если маршрут не определяет redirect, HTML или OAuth form response, API отвечает JSON. Тело JSON содержит ровно одно значение; хвостовые данные, несколько значений, неизвестные поля, неверный регистр полей и невалидные идентификаторы отклоняются до обращения к бизнес-логике. UUID валидируется до запроса к БД, timestamps возвращаются в RFC 3339 UTC. Неизвестные маршруты API, OAuth и Well-Known возвращают JSON 404.

Ошибки прикладного API имеют единый JSON envelope `{"error":"читаемое сообщение","code":"machine_code","details":...}`. Поля `error` и `code` обязательны, `details` присутствует при наличии структурированного контекста. Validation errors возвращают HTTP 400, отсутствующие ресурсы — 404, конфликты уникальности или состояния — 409. OAuth/OIDC endpoints используют нормативные `error` и `error_description`. Auth/token responses содержат `Cache-Control: no-store`. `X-Request-ID` принимается только в допустимом формате либо генерируется сервисом и возвращается клиенту.

Cookie-authenticated изменяющие запросы требуют CSRF token. После загрузки admin UI действующая browser-сессия обменивается на хранимый только в памяти access token через `POST /api/v1/auth/session-token`; запрос передаёт значение cookie `sso_csrf` в `X-CSRF-Token`, а отсутствие или несовпадение токена отклоняется. Admin API требует Bearer token и нужный scope. User principal дополнительно проходит RBAC-проверку. Machine principal из `client_credentials` допускается только к явному allowlist операций чтения, LDAP test и ротации signing key; прочие изменения требуют user principal. Bearer-аутентификация не зависит от CSRF. Logout с cookie инвалидирует серверную сессию; RP-initiated и back-channel logout дополнительно валидируют client, redirect и token hints.

Семейства публичного контракта:

- `/health/*`, `/metrics`, `/api/v1/bootstrap*` и сведения о доступных провайдерах;
- `/api/v1/auth/*`, `/api/v1/account/*`, MFA, consent и управление сессиями;
- `/oauth2/*` и `/.well-known/*` для OAuth 2.0/OpenID Connect;
- `/api/v1/users`, `/roles`, `/permissions`, `/groups`, `/oauth/clients`, `/identity-providers`, `/ldap/providers`, `/audit` и broker endpoints для администрирования.
- `/api/v1/operations/*` для безопасного operational summary и управления жизненным циклом OIDC signing keys; private/public PEM через administrative API не выдаются.

Машиночитаемый HTTP-контракт разделён на `contracts/openapi-admin.json` для administrative/account API и `contracts/openapi-oauth.json` для OAuth 2.0, OpenID Connect и federation broker endpoints. Оба документа используют OpenAPI 3.1 и JSON Schema 2020-12. `composer verify:contracts` строит множество операций из спецификаций, сверяет его со всеми маршрутами `/api/v1`, `/oauth2` и `/.well-known`, проверяет уникальность `operationId`, path parameters, responses и локальные `$ref`. Любое изменение внешнего поведения должно атомарно обновлять router, соответствующую спецификацию и contract/integration tests; предполагаемое поведение исторической реализации контрактом не является.

Административные списки принимают `limit` (1–100, по умолчанию 50), `offset` (неотрицательный) и текстовый фильтр `q`. Список пользователей дополнительно принимает `status` и `source`, OAuth clients и identity providers — `type`, LDAP providers — `enabled`. Некорректные границы заменяются безопасными значениями по умолчанию. Ответ имеет envelope `{"items": [...], "pagination": {"limit": 50, "offset": 0, "count": N}}`; `count` описывает число элементов в текущей странице. Пользовательские account-списки остаются JSON-массивами.

## Browser и OAuth/OIDC security

Ответы получают CSP, `Permissions-Policy`, `X-Content-Type-Options` и `Referrer-Policy`; HSTS включается в production для прямого HTTPS и HTTPS за доверенным proxy. Cookie имеют `HttpOnly`, `SameSite` и в production `Secure`. Forwarded IP, host, scheme и port принимаются только от CIDR из `SSO_TRUSTED_PROXIES`; те же CIDR настраивают Yii `Request::trustedHosts`, поэтому недоверенные peers не могут подменить origin или признак защищённого соединения.

Базовый production Compose публикует только TLS entrypoint `8443`; cleartext web и PostgreSQL остаются во внутренних Docker networks. Локальные порты `8080` и `5433` добавляются отдельным `docker-compose.development.yml` и не входят в production graph.

Authorization Code требует PKCE S256, state и точного совпадения зарегистрированного redirect URI. Ошибка authorization перенаправляется только на уже проверенный redirect URI и сохраняет допустимые `state` и `iss`; иначе возвращается локально. Confidential client проходит ровно один способ аутентификации — HTTP Basic либо body; public client не использует secret и не получает client credentials grant.

Authorization codes и refresh tokens одноразовые. Повторное использование refresh token отзывает его семейство. ID Token подписывается активным RS256 key с `kid`, issuer/audience/time/nonce проверяются на границах; ID Token из refresh grant не содержит `nonce`. Introspection активного access token возвращает стандартные `exp` и `iat`, но не раскрывает внутренние JWT-only claims. Consent привязан к user, client и scopes; расширение scopes требует нового подтверждения.

Federation broker сохраняет только SHA-256 хеш подписанного `state` и хеш отдельного browser-context token. Контекст передаётся в `HttpOnly`, `SameSite=Lax` cookie с областью `/oauth2/broker`; callback атомарно помечает запись использованной до обмена authorization code. Поэтому `state` нельзя перенести в другой браузер или повторно применить, а два конкурентных callback не могут оба обратиться к провайдеру. Error callback также потребляет `state`; истёкшие записи удаляются общим cleanup.

Пользователь просматривает и отзывает собственные активные consent. Администратор с отдельными permissions может просматривать federated identity links пользователя и отвязывать их; последний способ входа federated-only пользователя удалить нельзя. Принудительная ротация signing key атомарно переводит прежний active key в `retiring`, сохраняет его в JWKS на grace period, создаёт новый encrypted active key и записывает audit event.

## RBAC и аудит

Эффективные permissions складываются из прямых ролей и ролей групп. Bearer API дополнительно ограничен scopes. Bootstrap доступен только пока не существует пользователь с эффективным admin-доступом.

Операции, способные оставить систему без активного администратора, сериализуются PostgreSQL advisory transaction lock и отклоняются с HTTP 409. Permissions встроенной admin-роли нельзя удалить.

Audit хранит тип principal (`user`, `client` или `system`), его идентификатор, action, target и при необходимости IP/User-Agent, но не secrets и токены. Для user principal совместимое поле `audit_logs.actor_user_id` ссылается на пользователя через FK `ON DELETE SET NULL`; OAuth client записывается в `actor_id` без ложной пользовательской ссылки. Soft delete сохраняет actor, физическое удаление пользователя анонимизирует ссылку, а само audit-событие остаётся неизменяемым и доступным до retention cleanup. Изменяющие admin use cases сохраняют mutation и обязательное audit-событие в одной PostgreSQL-транзакции; ошибка аудита откатывает mutation.

## Надёжность и эксплуатация

Rate limits и metrics counters используют атомарные PostgreSQL operations. JSON-логи направляются в stderr и связываются `X-Request-ID`; metric labels имеют ограниченную cardinality, а `/metrics` защищён CIDR allowlist и/или bearer token.

Cleanup запускается scheduler-контейнером, работает батчами под advisory lock и публикует время последнего успеха, длительность и число удалённых строк. Readiness проверяет PostgreSQL, локальный encryption round trip и OIDC signing round trip без внешних вызовов. Baseline-миграция намеренно не использует `IF NOT EXISTS` для таблиц и индексов, поэтому частичная схема не принимается за новую установку. `deployment-check` дополнительно сверяет обязательные таблицы, столбцы, constraints и индексы и должен завершиться до запуска новой версии приложения.

Admin UI показывает агрегированный operational status: доступность БД, состояние signing keys, версию/среду и timestamp последнего успешного cleanup. Детали соединения, конфигурационные secrets и key material в этот ответ не включаются.

Maintenance mode блокирует весь HTTP кроме liveness на время backup/schema operations. Restore rehearsal разворачивает dump во временную БД и проверяет критическую структуру и данные. Пошаговые deployment, backup, restore, monitoring и rotation процедуры находятся в `README.md`.

## Цепочка поставки и web assets

Composer dependencies фиксируются lock-файлом и проходят audit. Production image сопровождается SPDX SBOM и vulnerability scan. Vendored browser assets не загружаются с CDN во время работы приложения и проверяются по зафиксированной SHA-256 сумме.

Vue хранится в `web/js/vue.global.prod.js`; текущий источник — production build Vue 3.5.43 из `https://unpkg.com/vue@3.5.43/dist/vue.global.prod.js`. При обновлении выбирается стабильная версия, копируется точный distribution artifact, обновляется `.sha256`, после чего выполняются `make verify-assets`, unit tests и UI smoke test.

## Роли документов

- `README.md` — запуск, конфигурация, проверки и эксплуатационные процедуры.
- `ARCHITECTURE.md` — устойчивые границы, security model и нормативный HTTP/API-контракт.
- `PLAN.md` — только незавершённый или явно отложенный backlog.
- `AGENTS.md` — правила изменения и проверки репозитория.
