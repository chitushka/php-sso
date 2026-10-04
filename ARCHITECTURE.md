# Архитектура проекта

## Назначение и границы

SSO PHP — самостоятельный single-tenant Identity Provider. Система отвечает за аутентификацию пользователей, SSO-сессии, OAuth 2.0/OpenID Connect, MFA, LDAP и внешнюю федерацию, RBAC, аудит и административное управление.

Multi-tenancy/realms, SCIM, SAML и high-assurance hardware authentication сейчас не входят в заявленный контракт. Их добавление требует отдельного продуктового решения и threat model.

## Технологический стек

- PHP 8.4 + PHP-FPM и Yii2.
- PostgreSQL 17 и расширение `pgcrypto`.
- nginx и Docker Compose.
- Self-hosted Vue 3 в Yii layout.

Production image собирается отдельным multi-stage target без dev dependencies, Composer и тестовых файлов; test target содержит инструменты качества. Runtime работает без root, с read-only root filesystem и выделенными tmpfs. Миграции выполняются отдельным operations job, приложение не применяет их при старте. Production-конфигурация и secret-file mounts проверяются fail-closed.

## Слои и размещение кода

```text
HTTP Controller -> Application Service -> ActiveRecord/PostgreSQL
                                   -> LDAP/SMTP/HTTP provider
```

- `controllers/` — маршрутизация, HTTP validation, authentication/authorization и представление результата.
- `services/` — auth, account, OAuth/OIDC, MFA, RBAC, LDAP, broker, mail, audit и operational use cases.
- `models/` — ActiveRecord-представление таблиц.
- `components/` — request parsing, trusted proxy, RFC3339, maintenance и metric route helpers.
- `migrations/` — schema и seed permissions.
- `views/admin/`, `web/js/`, `web/css/` — Vue admin UI.
- `commands/` — cleanup, readiness и обслуживание секретов.

Текущие крупные сервисы и глобальная зависимость от `Yii::$app` признаны техдолгом; направление декомпозиции зафиксировано в `PLAN.md`.

## Доменные модули

- Authentication: local password, LDAP fallback, federated login, SSO cookie и Bearer JWT.
- Account: profile, password change/reset, email verification и session management.
- MFA: TOTP enrollment/verification, replay counter и recovery codes.
- OAuth/OIDC Provider: clients, authorization code + PKCE, refresh tokens, client credentials, revoke, introspect, consent, Discovery, JWKS, ID Token, UserInfo и logout.
- Federation Broker: external providers, signed state, callback, identity linking и JIT provisioning.
- Authorization: direct roles, group roles, permissions и scope checks.
- Administration: users, roles, groups, clients, providers, LDAP mappings и audit.
- Operations: health, metrics, cleanup, maintenance mode, secret re-encryption, backup/restore rehearsal и smoke checks.

## Данные и секреты

PostgreSQL хранит пользователей, сессии, auth artifacts, OAuth clients, signing keys, RBAC, LDAP/provider configuration, audit, rate-limit buckets и metric counters.

Email пользователя нормализуется как `trim + lowercase` и уникален без учёта регистра. Смена адреса атомарно сбрасывает `email_verified`, удаляет ранее выданные verification tokens и отзывает sessions, refresh tokens и user JWT. Блокировка и soft delete пользователя также отзывают credentials.

Сырые session, authorization, refresh, one-time и recovery tokens не хранятся: сохраняются SHA-256 hashes. Пароли и confidential client secrets хэшируются Argon2id. LDAP passwords, MFA secrets, provider secrets и OIDC private keys шифруются AES-256-GCM ключом `SSO_ENCRYPTION_KEY`.

Signing, JWT и encryption keys имеют разные назначения и не должны переиспользоваться. Создание session, authorization code, refresh/one-time/recovery token и обязательного audit event проверяется явно. Изменение security-состояния и соответствующий audit выполняются в одной PostgreSQL-транзакции; ошибка записи отменяет весь use case и не допускает успешного ответа с несуществующим артефактом.

Login-attempt обновляется одним PostgreSQL UPSERT: конкурентные ошибки входа не теряют инкременты, а lockout рассчитывается из уже сериализованного значения счётчика. Потребление password reset, MFA challenge/recovery code, authorization code и refresh rotation защищено условным UPDATE либо row lock, поэтому конкурентно успешным может быть только один запрос.

## HTTP и browser security

`ApiController` задаёт JSON responses, ограничение body и rate limiting для чувствительных маршрутов. `TrustedRequest` принимает forwarded IP только от настроенных proxy networks. nginx ограничивает тело до передачи PHP.

