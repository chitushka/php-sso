FROM nginxinc/nginx-unprivileged:1.27-alpine-slim
COPY docker/nginx/app.conf /etc/nginx/conf.d/default.conf
COPY --chown=101:101 web /app/web
