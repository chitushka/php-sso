# Архитектура проекта

## Назначение

Проект является PHP/Yii2 переносом single-tenant SSO/IdP сервиса из Go. Multi-tenancy/realms не входят в область задачи. Основная цель архитектуры — сохранить доменные границы исходного сервиса и постепенно заменить Go-модули эквивалентами на PHP 7.4.

## Технологический стек

- PHP 7.4
- Yii2
- PostgreSQL
- Docker Compose
- VueJS в Yii2 layout без отдельной frontend-сборки

Локальное окружение разработчика не обязано содержать PHP или Composer: запуск, миграции и проверки выполняются внутри Docker-контейнера `app`.

## Слои

```text
HTTP Controller -> Service -> ActiveRecord Model -> PostgreSQL
```

Контроллеры находятся в `controllers/`, бизнес-логика в `services/`, модели таблиц в `models/`, миграции в `migrations/`, публичная входная точка в `web/`, UI в `views/admin/` и `web/css/`.

Для `OAuthController` задан явный `controllerMap`: Yii2 иначе преобразует имя класса в controller id `o-auth`, а внешний контракт Go-сервиса использует стабильный маршрут `oauth/*`.

## Модули

- `BootstrapController` — первичная инициализация администратора.
- `AuthController` + `AuthService` — локальный вход, cookie-сессии, Bearer JWT, отзыв сессий.
- `UsersController` — административный CRUD пользователей и soft delete; создание возвращает Go-compatible 400 для пустых username/email и 409 для конфликта username.
- `RbacController` + `RbacService` — роли, permissions, назначение ролей пользователям и permissions ролям; create role/group возвращает Go-compatible 400 для пустых code/name и 409 для конфликта code.
- `GroupsController` — группы, роли групп и членство пользователей в группах.
- `LdapController` + `LdapService` — CRUD LDAP-провайдеров, test bind и group mappings.
- `LdapService::authenticate()` — LDAP Search + Bind fallback для `AuthService::login()`, auto-provisioning LDAP-пользователей и синхронизация mapped groups.
- `MfaService` — TOTP setup/enable/disable, Go-compatible `enroll`/`activate` aliases, одноразовые recovery codes и MFA challenge для login.
- `AccountService` + `MailService` — password forgot/reset/change, email verification и доставка reset/verification ссылок через SMTP или лог в dev-режиме.
- `BrokerController` + `BrokerService` — identity brokering для Google/GitHub/generic OIDC, admin CRUD identity providers с Go-compatible ошибками создания, signed state, callback, linking/JIT provisioning federated users.
- `OAuthController` + `OAuthService` — OAuth-клиенты, authorization code, refresh, revoke, introspect, consent.
- `OidcController` + `OidcService` — discovery, JWKS, RS256 ID Token, UserInfo.
- `AuditController` — чтение журнала аудита с Go-compatible валидацией фильтров `actor`, `from`, `to`.
- `AuditService` — запись важных событий.
- `CryptoService` — AES-256-GCM шифрование секретов без Composer-зависимостей.
- `RateLimitService` — token bucket на Yii2 cache для чувствительных auth/OAuth/bootstrap маршрутов.
- `MetricsService` — dependency-free Prometheus text метрики HTTP-запросов на `/metrics`, сохранённые через Yii2 cache.
- `TrustedRequest` — безопасное разрешение client IP: `X-Forwarded-For` учитывается только от `SSO_TRUSTED_PROXIES`.
- `CleanupController` — console-команда удаления истёкших auth/OAuth артефактов.
- `models/*` — ActiveRecord-представление схемы Go-сервиса.

## База данных

Миграция `m260907_000001_init_sso_schema` создаёт актуальную PostgreSQL-схему из Go-миграций `000001-000010`: users, sessions, audit_logs, oauth_clients, authorization codes, OIDC signing keys, refresh tokens, RBAC, groups, LDAP, MFA, one-time tokens и federation.

UUID генерируются PostgreSQL через `pgcrypto/gen_random_uuid()`.

## Конфигурация

Все runtime-настройки используют namespace `SSO_*`. Основные переменные: `SSO_DATABASE_URL`, `SSO_ISSUER`, `SSO_JWT_SECRET`, `SSO_ENCRYPTION_KEY`, `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL`.

Rate limiting настраивается через `SSO_RATE_LIMIT_PER_MINUTE` и `SSO_RATE_LIMIT_BURST`. По умолчанию token bucket хранится в `FileCache`; для нескольких app-инстансов можно задать `SSO_CACHE_DRIVER=db`, тогда Yii2 cache использует таблицу `cache` в PostgreSQL. Максимальный размер тела запроса задаёт `SSO_MAX_BODY_BYTES`. `SSO_TRUSTED_PROXIES` содержит comma-separated IP/CIDR reverse proxy, которым разрешено задавать `X-Forwarded-For`.

