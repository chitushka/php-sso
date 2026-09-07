# Repository Guidelines

## Project Structure & Module Organization

This repository is a PHP 7.4 Yii2 port of the Go SSO service in `D:\workspace\GolandProjects\sso`. Keep controllers in `controllers/`, business logic in `services/`, ActiveRecord models in `models/`, Yii config in `config/`, migrations in `migrations/`, web entry points in `public/`, and Vue-backed admin views in `views/admin/`.

Current layout:

```text
controllers/
services/
models/
migrations/
public/
config/
tests/
vendor/
```

## Build, Test, and Development Commands

```bash
composer install
php yii migrate --interactive=0
composer lint
docker compose up
```

`composer install` installs Yii2. `php yii migrate --interactive=0` applies the PostgreSQL schema. `composer lint` checks PHP syntax. `docker compose up` starts the app and PostgreSQL.

## Coding Style & Naming Conventions

Use PSR-12 formatting and 4-space indentation for PHP. Name classes in `PascalCase`, methods and variables in `camelCase`, constants in `UPPER_SNAKE_CASE`, and test classes with a `Test` suffix. Organize namespaces to mirror `src/` paths, for example `App\Auth\TokenValidator` in `src/Auth/TokenValidator.php`.

Keep authentication, token, session, and provider-specific code separated by namespace so SSO flows remain easy to audit.

## Testing Guidelines

Use PHPUnit once test dependencies are added. Put unit tests under `tests/Unit/` and integration or HTTP flow tests under `tests/Integration/`. Test files should match the class or behavior under test, for example `TokenValidatorTest.php`.

Add tests for login redirects, callback handling, token validation, session creation, logout, and error cases such as expired state values or invalid signatures.

## Commit & Pull Request Guidelines

No Git history is available in this workspace, so use short imperative commit messages, such as `Add token validation tests` or `Fix OAuth callback error handling`.

Pull requests should include a brief summary, testing performed, linked issue or task reference when available, and screenshots only for UI-facing changes. Call out any configuration, migration, or security-sensitive changes explicitly.

## Security & Configuration Tips

Never commit client secrets, private keys, signing certificates, session secrets, or production `.env` files. Document required SSO settings in `.env.example`, including redirect URIs, issuer IDs, client IDs, and certificate paths. Validate all redirect targets and state parameters.

## Agent-Specific Instructions

Maintain `PLAN.md`, `ARCHITECTURE.md`, and `README.md` with every important functional change. Write code comments in Russian. Prefer Yii2 core features and avoid adding external dependencies unless the migration plan explicitly requires them.
