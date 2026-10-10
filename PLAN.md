# План развития SSO PHP

## Правила ведения

- Это единственный активный backlog; завершённые исторические этапы здесь не хранятся.
- Порядок внутри приоритета задаёт рекомендуемую последовательность.
- Пункт закрывается после кода, миграции при необходимости, автоматических тестов и обновления документации.
- Новое внешнее поведение фиксируется в HTTP/API-контракте `ARCHITECTURE.md` и contract/integration tests.

## P2 — архитектура и поддерживаемость

### 1. Формализовать API и продуктовые сценарии

- Создать OpenAPI 3.1 для административного/account API и отдельное описание OAuth/OIDC endpoints; генерировать contract checks.
- Унифицировать JSON error envelope, HTTP statuses для not-found/validation/conflict и pagination metadata.
- Добавить в UI управление активными consent, federated identity links, signing keys и operational status.
- Провести accessibility, keyboard navigation и responsive review Vue admin; разделить монолитный `views/admin/index.php` на модули.

Критерий готовности: внешний контракт машиночитаем, UI покрывает доступные операции и не дублирует бизнес-правила API.
