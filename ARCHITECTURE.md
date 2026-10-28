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
- MFA: TOTP enrollment/verification, replay counter, одноразовые recovery codes и отзыв credentials при смене MFA-состояния.
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

Federation broker поддерживает единственный тип внешнего provider — `oidc`; административный контракт требует явные `authorize_url`, `token_url` и `userinfo_url` и не подставляет provider-specific presets. `IdentityProviderPolicy` централизованно проверяет все три endpoint перед сохранением и непосредственно перед broker flow, используя общую outbound HTTP/SSRF-защиту. Проверка зарегистрированных OAuth redirect URI и разрешённой для public clients вариации loopback-порта сосредоточена в `OAuthRedirectPolicy` и одинаково применяется при администрировании клиента и authorization flow.

## Данные, секреты и транзакции

PostgreSQL хранит пользователей, сессии, auth artifacts, OAuth clients, signing keys, RBAC, LDAP/provider configuration, audit, rate-limit buckets и metric counters.

Email нормализуется как `trim + lowercase` и уникален без учёта регистра. Его смена атомарно сбрасывает `email_verified`, удаляет старые verification tokens и отзывает sessions, refresh tokens и user JWT. Блокировка и soft delete пользователя также отзывают credentials.

Источник пользователя является DB-инвариантом. `local` требует непустой password hash и не содержит LDAP-реквизитов; `ldap` не содержит локального password hash и требует одновременно provider и непустой DN; `federated` не содержит password/LDAP-реквизитов и к концу транзакции имеет хотя бы один federated identity link. Отложенный constraint trigger разрешает только транзакционный JIT-переход `user -> link`, но не позволяет зафиксировать осиротевшую federated-учётную запись. Дополнительные federated links у local/LDAP пользователя допустимы как отдельные способы входа.

Confidential OAuth client всегда имеет непустой secret hash, public client не имеет secret. Все OIDC signing keys имеют алгоритм `RS256`; active key не имеет `expires_at`, retiring/retired key имеет срок окончания, а partial unique index допускает не более одного active key. Отсутствие active key диагностируется deployment check и устраняется атомарным repair use case, который также нормализует старый дрейф перед установкой ограничений.

Сырые session, authorization, refresh и one-time tokens не хранятся: сохраняются SHA-256 hashes. Recovery codes имеют 128 бит энтропии, показываются только при создании и хранятся как Argon2id hashes; миграция на этот формат удаляет прежние 40-битные SHA-256 codes, поэтому пользователю с включённым MFA требуется выпустить новый набор. Пароли и confidential client secrets также хэшируются Argon2id. LDAP passwords, MFA secrets, provider secrets и OIDC private keys шифруются AES-256-GCM ключом `SSO_ENCRYPTION_KEY`. Signing, JWT и encryption keys имеют разные назначения и не переиспользуются.

Создание security-артефакта и обязательного audit event выполняется в одной PostgreSQL-транзакции. Ошибка аудита отменяет use case. Одноразовые артефакты и refresh rotation потребляются условным UPDATE или под row lock, поэтому конкурентно успешным может быть только один запрос. Login lockout обновляется атомарным UPSERT.

## HTTP/API-контракт

Если маршрут не определяет redirect, HTML или OAuth form response, API отвечает JSON. Тело JSON содержит ровно одно значение; хвостовые данные, несколько значений, неизвестные поля, неверный регистр полей и невалидные идентификаторы отклоняются до обращения к бизнес-логике. UUID валидируется до запроса к БД, timestamps возвращаются в RFC 3339 UTC. Неизвестные маршруты API, OAuth и Well-Known возвращают JSON 404.

Ошибки прикладного API имеют единый JSON envelope `{"error":"читаемое сообщение","code":"machine_code","details":...}`. Поля `error` и `code` обязательны, `details` присутствует при наличии структурированного контекста. Validation errors возвращают HTTP 400, отсутствующие ресурсы — 404, конфликты уникальности или состояния — 409. OAuth/OIDC endpoints используют нормативные `error` и `error_description`. Auth/token responses содержат `Cache-Control: no-store`. `X-Request-ID` принимается только в допустимом формате либо генерируется сервисом и возвращается клиенту.

