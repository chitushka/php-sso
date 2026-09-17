# План переноса SSO

## Цель

Перенести функционал рабочего Go-сервиса `D:\workspace\GolandProjects\sso` в PHP 7.4 на Yii2, сохранив single-tenant модель, PostgreSQL-схему, публичные OAuth2/OIDC-контракты и административный UI.

## Этап 1. Инициализация проекта

- Создать Yii2-приложение с Docker-сборкой и PostgreSQL.
- Подключить конфигурацию через переменные `SSO_*`.
- Перенести актуальную схему БД из Go-миграций `000001-000010` в стартовую Yii2-миграцию.
- Сделать базовую Vue-админку на стандартном Yii2 layout и стандартном web root `web/`.

Статус: выполнено.

## Этап 2. Базовая авторизация

- Перенести bootstrap первого администратора.
- Реализовать локальный login/logout, cookie `sso_session`, Bearer JWT и отзыв пользовательских сессий.
- Сохранить политику паролей 12-128 символов.
- Добавить аудит ключевых действий.

Статус: выполнено. Brute-force lockout перенесён через таблицу `login_attempts`; rate limit перенесён через Yii2 cache-backed token bucket для bootstrap/auth/OAuth endpoint-ов.

## Этап 3. OAuth2 и OIDC

- Перенести CRUD OAuth-клиентов.
- Реализовать Authorization Code + PKCE, refresh token rotation, revocation, introspection.
- Поддержать OIDC Discovery, JWKS, RS256 ID Token и UserInfo.
- Сверить ответы ошибок с RFC 6749.

Статус: базовая реализация выполнена; refresh token rotation hardened против гонок и reuse: family сохраняется при rotation, а повторное/параллельное использование старого refresh token отзывает всю family. Public OAuth clients требуют PKCE S256. OIDC UserInfo фильтрует claims по scope, discovery содержит revocation/introspection metadata. RP-initiated logout валидирует `id_token_hint` и `post_logout_redirect_uri`, отзывает SSO-сессию и refresh tokens пользователя. Добавлен HTTP smoke flow для Authorization Code + PKCE, OIDC id_token/UserInfo/logout, refresh rotation/reuse detection, RBAC enforcement и ключевых RFC 6749 error contracts. Нужна дальнейшая точечная сверка оставшихся endpoint-контрактов с Go-сервисом.

## Этап 4. Административные модули

- Перенести Users, Roles, Permissions, Groups, Audit, LDAP providers.
- Включить RBAC middleware для всех admin API.
- Доработать Vue-страницы по аналогии с Go UI.

Статус: выполнено для базового admin-функционала. Перенесены RBAC-проверка прав, Users API, Roles API, Permissions API, Groups API, Audit API, LDAP providers API, LDAP test bind, LDAP group mappings, базовые Vue-вкладки и UI-формы назначения ролей пользователям, групп пользователям, permissions ролям и ролей группам. Users/Roles/Groups API дополнительно выровнены с Go-контрактом ошибок создания для обязательных полей и конфликтов уникального code/username; Users API валидирует path UUID для GET/PUT/DELETE и использует Go-границу pagination limit 100; Roles API валидирует role/permission UUID для CRUD и permission assignments с точными Go-ошибками; Audit API выровнен по валидации фильтров `actor`, `from`, `to`.

## Этап 5. Security hardening

- Перенести MFA TOTP, recovery codes, email verification, password reset/change.
- Перенести LDAP Search + Bind authentication при login.
- Перенести identity brokering для Google/GitHub/OIDC.
- Добавить trusted proxy IP, cleanup expired records и production-настройки shared rate limit для горизонтального масштабирования.

