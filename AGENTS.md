# Repository Guidelines

## Project Goal

This repository is an independent single-tenant SSO/Identity Provider implemented in PHP 8.4 on Yii2. Its goal is to provide secure local, LDAP and federated authentication, OAuth 2.0/OpenID Connect, session management, MFA, RBAC, audit and an administrative UI.

Treat the PHP application, its documented API contracts and applicable standards as the only sources of truth. Do not use, inspect or compare against another language implementation when designing, implementing, reviewing or testing changes. Historical migration compatibility is not a product requirement.

## Project Structure & Module Organization

Keep controllers in `controllers/`, business logic in `services/`, ActiveRecord models in `models/`, Yii config in `config/`, migrations in `migrations/`, web entry points in `web/`, and Vue-backed admin views in `views/admin/`.

## Build, Test, and Development Commands

Use Docker for all project checks and development runs. The host machine is not expected to have local PHP or Composer installed.

```bash
docker compose build
docker compose up
docker compose --profile operations run --rm migrate
docker compose run --rm --no-deps app composer lint
docker compose run --rm --no-deps app vendor/bin/phpunit --testsuite Unit
docker compose run --rm app sh -lc "php yii migrate --interactive=0 && vendor/bin/phpunit --testsuite Integration"
```

Use an explicit non-production test configuration for automated tests. Production fail-closed checks must never be weakened to make local tests pass.

## Coding Style & Naming Conventions

Use PSR-12 formatting and 4-space indentation for PHP. Name classes in `PascalCase`, methods and variables in `camelCase`, constants in `UPPER_SNAKE_CASE`, and test classes with a `Test` suffix. Namespaces mirror repository paths under the `app\` namespace.

Keep authentication, token, session and provider-specific code separated so security-sensitive flows remain auditable. Write code comments in Russian. Prefer Yii2 core features and avoid new dependencies unless the active plan justifies them.

## Testing Guidelines

Put unit tests under `tests/Unit/` and database or HTTP tests under `tests/Integration/`. Every security or functional change must cover success, validation failure, authorization failure, replay/concurrency where relevant, and rollback behavior for transactional changes.

Test behavior against the repository's own API contract and OAuth/OIDC standards. Do not encode behavior solely to match a historical implementation.

## Documentation and Planning

`PLAN.md` is the single active backlog and must contain only incomplete or explicitly deferred work. Update it when a finding is fixed or invalidated. Keep `ARCHITECTURE.md` and `README.md` aligned with every important functional change. `README.md` owns setup, configuration and operational runbooks; `ARCHITECTURE.md` owns stable system boundaries, the security model and the documented HTTP/API contract. Keep root documentation limited to `README.md`, `PLAN.md`, `AGENTS.md` and `ARCHITECTURE.md`.

## Security & Configuration

Never commit client secrets, private keys, signing certificates, session secrets or production `.env` files. Document settings in `.env.example`. Validate redirect targets, state and nonce values, keep secrets out of logs, and preserve fail-closed production startup.

## Commits and Pull Requests

Use short imperative commit messages. Pull requests should include a summary, tests performed, linked task when available, and screenshots only for UI changes. Call out configuration, schema and security changes explicitly.
