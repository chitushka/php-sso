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

Статус: выполнено. Bootstrap первого администратора выровнен с Go по порядку валидации, zero value `email_verified=false`, назначению admin-роли и событию `bootstrap_completed`; сценарий проверяется PostgreSQL-тестом в откатываемой транзакции. Brute-force lockout перенесён через таблицу `login_attempts`; rate limit перенесён через Yii2 cache-backed token bucket для bootstrap/auth/OAuth endpoint-ов.

## Этап 3. OAuth2 и OIDC

- Перенести CRUD OAuth-клиентов.
- Реализовать Authorization Code + PKCE, refresh token rotation, revocation, introspection.
- Поддержать OIDC Discovery, JWKS, RS256 ID Token и UserInfo.
- Сверить ответы ошибок с RFC 6749.

Дополнение 2026-09-18: OAuth consent lifecycle выровнен с Go: GET требует активную SSO-сессию, валидирует включённого клиента и scope, возвращает `requested_scopes`/`granted_scopes`; повторные POST объединяют scopes и создают audit-событие `consent_granted`.

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

Статус: базовая реализация выполнена; refresh token rotation hardened против гонок и reuse: family сохраняется при rotation, а повторное/параллельное использование старого refresh token отзывает всю family. Public OAuth clients требуют PKCE S256. OIDC UserInfo фильтрует claims по scope, discovery содержит revocation/introspection metadata. RP-initiated logout валидирует `id_token_hint` и `post_logout_redirect_uri`, отзывает SSO-сессию и refresh tokens пользователя. Административные GET/PUT/DELETE/rotate-secret маршруты OAuth-клиентов валидируют path UUID до service/DB слоя и возвращают Go-compatible 400/404 ошибки; create возвращает `{client, client_secret}` и использует Go zero values для enabled/массивов, а update полностью заменяет поля `UpdateClientInput`, сохраняя неизменным `type`. Consent POST использует строгую схему `client_id`/`scope`, а malformed, non-object и unknown-field JSON возвращают RFC-style `invalid_request` с Go-compatible описанием. Добавлен HTTP smoke flow для Authorization Code + PKCE, OIDC id_token/UserInfo/logout, refresh rotation/reuse detection, RBAC enforcement и ключевых RFC 6749 error contracts. Нужна дальнейшая точечная сверка оставшихся endpoint-контрактов с Go-сервисом.

## Этап 4. Административные модули

- Перенести Users, Roles, Permissions, Groups, Audit, LDAP providers.
- Включить RBAC middleware для всех admin API.
- Доработать Vue-страницы по аналогии с Go UI.

Статус: выполнено для базового admin-функционала. Перенесены RBAC-проверка прав, Users API, Roles API, Permissions API, Groups API, Audit API, LDAP providers API, LDAP test bind, LDAP group mappings, базовые Vue-вкладки и UI-формы назначения ролей пользователям, групп пользователям, permissions ролям и ролей группам. Users/Roles/Groups API дополнительно выровнены с Go-контрактом ошибок создания для обязательных полей и конфликтов уникального code/username; Users API валидирует path UUID для GET/PUT/DELETE, использует Go-границу pagination limit 100 и точные create/update JSON-схемы без password/email_verified в update; Roles/Groups API валидируют UUID для CRUD и assignments, а также отклоняют неизвестные JSON-поля как Go `DisallowUnknownFields`; Identity Providers API валидирует UUID для update/delete, различает невалидный ID и отсутствующего провайдера и использует строгую Go model-схему; LDAP Providers/test/group mappings валидируют provider/mapping UUID с Go-compatible порядком ошибок и отклоняют неизвестные JSON-поля; Audit API выровнен по валидации фильтров `actor`, `from`, `to`.

Дополнение по Audit API: `actor` проходит строгую UUID-проверку, RFC3339-фильтры принимают дробные секунды, а `limit`/`offset` повторяют Go-семантику; невалидный или превышающий 200 `limit` сбрасывается к 50.

## Этап 5. Security hardening

- Перенести MFA TOTP, recovery codes, email verification, password reset/change.
- Перенести LDAP Search + Bind authentication при login.
- Перенести identity brokering для Google/GitHub/OIDC.
- Добавить trusted proxy IP, cleanup expired records и production-настройки shared rate limit для горизонтального масштабирования.

Статус: частично выполнено. LDAP Search + Bind authentication при login перенесён: включённые LDAP providers используются как fallback после локальной проверки пароля, LDAP-пользователь создаётся/обновляется в `users`, а LDAP group mappings синхронизируют группы с `source=ldap`. MFA TOTP и recovery codes перенесены: login выдаёт одноразовый MFA challenge с Go-compatible `mfa_token`, TOTP enrollment поддерживает маршруты `/api/v1/auth/mfa/enroll` и `/activate`, TOTP защищён от повторного counter, recovery codes одноразовые. MFA verification выровнен с Go security contract: неизвестный/использованный token и неверный код имеют разные 401-ошибки, неверные коды учитываются общим login/IP lockout, успешная проверка сбрасывает счётчик. Контракты повторного `activate` и `disable` также выровнены: точные статусы/ошибки, успешный ответ `mfa_disabled` и сохранение текущего Bearer JWT. Password reset/change и email verification перенесены через hashed one-time tokens; reset отзывает пользовательские сессии, refresh tokens и ранее выданные first-party Bearer JWT, тогда как OAuth access tokens внешних клиентов сохраняют Go-compatible срок жизни до `exp`. Identity brokering для Google/GitHub/OIDC перенесён: admin CRUD провайдеров, public список кнопок входа, signed state, callback, JIT-provisioning, безопасная линковка по verified email и Go-compatible ошибки создания identity providers. Production hardening частично перенесён: trusted proxy IP resolution, cleanup expired records, Go-compatible `/metrics` с chi-совместимыми route templates без UUID-кардинальности и опциональный DB-backed cache для rate limit/metrics. Расширенный account profile перенесён для self-service обновления first/last name и attributes. Следующими остаются интеграционные проверки совместимости.

