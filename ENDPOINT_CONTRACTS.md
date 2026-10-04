# HTTP-контракты SSO PHP

Этот документ — нормативное краткое описание публичных маршрутов проекта. Фактические поля запросов и ответов дополнительно закреплены tests. Машиночитаемый OpenAPI 3.1 контракт ещё предстоит добавить по `PLAN.md`.

## Общие правила

- API использует JSON, если endpoint явно не является OAuth form endpoint или redirect.
- Неизвестные поля типизированного JSON body отклоняются с HTTP 400.
- Невалидный JSON/object type отклоняется с HTTP 400.
- Административные маршруты требуют Bearer token, соответствующий scope и RBAC permission.
- UUID path/body проверяются до обращения к БД.
- Timestamps наружу передаются в RFC3339 UTC.
- Неизвестные API/OAuth/Well-Known маршруты возвращают JSON 404.
- OAuth errors имеют поля `error` и `error_description`.
- Cookie-authenticated POST/PUT/PATCH/DELETE на защищённых auth/OAuth маршрутах требуют совпадающие `sso_csrf` cookie и `X-CSRF-Token`; Bearer-аутентифицированные запросы от этой проверки освобождены.
- Auth/token responses возвращаются с `Cache-Control: no-store`.
- Каждый HTTP-ответ содержит `X-Request-ID` для корреляции с JSON-логами.

## Public и health

| Метод | Маршрут | Назначение |
|---|---|---|
| GET | `/health/live` | Liveness процесса |
| GET | `/health/ready` | Readiness PostgreSQL, encryption и OIDC signing |
| GET | `/health/version` | Версия приложения |
| GET | `/metrics` | Prometheus metrics; CIDR allowlist или Bearer authentication |
| GET | `/api/v1/bootstrap/status` | Состояние первичной настройки |
| POST | `/api/v1/bootstrap` | Создание первого администратора |
| GET | `/api/v1/broker/providers` | Доступные внешние провайдеры |

## Authentication и account

| Метод | Маршрут | Назначение |
|---|---|---|
| POST | `/api/v1/auth/login` | Первый фактор и создание login result/MFA challenge |
| POST | `/api/v1/auth/mfa/verify` | Завершение MFA login |
| POST | `/api/v1/auth/logout` | Отзыв текущей сессии/token |
| GET | `/api/v1/auth/me` | Текущий пользователь |
| GET/DELETE | `/api/v1/auth/sessions` | Список/отзыв всех сессий |
| DELETE | `/api/v1/auth/sessions/{id}` | Отзыв одной своей сессии |
| POST | `/api/v1/auth/password/forgot` | Запрос password reset |
| POST | `/api/v1/auth/password/reset` | Сброс пароля по token |
| POST | `/api/v1/auth/password/change` | Смена локального пароля |
| PUT | `/api/v1/auth/profile` | Изменение собственного профиля |
| POST | `/api/v1/auth/email/request` | Отправка email verification |
| POST | `/api/v1/auth/email/verify` | Подтверждение email |
| POST | `/api/v1/auth/mfa/enroll` | Начало TOTP enrollment |
| POST | `/api/v1/auth/mfa/activate` | Активация TOTP |
| POST | `/api/v1/auth/mfa/disable` | Отключение MFA |
| POST | `/api/v1/auth/mfa/recovery-codes` | Перегенерация recovery codes |
| GET | `/api/v1/auth/consents` | Список выданных текущим пользователем OAuth consent |
| DELETE | `/api/v1/auth/consents/{clientId}` | Отзыв consent и refresh tokens клиента |

Маршруты `/mfa/setup` и `/mfa/enable` пока являются deprecated aliases. Их удаление требует объявленного deprecation window.

## OAuth 2.0 и OpenID Connect

