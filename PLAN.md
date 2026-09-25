# План переноса SSO

## Цель

Перенести функционал рабочего Go-сервиса `D:\workspace\GolandProjects\sso` в PHP 7.4 на Yii2, сохранив single-tenant модель, PostgreSQL-схему, публичные OAuth2/OIDC-контракты и административный UI.

## Этап 1. Инициализация проекта

- Создать Yii2-приложение с Docker-сборкой и PostgreSQL.
- Подключить конфигурацию через переменные `SSO_*`.
- Перенести актуальную схему БД из Go-миграций `000001-000010` в стартовую Yii2-миграцию.
- Сделать базовую Vue-админку на стандартном Yii2 layout и стандартном web root `web/`.

Статус: выполнено.

Health-check контракт завершён: live/version сохраняют публичные ответы Go, readiness проверяет PostgreSQL и возвращает 503 `database unavailable` при сбое; успешные probes покрыты HTTP smoke, отказ БД — unit-тестом.

## Этап 2. Базовая авторизация

- Перенести bootstrap первого администратора.
- Реализовать локальный login/logout, cookie `sso_session`, Bearer JWT и отзыв пользовательских сессий.
- Сохранить политику паролей 12-128 символов.
- Добавить аудит ключевых действий.

Дополнение 2026-09-18: Bearer-аутентификация защищённых account/session/admin/OIDC endpoint-ов различает Go-compatible ошибки `missing bearer token`, `invalid token`, `account is not active` и `token revoked`.

Дополнение 2026-09-24: успешный local/MFA/federated login возвращает Go `LoginResult`: raw `session_token`, RFC3339 `access_token_expires_at` и `session_expires_at`; OAuth-only поля `token_type`/`expires_in` удалены из auth-ответа. Cookie `sso_session` содержит тот же raw session token без Yii signing envelope, поэтому остаётся совместимой между Go/PHP-инстансами при rolling migration.

Дополнение 2026-09-24: wire-format `User` и Session API выровнен с Go JSON: timestamps сериализуются как RFC3339 UTC, пустые user attributes возвращаются объектом `{}`, nullable `ldap_provider_id`/`ldap_dn`/`last_login_at` и `revoked_at` активной сессии отсутствуют согласно `omitempty`.

Дополнение 2026-09-24: Identity Providers API сериализует `created_at`/`updated_at` как RFC3339 UTC и при PUT явно продвигает `updated_at`, повторяя Go repository `UPDATE ... updated_at=now() RETURNING`.

Дополнение 2026-09-24: Identity Providers POST/PUT выровнены с Go zero-value/full-replacement семантикой `Provider`: пропущенные `scopes`/`enabled` при create остаются пустыми/false, а PUT очищает все пропущенные изменяемые поля. Пустой `client_secret` при PUT по-прежнему сохраняет зашифрованный текущий секрет.

Дополнение 2026-09-24: LDAP Providers и group mappings возвращают Go-compatible RFC3339 timestamps; create перечитывает DB-generated даты, а PUT провайдера явно обновляет `updated_at`.

Дополнение 2026-09-25: LDAP Providers POST/PUT выровнены с Go zero-value/full-replacement семантикой `Provider`: create применяет Go defaults только к port, user filter и трём основным attributes, а PUT очищает пропущенные поля. Пустой `bind_password` при PUT сохраняет текущий зашифрованный пароль.

Дополнение 2026-09-25: ошибки PostgreSQL в LDAP provider create/update скрываются за Go-compatible 500 `failed to create/update provider`; duplicate group mapping возвращает 409 `mapping already exists`, а невалидный `group_id` отклоняется как 400 `invalid json body` на уровне декодирования.

Дополнение 2026-09-25: LDAP repository lifecycle выровнен с фактическим Go-поведением: DELETE provider идемпотентен, group mappings сортируются по `ldap_group`, create provider не пишет отсутствующее в Go audit-событие, а update/delete/mapping events используют пустого actor и точные target type/id.

