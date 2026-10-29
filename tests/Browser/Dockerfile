FROM mcr.microsoft.com/playwright:v1.64.1-noble

WORKDIR /tests
COPY tests/Browser/package.json tests/Browser/package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY tests/Browser/playwright.config.js tests/Browser/admin.spec.js tests/Browser/build-admin-render.js ./
COPY views/admin/index.php /workspace/views/admin/index.php
COPY web/js/admin-render.js /workspace/web/js/admin-render.js
RUN node build-admin-render.js /workspace --check

CMD ["npx", "playwright", "test"]