Статус: частично выполнено. LDAP Search + Bind authentication при login перенесён: включённые LDAP providers используются как fallback после локальной проверки пароля, LDAP-пользователь создаётся/обновляется в `users`, а LDAP group mappings синхронизируют группы с `source=ldap`. MFA TOTP и recovery codes перенесены: login выдаёт одноразовый MFA challenge с Go-compatible `mfa_token`, TOTP enrollment поддерживает маршруты `/api/v1/auth/mfa/enroll` и `/activate`, TOTP защищён от повторного counter, recovery codes одноразовые. MFA verification выровнен с Go security contract: неизвестный/использованный token и неверный код имеют разные 401-ошибки, неверные коды учитываются общим login/IP lockout, успешная проверка сбрасывает счётчик. Контракты повторного `activate` и `disable` также выровнены: точные статусы/ошибки, успешный ответ `mfa_disabled` и сохранение текущего Bearer JWT. Password reset/change и email verification перенесены через hashed one-time tokens; reset отзывает пользовательские сессии, refresh tokens и ранее выданные first-party Bearer JWT, тогда как OAuth access tokens внешних клиентов сохраняют Go-compatible срок жизни до `exp`. Identity brokering для Google/GitHub/OIDC перенесён: admin CRUD провайдеров, public список кнопок входа, signed state, callback, JIT-provisioning, безопасная линковка по verified email и Go-compatible ошибки создания identity providers. Production hardening частично перенесён: trusted proxy IP resolution, cleanup expired records, Go-compatible `/metrics` и опциональный DB-backed cache для rate limit/metrics. Расширенный account profile перенесён для self-service обновления first/last name и attributes. Следующими остаются интеграционные проверки совместимости.

## Этап 6. Проверка совместимости

- Добавить PHPUnit/integration tests против PostgreSQL.
- Сравнить контракты эндпоинтов с Go-сервисом.
- Проверить OAuth/OIDC flow внешним тестовым клиентом.
- Подготовить migration notes для перехода с Go-версии.

Статус: частично выполнено. Добавлен dependency-free HTTP smoke test `tests/Integration/oauth_flow.php`, запускаемый через `make integration`; он покрывает OAuth/OIDC happy path, refresh token reuse, полный MFA enroll/activate/login/disable lifecycle, различение invalid MFA token/code, `/metrics`, Users/Roles/Groups/Audit/Identity Providers API error contracts, RBAC и несколько RFC 6749 error contracts. Smoke flow расширен проверками OIDC Discovery/JWKS, RS256 `id_token` claims, одноразовости authorization code, introspection active/inactive token, revocation refresh token, `client_secret_basic`, client_credentials token contract, consent-required/grant сценария, login_required/invalid_request authorize errors и invalid_client revoke/introspect errors. Users HTTP-контракт покрывает invalid path UUID для GET/PUT/DELETE и 404 отсутствующего пользователя; Roles HTTP-контракт покрывает invalid role UUID на CRUD/permissions маршрутах и invalid permission UUID в path/body. Session API выровнен с Go-контрактом: список возвращает только активные неистёкшие сессии с `user_id`, невалидный path UUID даёт 400 `invalid session id`, точечный и полный отзыв проверяются HTTP smoke flow. Revoke-all немедленно инвалидирует first-party JWT, но сохраняет внешний OAuth access token; устранена секундная гонка немедленного повторного login через `iat` строго после `tokens_invalid_before`. Добавлен `tests/Integration/oauth_client.php`, запускаемый через `make integration-client`, для проверки Authorization Code + PKCE внешним RP-клиентом через Discovery, `client_secret_basic`, UserInfo, refresh token и RP logout. Добавлен `make integration-broker` с локальным mock OIDC provider для проверки external sign-in login/callback, JIT provisioning, federated identity linking, SSO cookie и broker token fragment. Добавлен optional `make integration-ldap` для проверки LDAP service bind, search + user bind, provisioning, group mapping и audit против реального LDAP test fixture через `SSO_TEST_LDAP_*`. Добавлена PHPUnit-инфраструктура `phpunit.xml.dist`, `tests/bootstrap.php` и `make unit`; первый unit-набор покрывает password policy и trusted-proxy IP resolution без БД. Добавлен `make phpunit-integration`; PostgreSQL fixture-наборы покрывают `OAuthService` client CRUD, Authorization Code + PKCE token exchange, refresh token issuance, запрет повторного использования authorization code, refresh token rotation, reuse detection с отзывом family, revoke refresh token, OIDC ID token hint, JWKS, UserInfo scope filtering, RP-initiated logout, account password reset/email verification/profile validation, first-party/external token revocation semantics, MFA enroll/enable/challenge/recovery/disable и MFA lockout, RBAC role/group inheritance, identity provider CRUD/secret preservation, LDAP provider CRUD/group mappings, PostgreSQL array-поля, audit side effects, local login, brute-force reset, session management, Bearer JWT `currentUser`, logout revoke и revoke-all/relogin. Подготовлены `MIGRATION_NOTES.md` для cutover с Go-сервиса на PHP/Yii2. Полная сверка endpoint-контрактов с Go-сервисом и запуск LDAP smoke против реального LDAP test fixture ещё запланированы.