Cookie-authenticated изменяющие запросы требуют CSRF token. После загрузки admin UI действующая browser-сессия обменивается на хранимый только в памяти access token через `POST /api/v1/auth/session-token`; запрос передаёт значение cookie `sso_csrf` в `X-CSRF-Token`, а отсутствие или несовпадение токена отклоняется. Admin API требует Bearer token и нужный scope. User principal дополнительно проходит RBAC-проверку. Machine principal из `client_credentials` допускается только к явному allowlist операций чтения, LDAP test и ротации signing key; прочие изменения требуют user principal. Bearer-аутентификация не зависит от CSRF. Logout с cookie инвалидирует серверную сессию; RP-initiated и back-channel logout дополнительно валидируют client, redirect и token hints.

Все HS256 access tokens имеют единый обязательный профиль: `iss` равен настроенному issuer, `aud` — API audience, а `sub`, `jti`, целочисленные `iat`, `nbf`, `exp` и явный `purpose` обязательны. Разрешены только три назначения: `first_party_access` без `client_id`, пользовательский `oauth_access` с действующим `client_id` и машинный `client_credentials` с действующим confidential client. Токены с другим issuer/audience/purpose, будущими `iat`/`nbf` за пределами 60-секундного clock skew, истёкшим или чрезмерным сроком, отключённым user/client либо отозванным `jti` отклоняются. `/oauth2/userinfo` принимает только `oauth_access`; first-party browser/admin token и machine token для него непригодны.

Парольный шаг входа при включённом MFA возвращает только `{"mfa_required":true,"mfa_token":"..."}` без фиктивного пользователя, access/session timestamps или иных account-данных. Enrollment, enable, disable и перевыпуск recovery codes принимают только first-party access token с `auth_time` не старше пяти минут; OAuth resource token для этих операций не подходит. Обмен browser session на access token сохраняет исходное время аутентификации и не продлевает это окно. Успешные enable и disable атомарно отзывают все sessions и refresh tokens пользователя, инвалидируют ранее выпущенные access tokens и требуют нового входа. TOTP counter и recovery code потребляются атомарно, поэтому replay и конкурентный повтор не могут завершить второй challenge.

Семейства публичного контракта:

- `/health/*`, `/metrics`, `/api/v1/bootstrap*` и сведения о доступных провайдерах;
- `/api/v1/auth/*`, `/api/v1/account/*`, MFA, consent и управление сессиями;
- `/oauth2/*` и `/.well-known/*` для OAuth 2.0/OpenID Connect;
- `/api/v1/users`, `/roles`, `/permissions`, `/groups`, `/oauth/clients`, `/identity-providers`, `/ldap/providers`, `/audit` и broker endpoints для администрирования.
- `/api/v1/operations/*` для безопасного operational summary и управления жизненным циклом OIDC signing keys; private/public PEM через administrative API не выдаются.

Машиночитаемый HTTP-контракт разделён на `contracts/openapi-admin.json` для administrative/account API и `contracts/openapi-oauth.json` для OAuth 2.0, OpenID Connect и federation broker endpoints. Оба документа используют OpenAPI 3.1 и JSON Schema 2020-12. `composer verify:contracts` строит множество операций из спецификаций, сверяет его со всеми маршрутами `/api/v1`, `/oauth2` и `/.well-known`, проверяет уникальность `operationId`, path parameters, status codes, response content schemas, security requirements и локальные `$ref`; для каждой permission-protected административной операции он дополнительно требует ответы `401` и `403`, а для каждого маршрута из `rateLimitedRoutes` — `429`. Все administrative/account request bodies, resources, arrays и pages имеют отдельные строгие schemas; nullable object/array сохраняют проверку вложенных полей и запрет незаявленных свойств. Одноразовые OAuth client secrets описаны только в create/rotate responses, а LDAP bind password, IdP client secret и OAuth client secret hash исключены из resource schemas. Consent endpoints требуют browser session cookie, имеют строгие schemas prompt/grant и отдельно описывают OAuth errors и CSRF error. Deployment integration smoke валидирует по тем же схемам реальные request fixtures и HTTP-ответы публичного bootstrap/provider API, login/MFA, `/auth/me`, token, UserInfo, Discovery и JWKS, а основной OAuth/OIDC integration-flow — login, account lifecycle, consent, operations, OAuth client administration, users/RBAC lifecycle и audit, включая административные списки, CRUD, назначения, ошибки, ротацию секретов и ключей и отзыв токенов; `additionalProperties: false` в этих схемах не допускает незаявленные, в том числе секретные, поля. Любое изменение внешнего поведения должно атомарно обновлять router, соответствующую спецификацию и contract/integration tests; предполагаемое поведение исторической реализации контрактом не является.

