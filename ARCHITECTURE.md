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

## Модули

- `BootstrapController` — первичная инициализация администратора.
- `AuthController` + `AuthService` — локальный вход, cookie-сессии, Bearer JWT, отзыв сессий.
- `OAuthController` + `OAuthService` — OAuth-клиенты, authorization code, refresh, revoke, introspect, consent.
- `OidcController` + `OidcService` — discovery, JWKS, RS256 ID Token, UserInfo.
- `AuditService` — запись важных событий.
- `models/*` — ActiveRecord-представление схемы Go-сервиса.

## База данных

Миграция `m260907_000001_init_sso_schema` создаёт актуальную PostgreSQL-схему из Go-миграций `000001-000010`: users, sessions, audit_logs, oauth_clients, authorization codes, OIDC signing keys, refresh tokens, RBAC, groups, LDAP, MFA, one-time tokens и federation.

UUID генерируются PostgreSQL через `pgcrypto/gen_random_uuid()`.

## Конфигурация

Все runtime-настройки используют namespace `SSO_*`. Основные переменные: `SSO_DATABASE_URL`, `SSO_ISSUER`, `SSO_JWT_SECRET`, `SSO_ENCRYPTION_KEY`, `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL`.

## UI

Админский интерфейс обслуживается Yii2 маршрутом `AdminController::actionIndex()` и использует VueJS на одной странице. Текущий UI покрывает bootstrap, login и OAuth clients. Следующие страницы должны переноситься по модулям: users, roles, groups, LDAP, audit, account, identity providers.

## Документация

При каждом функциональном изменении нужно обновлять:

- `PLAN.md` — статус и порядок переноса.
- `ARCHITECTURE.md` — архитектурные решения и границы модулей.
- `README.md` — пользовательские команды, возможности и важные изменения.