## Этап 6. Проверка совместимости

- Добавить PHPUnit/integration tests против PostgreSQL.
- Сравнить контракты эндпоинтов с Go-сервисом.
- Проверить OAuth/OIDC flow внешним тестовым клиентом.
- Подготовить migration notes для перехода с Go-версии.

Дополнение 2026-09-18: HTTP smoke закрепляет точные Bearer-ошибки для account/session, admin API и UserInfo. `password/forgot` повторяет особый Go-контракт и возвращает `login is required` при malformed, non-object или unknown-field JSON вместо общего `invalid json body`.

Статус: частично выполнено. Добавлен dependency-free HTTP smoke test `tests/Integration/oauth_flow.php`, запускаемый через `make integration`; он покрывает OAuth/OIDC happy path, refresh token reuse, полный MFA enroll/activate/login/disable lifecycle, различение invalid MFA token/code, `/metrics`, Users/Roles/Groups/Audit/Identity Providers/LDAP API error contracts, RBAC и несколько RFC 6749 error contracts. JSON parser выровнен с Go struct decoder для malformed и non-object body: API возвращает компактный 400 `invalid json body`, сохраняя handler-specific порядок path/body validation. Auth/account/bootstrap, Users/Roles/Groups CRUD/assignments и OAuth/Identity/LDAP admin payloads используют allowlist полей, повторяя Go `DisallowUnknownFields`; Vue MFA flow переведён на `mfa_token` и канонические `/enroll`/`/activate`. Smoke flow расширен проверками OIDC Discovery/JWKS, RS256 `id_token` claims, одноразовости authorization code, introspection active/inactive token, revocation refresh token, `client_secret_basic`, client_credentials token contract, consent-required/grant сценария, malformed/non-object/unknown-field consent JSON, login_required/invalid_request authorize errors и invalid_client revoke/introspect errors. Users HTTP-контракт покрывает invalid path UUID для GET/PUT/DELETE, 404 отсутствующего пользователя и strict create/update payloads; Roles/Groups HTTP-контракты покрывают invalid path/body UUID и unknown fields на CRUD/assignment маршрутах; Identity Providers HTTP-контракт покрывает invalid path UUID, missing provider и unknown fields для POST/PUT; LDAP HTTP-контракт покрывает invalid provider UUID для PUT/DELETE/list/create mappings, missing provider, invalid/missing mapping ID и unknown provider/test/mapping fields; OAuth clients HTTP-контракт покрывает nested create response, Go zero-value/full-replacement semantics, invalid path UUID, missing clients и strict create/update payloads. Session API выровнен с Go-контрактом: список возвращает только активные неистёкшие сессии с `user_id`, невалидный path UUID даёт 400 `invalid session id`, точечный и полный отзыв проверяются HTTP smoke flow. Revoke-all немедленно инвалидирует first-party JWT, но сохраняет внешний OAuth access token; устранена секундная гонка немедленного повторного login через `iat` строго после `tokens_invalid_before`. Добавлен `tests/Integration/oauth_client.php`, запускаемый через `make integration-client`, для проверки Authorization Code + PKCE внешним RP-клиентом через Discovery, `client_secret_basic`, UserInfo, refresh token и RP logout. Добавлен `make integration-broker` с локальным mock OIDC provider для проверки external sign-in login/callback, JIT provisioning, federated identity linking, SSO cookie и broker token fragment. Добавлен optional `make integration-ldap` для проверки LDAP service bind, search + user bind, provisioning, group mapping и audit против реального LDAP test fixture через `SSO_TEST_LDAP_*`. Добавлена PHPUnit-инфраструктура `phpunit.xml.dist`, `tests/bootstrap.php` и `make unit`; unit-набор покрывает password policy, trusted-proxy IP resolution и Go-compatible JSON parsing без БД. Добавлен `make phpunit-integration`; PostgreSQL fixture-наборы покрывают `OAuthService` client CRUD и zero-value/full-replacement semantics, Authorization Code + PKCE token exchange, refresh token issuance, запрет повторного использования authorization code, refresh token rotation, reuse detection с отзывом family, revoke refresh token, OIDC ID token hint, JWKS, UserInfo scope filtering, RP-initiated logout, account password reset/email verification/profile validation, first-party/external token revocation semantics, MFA enroll/enable/challenge/recovery/disable и MFA lockout, RBAC role/group inheritance, identity provider CRUD/secret preservation, LDAP provider CRUD/group mappings, PostgreSQL array-поля, audit side effects, local login, brute-force reset, session management, Bearer JWT `currentUser`, logout revoke и revoke-all/relogin. Audit HTTP-проверки дополнительно покрывают строгий actor UUID, дробный RFC3339 и default/max pagination limits. Подготовлены `MIGRATION_NOTES.md` для cutover с Go-сервиса на PHP/Yii2. Полная сверка endpoint-контрактов с Go-сервисом и запуск LDAP smoke против реального LDAP test fixture ещё запланированы.
