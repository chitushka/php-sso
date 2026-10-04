# Сторонние web-ресурсы

## Vue

Файл `web/js/vue.global.prod.js` — production-сборка Vue 3.5.43. Исходный артефакт:
`https://unpkg.com/vue@3.5.43/dist/vue.global.prod.js`.

Контрольная сумма SHA-256 хранится в `web/js/vue.global.prod.js.sha256` и проверяется
командой `make verify-assets` и CI. Обновление выполняется отдельным pull request:

1. выбрать опубликованную стабильную версию и изучить release notes;
2. скачать ровно `dist/vue.global.prod.js` из пакета выбранной версии;
3. обновить версию и источник в этом документе;
4. пересчитать SHA-256 и выполнить `make verify-assets`, `make unit` и UI smoke-тесты.

Подмена файла без одновременного обновления зафиксированного хэша блокирует merge.