Ответы получают CSP, `Permissions-Policy`, `X-Content-Type-Options` и `Referrer-Policy`; production HTTPS также получает HSTS. SSO cookie — HttpOnly, SameSite=Lax и Secure в production. Сырой session token не раскрывается в login JSON. Cookie-authenticated изменяющие операции используют double-submit CSRF cookie/header с дополнительной проверкой Origin и Fetch Metadata. Auth/token responses запрещено кэшировать. Полная модель угроз описана в `SECURITY.md`.

Неизвестные `/api`, `/oauth2` и `/.well-known` маршруты возвращают JSON 404, а остальные GET могут обслуживать SPA.

## OAuth/OIDC

OAuth clients бывают public и confidential. Authorization endpoint поддерживает только code flow; `state` и PKCE S256 обязательны для всех clients, а `nonce` — для OIDC. Redirect URI требуют HTTPS и точного строкового совпадения. Исключение — явно включённый HTTP loopback у public client, где может изменяться только port. Refresh tokens ротируются семьями; повторное использование старого токена отзывает семью.

OIDC ID Token подписывается RS256 активным ключом и содержит проверяемые `iss`, `aud`, `azp`, `exp`, `iat`. Старый ключ остаётся опубликованным как минимум на срок жизни проверяемого token с учётом clock skew. UserInfo раскрывает claims согласно scopes. Пользователь может просмотреть и отозвать consent; отзыв прекращает действие связанных refresh tokens. Поддерживаемый профиль и его негативная матрица зафиксированы в `SECURITY.md` и contract tests.

## LDAP и внешние провайдеры

LDAP использует Search + Bind. Production transport требует TLS/StartTLS, allowlist хостов, connection-local проверки certificate/SAN/hostname и ограниченного network timeout. LDAP group mappings синхронизируют только назначения с `source=ldap`, не удаляя ручные назначения.

Federation broker валидирует provider endpoints, подписывает state и не связывает существующую учётную запись только по совпадению email. Исходящий HTTP запрещает credentials/fragment в endpoint URL, ограничивает private/reserved addresses кроме явного allowlist, защищён от DNS rebinding, не следует redirects и ограничивает body непосредственно во время чтения.

## RBAC и аудит

Admin endpoint требует действующего principal, permission scope для OAuth token и RBAC permission пользователя. Права наследуются из прямых ролей и ролей групп.

Инвариант RBAC требует хотя бы одного активного администратора. Изменение статуса/удаление администратора, снятие последней admin-роли, исключение из admin-группы, удаление такой группы и снятие её admin-роли сериализуются PostgreSQL advisory transaction lock и отклоняются с HTTP 409, если оставляют систему без администратора. Permissions встроенной admin-роли удалять нельзя.

Audit хранит actor, action, target и при необходимости IP/User-Agent. Ошибка сохранения audit event является ошибкой операции; в security-critical use cases audit и изменение состояния имеют общую транзакцию. Политика целостности actor после удаления пользователя ещё должна быть формализована.

## Надёжность и эксплуатация

Rate limit и metrics counters используют атомарные PostgreSQL операции. Структурированные
JSON-логи направляются в stderr и связываются через `X-Request-ID`. Prometheus endpoint
защищён CIDR allowlist и/или bearer token; labels имеют ограниченную cardinality.

Cleanup выполняется scheduler-контейнером батчами под advisory lock, а время последнего
успеха, длительность и число удалённых строк публикуются как метрики. Readiness проверяет
PostgreSQL, локальный encryption round trip и OIDC signing round trip без внешних вызовов.
SLO, alerts, backup/restore и rotation runbooks находятся в `OPERATIONS.md`.

Maintenance flag блокирует весь HTTP кроме liveness на время backup/schema operations. Restore rehearsal разворачивает dump во временную БД и сверяет критическую структуру/данные. Детали находятся в `MIGRATION_NOTES.md`.

## Контракты и документация

- `ENDPOINT_CONTRACTS.md` — нормативный HTTP-контракт проекта.
- `PLAN.md` — единственный активный backlog.
- `README.md` — запуск, конфигурация и операции.
- `MIGRATION_NOTES.md` — deployment/schema/restore runbook.
- `SECURITY.md` — модель угроз, browser/transport policy и поддерживаемый OAuth/OIDC профиль.
- `OPERATIONS.md` — SLO, наблюдаемость и эксплуатационные регламенты.
- `VENDORED_ASSETS.md` — provenance и контроль целостности vendored web assets.

Документация не должна описывать систему через сравнение с другой реализацией.