| Метод | Маршрут | Назначение |
|---|---|---|
| GET | `/oauth2/authorize` | Authorization Code request |
| POST | `/oauth2/token` | Code, refresh и client credentials grants |
| POST | `/oauth2/revoke` | Token revocation |
| POST | `/oauth2/introspect` | Token introspection |
| GET/POST | `/oauth2/consent` | Просмотр/выдача consent |
| GET/POST | `/oauth2/logout` | RP-initiated logout |
| GET | `/oauth2/userinfo` | OIDC UserInfo |
| GET | `/.well-known/openid-configuration` | Discovery |
| GET | `/.well-known/jwks.json` | JWKS |
| GET | `/oauth2/broker/{code}/login` | Начало federated login |
| GET | `/oauth2/broker/{code}/callback` | Callback провайдера |

Authorization endpoint поддерживает только `response_type=code`. `state` и PKCE S256 обязательны для всех clients; scope `openid` также требует `nonce`. Confidential clients используют ровно один способ client authentication (Basic или body credentials), public clients не передают secret. Redirect/post-logout URI требуют HTTPS и точного совпадения с регистрацией. Public client может явно включить `allow_loopback_redirects`; тогда HTTP разрешён только для loopback host, а authorization request может выбрать другой port при неизменных host/path/query. URI с credentials, fragment, backslash или управляющими символами запрещены.

Authorization error перенаправляется только после проверки client и redirect URI и содержит `error`, `error_description`, исходный `state` и issuer `iss`. Успешный authorization response содержит `code`, `state` и `iss`. GET logout требует валидный `id_token_hint`, связанный с текущей активной browser session; обычный cookie-only cross-site GET logout не выполняется. Полный профиль безопасности находится в `SECURITY.md`.

Успешная выдача session, authorization code, refresh token, MFA challenge/recovery code или account one-time token означает, что артефакт уже зафиксирован в PostgreSQL. Если use case предусматривает обязательный audit, изменение состояния и audit фиксируются одной транзакцией. Ошибка любой из этих записей возвращает HTTP 500 либо OAuth `server_error`, не раскрывая несохранённый token. Password reset, MFA challenge, authorization code и refresh rotation имеют exactly-once consumption: из конкурентных запросов только один может успешно использовать одноразовый артефакт. Logout и revoke-all идемпотентны.

## Admin API

- Users: `GET/POST /api/v1/users`, `GET/PUT/DELETE /api/v1/users/{id}`.
- User roles/groups: `/api/v1/users/{userID}/roles`, `/api/v1/users/{userID}/groups`.
- Roles and permissions: `/api/v1/roles`, `/api/v1/roles/{roleID}`, `/permissions`, `/api/v1/permissions`.
- Groups and roles: `/api/v1/groups`, `/api/v1/groups/{groupID}`, `/roles`.
- OAuth clients: `/api/v1/oauth/clients`, item CRUD и `/rotate-secret`.
- Identity providers: `/api/v1/identity-providers` и item update/delete.
- LDAP providers: `/api/v1/ldap/providers`, `/test`, item group mappings.
- Audit: `GET /api/v1/audit` с action/actor/time/pagination filters.

Secret values никогда не возвращаются после первоначального создания/ротации. Delete пользователя является soft delete. Изменение security status должно отзывать sessions, refresh tokens и ранее выданные user access tokens.

Email сохраняется в lowercase без внешних пробелов и уникален без учёта регистра. При password reset точное совпадение username имеет приоритет над поиском нормализованного email. Смена email сбрасывает `email_verified`, делает старые verification tokens недействительными и отзывает credentials. Конфликт username/email возвращает 409. Операция, оставляющая систему без активного администратора, возвращает 409 `at least one active administrator is required`.

## Ошибки и совместимость

Стабильность публичного контракта обеспечивается SemVer/deprecation policy проекта. Новые клиенты не должны зависеть от недокументированных полей, порядка JSON keys или текста внутренних исключений. Изменения статусов, обязательных полей и token semantics требуют contract tests и записи в changelog.
