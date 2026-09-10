# Архитектура проекта

## Назначение

Проект является PHP/Yii2 переносом single-tenant SSO/IdP сервиса из Go. Multi-tenancy/realms не входят в область задачи. Основная цель архитектуры — сохранить доменные границы исходного сервиса и постепенно заменить Go-модули эквивалентами на PHP 7.4.

## Технологический стек

- PHP 7.4
- Yii2
- PostgreSQL
- Docker Compose
- VueJS в Yii2 layout без отдельной frontend-сборки

## Слои

```text
HTTP Controller -> Service -> ActiveRecord Model -> PostgreSQL
```

Контроллеры находятся в `controllers/`, бизнес-логика в `services/`, модели таблиц в `models/`, миграции в `migrations/`, UI в `views/admin/` и `public/css/`.

Для `OAuthController` задан явный `controllerMap`: Yii2 иначе преобразует имя класса в controller id `o-auth`, а внешний контракт Go-сервиса использует стабильный маршрут `oauth/*`.

## Модули

- `BootstrapController` — первичная инициализация администратора.
- `AuthController` + `AuthService` — локальный вход, cookie-сессии, Bearer JWT, отзыв сессий.
- `UsersController` — административный CRUD пользователей и soft delete.
- `RbacController` + `RbacService` — роли, permissions, назначение ролей пользователям и permissions ролям.
- `GroupsController` — группы, роли групп и членство пользователей в группах.
- `LdapController` + `LdapService` — CRUD LDAP-провайдеров, test bind и group mappings.
- `OAuthController` + `OAuthService` — OAuth-клиенты, authorization code, refresh, revoke, introspect, consent.
- `OidcController` + `OidcService` — discovery, JWKS, RS256 ID Token, UserInfo.
- `AuditController` — чтение журнала аудита с фильтрами.
- `AuditService` — запись важных событий.
- `CryptoService` — AES-256-GCM шифрование секретов без Composer-зависимостей.
- `RateLimitService` — token bucket на Yii2 cache для чувствительных auth/OAuth/bootstrap маршрутов.
- `models/*` — ActiveRecord-представление схемы Go-сервиса.

## База данных

Миграция `m260907_000001_init_sso_schema` создаёт актуальную PostgreSQL-схему из Go-миграций `000001-000010`: users, sessions, audit_logs, oauth_clients, authorization codes, OIDC signing keys, refresh tokens, RBAC, groups, LDAP, MFA, one-time tokens и federation.

UUID генерируются PostgreSQL через `pgcrypto/gen_random_uuid()`.

## Конфигурация

Все runtime-настройки используют namespace `SSO_*`. Основные переменные: `SSO_DATABASE_URL`, `SSO_ISSUER`, `SSO_JWT_SECRET`, `SSO_ENCRYPTION_KEY`, `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL`.

Rate limiting настраивается через `SSO_RATE_LIMIT_PER_MINUTE` и `SSO_RATE_LIMIT_BURST`. Максимальный размер тела запроса задаёт `SSO_MAX_BODY_BYTES`.

## Защитные механизмы

`AuthService` хранит неудачные попытки входа в `login_attempts`. После 5 ошибок пара login/IP блокируется с экспоненциальной задержкой от 1 до 15 минут. Успешный вход удаляет счётчик.

`ApiController` применяет общий лимит тела запроса и вызывает `RateLimitService` для `auth/login`, `bootstrap/create`, `oauth/token`, `oauth/revoke`, `oauth/introspect`.

`ApiController::requirePermission()` загружает пользователя по Bearer JWT и проверяет право через `RbacService`. `RbacService` учитывает как прямые роли из `user_roles`, так и роли, унаследованные через `user_groups -> group_roles`.

LDAP bind password сохраняется в `ldap_providers.bind_password` в формате `v1:<base64>` через `CryptoService`. Ответы API не возвращают секрет. При update пустой `bind_password` сохраняет уже записанный секрет.

## UI

Админский интерфейс обслуживается Yii2 маршрутом `AdminController::actionIndex()` и использует VueJS на одной странице. Текущий UI покрывает bootstrap, login, OAuth clients, users, roles, permissions, groups, LDAP providers и audit. Формы назначений работают поверх RBAC API: роли пользователям, группы пользователям, permissions ролям и роли группам. Следующие страницы должны переноситься по модулям: account, identity providers, MFA и recovery flows.

## Документация

При каждом функциональном изменении нужно обновлять:

- `PLAN.md` — статус и порядок переноса.
- `ARCHITECTURE.md` — архитектурные решения и границы модулей.
- `README.md` — пользовательские команды, возможности и важные изменения.
