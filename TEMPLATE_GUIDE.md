# Starter Template Guide

This codebase can be used as a starter for new PHP + MySQL applications that need:

- central bootstrap ownership
- session auth + CSRF for browser users
- scoped API-key auth for device or server integrations
- request logging and audit logging
- offline sync or retry-safe write workflows
- an executable backend smoke gate

## Reuse As-Is First

These files are generic platform pieces and should usually be copied forward with minimal changes:

- `app/bootstrap.php`
- `app/Database/Database.php`
- `app/Models/Model.php`
- `app/Controllers/Controller.php`
- `app/Middleware/AuthMiddleware.php`
- `app/Middleware/ApiKeyMiddleware.php`
- `app/Helpers/csrf_helper.php`
- `app/Helpers/debug_helper.php`
- `app/Helpers/security_helper.php`
- `app/Services/TransactionLogger.php`
- `config/app.php`
- `config/app.local.example.php`
- `validate-backend.ps1`
- `dev-server.ps1`
- `.vscode/tasks.json`
- `tests/backend_smoke.php`
- `lessons_learned.md`
- `task_module_roadmap.md`
- `WORKLOG.md`

## Customize Early

These areas are intentionally domain-specific and should be changed early in a new app:

- `database/migrations/*.sql`
- entity models under `app/Models/`
- business controllers under `app/Controllers/`
- route registration in `public/index.php`
- frontend assets in `frontend/` and `public/`
- smoke fixtures and expected flows in `tests/backend_smoke.php`
- project naming and deployment guidance in `README.md`

## New Project Checklist

1. Copy this starter into the new project folder.
2. Run `./bootstrap-starter.ps1 -AppName "Your App" -AppSlug "your_app" -AdminEmail "owner@example.com" -AdminPassword "StrongPassword123!" -Preview` to inspect the starter placeholder changes.
3. Re-run `./bootstrap-starter.ps1` without `-Preview` to apply the obvious renames.
4. Copy `config/app.local.example.php` to `config/app.local.php` and fill in local secrets.
5. Replace or rewrite `database/migrations/001_initial_schema.sql` and any later migrations for the new domain.
6. Keep the generic bootstrap, middleware, helpers, logger, and validation gate unless the new app has a simpler stack.
7. Replace the domain models, controllers, and the `smokeTemplateProfile()` values in `tests/backend_smoke.php` with the new app's entities and workflows.
8. Run `./setup-local-db.ps1` with the new admin values if local bootstrap seeding is still desired.
9. Run `./validate-backend.ps1` and do not start feature work until the baseline passes.
10. Make the first clean commit only after placeholder renames, local validation, and confirmation that private config and logs are still ignored.

## Keep These Standards

- One bootstrap owner for session and config initialization.
- One backend validation entrypoint at repo root.
- Audit assertions for compliance-sensitive writes.
- Normalization at the controller or sync boundary before validation and insert.
- Documentation updates in `WORKLOG.md` and `lessons_learned.md` after substantial changes.

## Do Not Reuse Blindly

Do not carry forward these values without deliberate replacement:

- table names and foreign keys
- hardcoded demo business names
- domain-specific audit entity names
- route names and request payload shapes
- smoke test fixtures tied to this service workflow

## Recommended Extraction Sequence

1. Keep the infrastructure layer.
2. Replace the schema.
3. Replace the domain models and controllers.
4. Replace the smoke fixtures and expected business assertions.
5. Re-run the backend gate until the new domain baseline is clean.