## Защитные механизмы

`AuthService` хранит неудачные попытки входа в `login_attempts`. После 5 ошибок пара login/IP блокируется с экспоненциальной задержкой от 1 до 15 минут. Успешный вход удаляет счётчик.

`ApiController` применяет общий лимит тела запроса и вызывает `RateLimitService` для `auth/login`, `bootstrap/create`, `oauth/token`, `oauth/revoke`, `oauth/introspect`.

`ApiController::requirePermission()` загружает пользователя по Bearer JWT и проверяет право через `RbacService`. `RbacService` учитывает как прямые роли из `user_roles`, так и роли, унаследованные через `user_groups -> group_roles`.

LDAP bind password сохраняется в `ldap_providers.bind_password` в формате `v1:<base64>` через `CryptoService`. Ответы API не возвращают секрет. При update пустой `bind_password` сохраняет уже записанный секрет.

При локальном login `AuthService` сначала проверяет локальный `password_hash`; если проверка не прошла, `LdapService` перебирает включённые LDAP providers, выполняет service bind, ищет пользователя по `user_filter`, затем проверяет пароль bind-ом найденного DN. LDAP-пользователь создаётся или обновляется в `users` с `source=ldap`, `ldap_provider_id` и `ldap_dn`. Если найден локальный пользователь с тем же username, LDAP login отклоняется, чтобы не захватить локальный аккаунт. LDAP group mappings синхронизируются в `user_groups` с `source=ldap`; ручные назначения не удаляются.

Для пользователей с `mfa_enabled=true` успешная проверка пароля не создаёт сессию сразу: `AuthService` создаёт `one_time_tokens.purpose=mfa_login` и возвращает `mfa_token` плюс legacy alias `challenge_token`. `POST /api/v1/auth/mfa/verify` принимает `mfa_token` или `challenge_token` и TOTP/recovery code, после чего создаёт обычную cookie-сессию и Bearer JWT. TOTP enrollment сохраняет encrypted secret в `users.mfa_secret` до активации; доступны Go-compatible маршруты `/api/v1/auth/mfa/enroll` и `/api/v1/auth/mfa/activate`, а также PHP aliases `/setup` и `/enable`. `mfa_last_used_counter` блокирует повтор counter, recovery codes хранятся в `mfa_recovery_codes` как password hash и помечаются `used_at`.

`AccountService` использует `one_time_tokens.purpose=password_reset` и `email_verify` с SHA-256 hash от сырого токена. `POST /api/v1/auth/password/forgot` всегда возвращает 200 при валидном body, чтобы не раскрывать наличие пользователя; reset-ссылка отправляется через `MailService`, а без SMTP пишется в лог категории `mail`. `POST /api/v1/auth/password/reset` меняет пароль локального аккаунта, отзывает все `sessions`, `refresh_tokens` и выставляет `tokens_invalid_before`. `POST /api/v1/auth/password/change` доступен только локальным аккаунтам и требует текущий пароль. `PUT /api/v1/auth/profile` позволяет текущему пользователю обновлять `first_name`, `last_name` и scalar `attributes` без admin-права `users:update`. `POST /api/v1/auth/email/verify` помечает email подтверждённым и переводит `pending`-аккаунт в `active`.

`BrokerService` хранит identity provider client secrets в `identity_providers.client_secret` через `CryptoService`. `GET /api/v1/broker/providers` отдаёт только включённые провайдеры для login UI. `GET /oauth2/broker/{code}/login` создаёт HMAC-signed state на 10 минут и перенаправляет во внешний IdP; callback обменивает authorization code, читает userinfo и создаёт обычную SSO cookie-сессию плюс Bearer JWT во fragment `#broker_token=...`. Линковка существующего аккаунта разрешена только при `email_verified=true` от IdP; MFA-защищённые локальные аккаунты не линкуются автоматически. Если подходящего аккаунта нет, создаётся `source=federated` пользователь и запись `federated_identities`.

Refresh token rotation сохраняет `refresh_tokens.family_id` между поколениями. При refresh grant старый token помечается `rotated_at` атомарным `UPDATE ... WHERE rotated_at IS NULL`; если token уже rotated или параллельный запрос проиграл этот guard, вся refresh family получает `revoked_at`, а в audit пишется `refresh_token_reuse_detected`.

Для public OAuth clients `OAuthService::authorize()` требует `code_challenge_method=S256` и непустой `code_challenge`; отсутствие PKCE возвращает RFC-style `invalid_request` с описанием `PKCE S256 required`.

