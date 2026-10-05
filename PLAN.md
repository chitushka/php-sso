# План развития SSO PHP

## Цель проекта

Развивать самостоятельный single-tenant SSO/Identity Provider на PHP 8.4 и Yii2. Сервис должен безопасно предоставлять локальную, LDAP- и федеративную аутентификацию, OAuth 2.0/OpenID Connect, пользовательские сессии, MFA, RBAC, аудит и административный интерфейс.

Источники требований: документация этого репозитория, принятые продуктовые решения и применимые стандарты. Другая реализация сервиса не является эталоном, зависимостью или критерием приёмки. Сравнительные проверки и сохранение исторических особенностей не входят в дальнейшие доработки.

## Правила ведения плана

- Это единый активный backlog. Завершённые исторические этапы здесь не хранятся.
- Порядок внутри приоритета задаёт рекомендуемую последовательность.
- Пункт закрывается только после кода, миграции при необходимости, автоматических тестов и обновления документации.
- Новое поведение сначала фиксируется в `ENDPOINT_CONTRACTS.md` и тестах как собственный контракт проекта.

## P2 — архитектура и поддерживаемость

### 5. Отвязать код и терминологию от истории переноса

- Переименовать `GoJson`, `parseGoInt`, сообщения/комментарии и тесты в термины собственного строгого JSON/API-контракта.
- Заменить `cutover/*`, `Cutover*`, `SSO_CUTOVER_*` и rollback-названия на нейтральные deployment/readiness/backup команды там, где функция всё ещё нужна.
- Удалить compatibility-only aliases и форматы после явного deprecation window; сохранить только реально используемый публичный API.
- Заменить версию `0.1.0-port` на самостоятельную SemVer-версию и вести changelog релизов.

Критерий готовности: runtime, тесты и документация не требуют знания другой реализации.

### 6. Декомпозировать сервисы и формализовать ошибки

- Разделить крупные `OAuthService`, `BrokerService`, `LdapService`, `AuthService` и `RbacService` по use cases.
- Внедрять зависимости через конструкторы вместо массового `new` и статического `Yii::$app`, чтобы тесты не требовали глобального состояния.
- Ввести типизированные DTO/результаты и единый error mapper вместо массивов `status/data/error`.
- Убрать контроллерную бизнес-логику из `UsersController`, стандартизировать транзакции и clock/random abstractions.

Критерий готовности: security use cases тестируются изолированно, ошибки и транзакционные границы единообразны.

### 7. Усилить модель и миграции БД

- Убрать `IF NOT EXISTS` из baseline-подхода новых установок либо добавить строгую schema drift verification: миграция не должна успешно отмечаться поверх несовместимой таблицы.
- Дополнить CHECK/FK/UNIQUE для purpose/source/type/alg и других enum/invariant полей; проверить индексы реальными `EXPLAIN` на cleanup и admin queries.
- Исправить `ValidatedActiveRecord`: документация обещает required/schema rules, но текущая реализация в основном добавляет типы и не отражает `NOT NULL` полноценно.
- Добавить pagination/filtering для всех потенциально неограниченных admin lists (clients, roles, groups, providers, permissions).
- Определить политику хранения actor после удаления пользователя и целостность audit log.

Критерий готовности: схема отклоняет невозможные состояния, drift обнаруживается до запуска приложения, списки имеют стабильные лимиты.

### 8. Формализовать API и продуктовые сценарии

- Создать OpenAPI 3.1 документ для административного/account API и отдельное описание OAuth/OIDC endpoints; генерировать contract checks.
- Унифицировать JSON error envelope, HTTP status для not-found/validation/conflict и pagination metadata.
- Добавить управление активными consent, federated identity links, signing keys и operational status в UI.
- Провести accessibility, keyboard navigation и responsive review Vue admin; разделить монолитный `views/admin/index.php` на поддерживаемые модули.

Критерий готовности: внешний контракт машиночитаем, UI покрывает доступные операции и не дублирует бизнес-правила API.

## Подтверждённое состояние на 2026-10-05

- PHP syntax check: успешно.
- PHPUnit Unit: 59 тестов, 119 assertions — успешно.
- PHPUnit Integration в явно заданном test environment: 71 тест, 665 assertions — успешно.
- HTTP/OIDC broker/client и LDAP smoke flows: успешно.
- Quality gate (PSR-12, PHPStan, assets/docs/secret checks): успешно.
- Миграции на чистой БД, upgrade snapshot и rollback обратимых миграций: успешно.
- Security-critical line coverage: 72,27% при пороге 35%.
- Production image запускается как `www-data` и не содержит тесты, Composer metadata или PHPUnit.
- SPDX SBOM production image сформирован и содержит 188 пакетов.
- Trivy 0.67.2: 0 исправимых HIGH/CRITICAL findings в Debian, Composer и Node слоях production image.
- Remote GitHub Actions quality gate для commit `15f7eaf` завершён успешно; `composer audit --locked`, SPDX SBOM и Trivy scan прошли.
- OpenAPI schema пока отсутствует и учтена в пункте 8.