Дополнение 2026-09-25: storage-контракт AES-256-GCM выровнен с Go `secrets.AESGCM` для LDAP bind passwords, MFA secrets и identity provider client secrets: `enc:v1:`, unpadded standard Base64 и порядок `nonce || ciphertext || tag`. PHP читает как Go ciphertext, так и ранний PHP `v1:` формат, а новые записи создаёт только в Go-формате для rolling migration и безопасного rollback.

Статус: выполнено. Bootstrap первого администратора выровнен с Go по порядку валидации, zero value `email_verified=false`, назначению admin-роли и событию `bootstrap_completed`; сценарий проверяется PostgreSQL-тестом в откатываемой транзакции. Brute-force lockout перенесён через таблицу `login_attempts`; rate limit перенесён через Yii2 cache-backed token bucket для bootstrap/auth/OAuth endpoint-ов.

## Этап 3. OAuth2 и OIDC

- Перенести CRUD OAuth-клиентов.
- Реализовать Authorization Code + PKCE, refresh token rotation, revocation, introspection.
- Поддержать OIDC Discovery, JWKS, RS256 ID Token и UserInfo.
- Сверить ответы ошибок с RFC 6749.

Дополнение 2026-09-18: OAuth consent lifecycle выровнен с Go: GET требует активную SSO-сессию, валидирует включённого клиента и scope, возвращает `requested_scopes`/`granted_scopes`; повторные POST объединяют scopes и создают audit-событие `consent_granted`.

Дополнение 2026-09-24: OAuth Clients API сериализует `created_at`/`updated_at` как Go RFC3339 UTC; PUT и rotate-secret теперь явно продвигают `updated_at`, повторяя PostgreSQL repository Go-сервиса, вместо сохранения исходного времени создания.

Дополнение 2026-09-18: RFC 7009/7662 endpoints выровнены с Go: revocation отзывает всю refresh family, пишет `refresh_token_revoked` и возвращает пустой body; introspection поддерживает access и refresh tokens с точным набором claims и состояниями active/rotated/revoked/expired; invalid client responses содержат `WWW-Authenticate: Basic realm="sso"`.

Дополнение 2026-09-18: authorization code exchange выровнен с Go: код атомарно и одноразово потребляется до проверки client/redirect/PKCE, поэтому неудачный обмен нельзя повторить; любой сохранённый PKCE challenge проверяется только как S256, включая confidential clients.

Дополнение 2026-09-18: реализован заявленный в Discovery OIDC Back-Channel Logout: для клиента с `backchannel_logout_uri` создаётся двухминутный RS256 `logout_token` с `jti` и стандартным event claim и отправляется form POST; ошибки RP не отменяют локальный logout. POST-параметры logout имеют Go-compatible приоритет над query.

Дополнение 2026-09-18: перенесён lifecycle OIDC signing keys: active RSA-ключ ротируется после 30 дней, прежний ключ ещё 24 часа публикуется в JWKS как `retiring`, затем переводится в `retired`; PostgreSQL advisory lock исключает параллельное создание нескольких active keys PHP workers.

Дополнение 2026-09-18: ID Token claims выровнены с Go: `auth_time` берётся из `created_at` authorization code или refresh token, а `nonce` присутствует всегда, включая пустое значение при refresh grant.

Дополнение 2026-09-18: формирование OAuth redirect URI выровнено с Go: authorize и RP logout заменяют служебные `code`/`state` в query, сохраняют остальные параметры и всегда размещают query до URI fragment.

Дополнение 2026-09-18: `client_secret_basic` выровнен с RFC 6749 и Go `url.QueryUnescape`: credentials декодируются как form-urlencoded, включая различение пробела (`+`) и литерального плюса (`%2B`).

Дополнение 2026-09-18: ответ `client_credentials` выровнен с Go `TokenResult`: подтверждённый непустой `scope` возвращается клиенту, а при пустом scope поле отсутствует согласно `omitempty`.

Дополнение 2026-09-18: HS256 access token claims выровнены с Go `auth.Claims`: login/OAuth tokens содержат `username`, `email`, `source`, OAuth client metadata добавляется только по назначению, пустые optional claims не сериализуются, а не заявленный Go-сервисом `iss` удалён.