`OidcController::actionUserinfo()` фильтрует claims по scope OAuth access token: `profile` раскрывает `preferred_username` и `source`, `email` раскрывает `email`. Локальные first-party Bearer tokens без `scope` сохраняют полный исторический ответ.

`OAuthService::logout()` реализует RP-initiated logout: валидирует RS256 `id_token_hint` по stored OIDC signing key, issuer и возрасту hint; отзывает текущую `sso_session` cookie-сессию и refresh tokens пользователя; `post_logout_redirect_uri` разрешён только если он зарегистрирован у клиента из `id_token_hint`.

`TrustedRequest` возвращает `Yii::$app->request->userIP` из прямого peer address, если peer не входит в `SSO_TRUSTED_PROXIES`. Для trusted proxy берётся правый самый не-trusted IP из `X-Forwarded-For`, что сохраняет per-IP brute-force lockout и rate limit за reverse proxy без доверия к spoofed headers.

`php yii cleanup/run` удаляет истёкшие/отозванные `sessions`, `oauth_authorization_codes`, `refresh_tokens`, `one_time_tokens` и старые `login_attempts`. В production команду нужно запускать cron-ом или аналогичным scheduler-ом примерно раз в час.

`GET /metrics` отдаёт Go-compatible Prometheus text exposition с `sso_http_requests_total`, `sso_http_inflight`, `sso_http_request_duration_seconds_sum` и `sso_http_requests_processed_total`. В PHP-порте counters хранятся через Yii2 cache, поэтому для нескольких app-инстансов рекомендуется `SSO_CACHE_DRIVER=db`.

`tests/Integration/oauth_flow.php` — dependency-free HTTP smoke test, который поднимается через `make integration`, создаёт временных пользователей/клиенты в PostgreSQL, проверяет OIDC Discovery/JWKS, login, OAuth2 Authorization Code + PKCE, `client_secret_post` и `client_secret_basic`, одноразовость authorization code, RS256 `id_token` claims, UserInfo scope filtering, introspection active/inactive token, revocation refresh token, client_credentials token contract, consent-required/grant flow, RP logout redirect validation, refresh rotation/reuse detection, RBAC enforcement и базовые OAuth error contracts для authorize/token/revoke/introspect, затем удаляет созданные записи. Цель `make integration` поднимает встроенный PHP server с высоким test-local rate limit, чтобы расширенный smoke flow проверял endpoint-контракты без ложных 429.

`tests/Integration/oauth_client.php` — dependency-free внешний RP client flow, запускаемый через `make integration-client`. Сценарий читает OIDC Discovery, формирует authorize URL, проверяет callback redirect/state, обменивает code через `client_secret_basic`, валидирует базовые `id_token` claims, вызывает UserInfo, refresh token и RP-initiated logout. Для тестового сервера цель задаёт `SSO_ISSUER=http://127.0.0.1:8081`, чтобы discovery endpoint-ы указывали на локальный app.

`tests/Unit/security_logic.php` — dependency-free unit security smoke, запускаемый через `make unit` без PostgreSQL. Он проверяет границы `PasswordPolicy` 12/128 символов и trusted-proxy IP resolution в `TrustedRequest`, включая игнорирование spoofed `X-Forwarded-For` от недоверенного peer, выбор rightmost untrusted IP, IPv6 CIDR и нормализацию IPv4 peer address с портом.

## UI

Админский интерфейс обслуживается Yii2 маршрутом `AdminController::actionIndex()` и использует VueJS на одной странице. Текущий UI покрывает bootstrap, login с MFA challenge и external sign-in buttons, forgot/reset password, account profile/password/email verification, OAuth clients, identity providers, users, roles, permissions, groups, LDAP providers, audit и базовое управление MFA текущего аккаунта. Формы назначений работают поверх RBAC API: роли пользователям, группы пользователям, permissions ролям и роли группам. Следующие UI-страницы должны переноситься по модулям: production/admin maintenance и тестовые сценарии совместимости.

## Документация

При каждом функциональном изменении нужно обновлять:

- `PLAN.md` — статус и порядок переноса.
- `ARCHITECTURE.md` — архитектурные решения и границы модулей.
- `README.md` — пользовательские команды, возможности и важные изменения.
- `MIGRATION_NOTES.md` — операционный порядок cutover/rollback при изменениях, влияющих на переход с Go-сервиса.

## Переход с Go-сервиса

`MIGRATION_NOTES.md` фиксирует production cutover с Go-версии на PHP/Yii2: совместимые публичные маршруты, подготовку `.env`, порядок применения Yii2-миграций, smoke-checklist после переключения reverse proxy и rollback через дамп PostgreSQL. Документ намеренно отделён от архитектуры, потому что описывает операционную процедуру, а не runtime-модуль приложения.
