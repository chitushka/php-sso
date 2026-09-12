# План переноса SSO

## Цель

Перенести функционал рабочего Go-сервиса `D:\workspace\GolandProjects\sso` в PHP 7.4 на Yii2, сохранив single-tenant модель, PostgreSQL-схему, публичные OAuth2/OIDC-контракты и административный UI.

## Этап 1. Инициализация проекта

- Создать Yii2-приложение с Docker-сборкой и PostgreSQL.
- Подключить конфигурацию через переменные `SSO_*`.
- Перенести актуальную схему БД из Go-миграций `000001-000010` в стартовую Yii2-миграцию.
- Сделать базовую Vue-админку на стандартном Yii2 layout.

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

Статус: базовая реализация выполнена; нужны интеграционные тесты и hardening гонок refresh token.

## Этап 4. Административные модули

- Перенести Users, Roles, Permissions, Groups, Audit, LDAP providers.
- Включить RBAC middleware для всех admin API.
- Доработать Vue-страницы по аналогии с Go UI.

Статус: выполнено для базового admin-функционала. Перенесены RBAC-проверка прав, Users API, Roles API, Permissions API, Groups API, Audit API, LDAP providers API, LDAP test bind, LDAP group mappings, базовые Vue-вкладки и UI-формы назначения ролей пользователям, групп пользователям, permissions ролям и ролей группам.

## Этап 5. Security hardening

- Перенести MFA TOTP, recovery codes, email verification, password reset/change.
- Перенести LDAP Search + Bind authentication при login.
- Перенести identity brokering для Google/GitHub/OIDC.
- Добавить trusted proxy IP, cleanup expired records и production-настройки shared rate limit для горизонтального масштабирования.

Статус: частично выполнено. LDAP Search + Bind authentication при login перенесён: включённые LDAP providers используются как fallback после локальной проверки пароля, LDAP-пользователь создаётся/обновляется в `users`, а LDAP group mappings синхронизируют группы с `source=ldap`. MFA TOTP и recovery codes перенесены: login выдаёт одноразовый MFA challenge, TOTP защищён от повторного counter, recovery codes одноразовые. Password reset/change и email verification перенесены через hashed one-time tokens; reset отзывает пользовательские сессии, refresh tokens и ранее выданные Bearer JWT. Identity brokering и production hardening остаются следующими подпунктами.

## Этап 6. Проверка совместимости

- Добавить PHPUnit/integration tests против PostgreSQL.
- Сравнить контракты эндпоинтов с Go-сервисом.
- Проверить OAuth/OIDC flow внешним тестовым клиентом.
- Подготовить migration notes для перехода с Go-версии.

Статус: запланировано.