Дополнение 2026-09-18: HS256 verifier выровнен с Go security contract: принимается только JWT header `alg=HS256`, `exp` обязан быть числовым и строго больше текущего времени; подписанные HMAC-SHA256 токены с подменённым algorithm header больше не принимаются.

Дополнение 2026-09-18: разбор compact JWT усилен до строгого unpadded base64url для header, payload и signature; optional claim `nbf` проверяется как числовой `NumericDate`, поэтому токен не принимается раньше разрешённого времени.

Дополнение 2026-09-18: wire-format identity broker state выровнен с Go для rolling migration: HMAC-SHA256 использует общий `SSO_JWT_SECRET`, expiry сериализуется как RFC3339 `time.Time`, а payload/signature строго декодируются как unpadded base64url. Login, начатый на Go-инстансе, теперь может завершиться callback'ом на PHP-инстансе и наоборот.

Дополнение 2026-09-25: identity broker redirect contract выровнен с Go: authorize URL заменяет служебные query-параметры без дубликатов, сохраняет остальные параметры и fragment, а provider/callback errors возвращают пользователя на `/login?broker_error=...`.

Статус: выполнено. Refresh token rotation hardened против гонок и reuse: family сохраняется при rotation, а повторное/параллельное использование старого refresh token отзывает всю family. Public OAuth clients требуют PKCE S256. OIDC UserInfo фильтрует claims по scope, discovery содержит revocation/introspection metadata. RP-initiated logout валидирует `id_token_hint` и `post_logout_redirect_uri`, отзывает SSO-сессию и refresh tokens пользователя. Административные GET/PUT/DELETE/rotate-secret маршруты OAuth-клиентов валидируют path UUID до service/DB слоя и возвращают Go-compatible 400/404 ошибки; create возвращает `{client, client_secret}` и использует Go zero values для enabled/массивов, а update полностью заменяет поля `UpdateClientInput`, сохраняя неизменным `type`. Consent POST использует строгую схему `client_id`/`scope`, а malformed, non-object и unknown-field JSON возвращают RFC-style `invalid_request` с Go-compatible описанием. HTTP smoke flow покрывает Authorization Code + PKCE, OIDC id_token/UserInfo/logout, refresh rotation/reuse detection, RBAC enforcement и ключевые RFC 6749 error contracts; полная endpoint-матрица закрыта этапом 6.

## Этап 4. Административные модули

- Перенести Users, Roles, Permissions, Groups, Audit, LDAP providers.
- Включить RBAC middleware для всех admin API.
- Доработать Vue-страницы по аналогии с Go UI.

Статус: выполнено для базового admin-функционала. Перенесены RBAC-проверка прав, Users API, Roles API, Permissions API, Groups API, Audit API, LDAP providers API, LDAP test bind, LDAP group mappings, базовые Vue-вкладки и UI-формы назначения ролей пользователям, групп пользователям, permissions ролям и ролей группам. Users/Roles/Groups API дополнительно выровнены с Go-контрактом ошибок создания для обязательных полей и конфликтов уникального code/username; Users API валидирует path UUID для GET/PUT/DELETE, использует Go-границу pagination limit 100 и точные create/update JSON-схемы без password/email_verified в update; Roles/Groups API валидируют UUID для CRUD и assignments, а также отклоняют неизвестные JSON-поля как Go `DisallowUnknownFields`; Identity Providers API валидирует UUID для update/delete, различает невалидный ID и отсутствующего провайдера и использует строгую Go model-схему; LDAP Providers/test/group mappings валидируют provider/mapping UUID с Go-compatible порядком ошибок и отклоняют неизвестные JSON-поля; Audit API выровнен по валидации фильтров `actor`, `from`, `to`.

Дополнение по Audit API: `actor` проходит строгую UUID-проверку, RFC3339-фильтры принимают дробные секунды, а `limit`/`offset` повторяют Go-семантику; невалидный или превышающий 200 `limit` сбрасывается к 50.

Дополнение 2026-09-24: Audit API выровнен с Go JSON wire-format: `created_at` возвращается как RFC3339 UTC, пустой `actor_user_id` отсутствует согласно `omitempty`, nullable PostgreSQL `ip`/`user_agent` сериализуются строковыми zero values.