Административные списки принимают `limit` (1–100, по умолчанию 50), `offset` (неотрицательный) и текстовый фильтр `q`. Список пользователей дополнительно принимает `status` и `source`, OAuth clients и identity providers — `type`, LDAP providers — `enabled`. Некорректные границы заменяются безопасными значениями по умолчанию. Ответ имеет envelope `{"items": [...], "pagination": {"limit": 50, "offset": 0, "count": N}}`; `count` описывает число элементов в текущей странице. Пользовательские account-списки остаются JSON-массивами.

LDAP group mapping является вложенным ресурсом provider: list/create возвращают `404`, если parent отсутствует, а delete сопоставляет одновременно `provider_id` и `mappingID`, поэтому mapping нельзя удалить через URI другого provider. Проверка parent, mutation и обязательный audit выполняются в одной транзакции. `PUT /api/v1/ldap/providers/{id}` имеет partial-update семантику: изменяются только присутствующие поля, пропущенные настройки и пустой `bind_password` сохраняют прежние значения.

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

Cleanup запускается scheduler-контейнером, работает батчами под advisory lock и публикует время последнего успеха, длительность и число удалённых строк. Readiness проверяет PostgreSQL, локальный encryption round trip и OIDC signing round trip без внешних вызовов. Operational status повторяет signing round trip с расшифровкой active private key и пробной подписью, поэтому повреждённый key material не считается готовым. Cleanup считается просроченным, если последний успех отсутствует, находится в недопустимом будущем или старше двух настроенных интервалов `SSO_CLEANUP_INTERVAL`. Baseline-миграция намеренно не использует `IF NOT EXISTS` для таблиц и индексов, поэтому частичная схема не принимается за новую установку. `deployment-check` дополнительно сверяет обязательные таблицы, столбцы, constraints, triggers и индексы, проверяет фактические security-model invariants и должен завершиться до запуска новой версии приложения.

Admin UI показывает агрегированный operational status: каждый из компонентов `database`, `signing_keys` и `cleanup` имеет безопасный `ready/degraded`, а cleanup дополнительно сообщает возраст последнего успеха, настроенный интервал и предельный возраст. Общий статус равен `ready` только при готовности всех трёх компонентов. Любой сбой DB-запроса, включая запросы после первоначального probe, перехватывается и возвращает структурированный `degraded` без текста исключения. Детали соединения, конфигурационные secrets и key material в ответ не включаются.

Maintenance mode блокирует весь HTTP кроме liveness на время backup/schema operations. Restore rehearsal разворачивает dump во временную БД и проверяет критическую структуру и данные. Пошаговые deployment, backup, restore, monitoring и rotation процедуры находятся в `README.md`.

## Цепочка поставки и web assets

Composer dependencies фиксируются lock-файлом и проходят audit. Production image сопровождается SPDX SBOM и vulnerability scan. Vendored browser assets не загружаются с CDN во время работы приложения и проверяются по зафиксированной SHA-256 сумме.

Vue хранится в `web/js/vue.global.prod.js`; текущий источник — production build Vue 3.5.43 из `https://unpkg.com/vue@3.5.43/dist/vue.global.prod.js`. При обновлении выбирается стабильная версия, копируется точный distribution artifact, обновляется `.sha256`, после чего выполняются `make verify-assets`, unit tests и UI smoke test.

## Роли документов

- `README.md` — запуск, конфигурация, проверки и эксплуатационные процедуры.
- `ARCHITECTURE.md` — устойчивые границы, security model и нормативный HTTP/API-контракт.
- `PLAN.md` — только незавершённый или явно отложенный backlog.
- `AGENTS.md` — правила изменения и проверки репозитория.