Дополнение 2026-09-24: `PUT /api/v1/users/{id}` выровнен с Go `UpdateUserInput`: разрешённые поля полностью заменяются, поэтому отсутствующие `first_name`, `last_name` и `attributes` очищаются, а не сохраняют прежние значения. Контракт закреплён HTTP smoke-проверкой.

Дополнение 2026-09-24: роли, permissions и группы используют Go JSON wire-format с RFC3339 UTC timestamps и точной `omitempty`/zero-value семантикой `description`. PUT ролей и групп полностью заменяет update-поля и явно продвигает `updated_at` как Go repositories.

## Этап 5. Security hardening

- Перенести MFA TOTP, recovery codes, email verification, password reset/change.
- Перенести LDAP Search + Bind authentication при login.
- Перенести identity brokering для Google/GitHub/OIDC.
- Добавить trusted proxy IP, cleanup expired records и production-настройки shared rate limit для горизонтального масштабирования.

Статус: выполнено. LDAP Search + Bind authentication при login перенесён: включённые LDAP providers используются как fallback после локальной проверки пароля, LDAP-пользователь создаётся/обновляется в `users`, а LDAP group mappings синхронизируют группы с `source=ldap`. Полный контур подтверждён автономным OpenLDAP integration flow: service bind, search, user bind, JIT-provisioning, group mapping и audit. MFA TOTP и recovery codes перенесены: login выдаёт одноразовый MFA challenge с Go-compatible `mfa_token`, TOTP enrollment поддерживает маршруты `/api/v1/auth/mfa/enroll` и `/activate`, TOTP защищён от повторного counter, recovery codes одноразовые. MFA verification выровнен с Go security contract: неизвестный/использованный token и неверный код имеют разные 401-ошибки, неверные коды учитываются общим login/IP lockout, успешная проверка сбрасывает счётчик. Контракты повторного `activate` и `disable` также выровнены: точные статусы/ошибки, успешный ответ `mfa_disabled` и сохранение текущего Bearer JWT. Password reset/change и email verification перенесены через hashed one-time tokens; reset отзывает пользовательские сессии, refresh tokens и ранее выданные first-party Bearer JWT, тогда как OAuth access tokens внешних клиентов сохраняют Go-compatible срок жизни до `exp`. Identity brokering для Google/GitHub/OIDC перенесён: admin CRUD провайдеров, public список кнопок входа, signed state, callback, JIT-provisioning, безопасная линковка по verified email и Go-compatible ошибки создания identity providers. Production hardening перенесён: trusted proxy IP resolution, cleanup expired records, Go-compatible `/metrics` с chi-совместимыми route templates без UUID-кардинальности и опциональный DB-backed cache для rate limit/metrics. Расширенный account profile перенесён для self-service обновления first/last name и attributes.

## Этап 6. Проверка совместимости

- Добавить PHPUnit/integration tests против PostgreSQL.
- Сравнить контракты эндпоинтов с Go-сервисом.
- Проверить OAuth/OIDC flow внешним тестовым клиентом.
- Подготовить migration notes для перехода с Go-версии.

Дополнение 2026-09-18: HTTP smoke закрепляет точные Bearer-ошибки для account/session, admin API и UserInfo. `password/forgot` повторяет особый Go-контракт и возвращает `login is required` при malformed, non-object или unknown-field JSON вместо общего `invalid json body`.

Статус: выполнено. Матрица endpoint-контрактов зафиксирована в `ENDPOINT_CONTRACTS.md`. PHP повторяет Go `encoding/json` + `DisallowUnknownFields`, включая строгие scalar/list/map/UUID/time типы, case-insensitive matching имён, `null`/zero values и порядок path/body/auth проверок. Выравнены редкие статусы и ответы Users/OIDC/MFA, UUID-формы `google/uuid.Parse`, login LDAP fallback и audit side effects. Контракты закреплены unit, PostgreSQL PHPUnit, расширенным HTTP smoke flow, внешним OAuth/OIDC client flow, mock broker flow и автономным OpenLDAP bind/search flow. Проверка конкретного корпоративного LDAP через `make integration-ldap-external` остаётся опциональной инфраструктурной сертификацией, а не частью переноса endpoint-функциональности.
