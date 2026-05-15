# Lessons Learned

## Purpose

Use this file to capture reusable engineering rules, mistakes, and verified debugging patterns that should influence future implementation work in this starter and any app derived from it.

## Architecture Rules

- Keep session configuration and `session_start()` in exactly one bootstrap layer.
- In this starter, `app/bootstrap.php` owns session startup and entrypoints such as `public/index.php` must not call `session_set_cookie_params()`, `session_name()`, or `session_start()` again.
- Treat session setup like database bootstrap: centralize it once, then consume the initialized state downstream.
- Keep browser auth and non-browser auth separate by design: use session + CSRF for frontend users, and scoped API keys only for device/server integrations. Do not embed API keys in browser JavaScript.
- Centralize transaction logging in one service instead of scattering controller-specific log formats. Request logging, validation failures, security events, and audit actions should all flow through the same layer so they can be searched and compared consistently.

## Data Rules

- Add new data-handling rules here when schema changes, validation behavior, or sync ownership creates a repeatable implementation constraint.
- In the DB wrapper, successful non-SELECT prepared statements must return a truthy value instead of `get_result()`. `mysqli_stmt::get_result()` is only valid for result-producing queries; using it for INSERT/UPDATE makes successful writes look like failures.
- When API payload names differ from schema column names, normalize them in the controller before validation and insert. Accepting `service_visit_id` while persisting `visit_id` is fine, but silently passing the wrong key into the model drops the required foreign key and breaks writes.
- Keep controller helper calls and model method signatures aligned. If the controller passes pagination arguments but the model helper only accepts a single parameter, the endpoint becomes a latent runtime failure even when the underlying query logic is correct.
- Do not assume optional PHP extensions are present in local or hosted environments. File-upload paths should detect MIME type through a fallback chain such as `mime_content_type()` -> `finfo` -> extension map, or uploads will fail only after integration testing.
- Do not implement soft-delete or lifecycle fields in controllers unless the schema actually has those columns. Writing through `deleted_at` or `completed_at` when the table does not support them creates endpoints that look complete in code review but fail only at runtime.
- Functional smoke coverage is not enough for compliance-sensitive flows. When a controller mutates persisted state, add executable assertions for the corresponding audit-log rows or logging regressions will pass unnoticed.
- Model validation should enforce schema-required fields before insert/update. If a NOT NULL column is treated as optional in validation, malformed API payloads bypass `422` handling and degrade into avoidable `500`-class database failures.
- Offline sync handlers need the same normalization rules as direct controllers. If queue payload aliases like `service_visit_id`, `issue`, `notes`, or `file_name` are not mapped before validation/insert, offline-only failures slip past endpoint coverage and surface as vague sync errors.
- If direct writes are audit-logged, sync-created equivalents need the same audit events and executable assertions. Otherwise the offline path becomes a compliance blind spot even when the online endpoints are fully covered.

## Security Rules

- Session initialization errors can affect auth and CSRF behavior indirectly, so bootstrap ownership is a security concern, not just a cleanup issue.
- Preserve a single request bootstrap path for session and middleware initialization before expanding state-changing endpoints.
- If browser warnings reference code that no longer exists on disk, treat stale PHP server state or opcode cache as a first-class debugging branch before editing more files.
- When including local config overrides, load them in isolated scope. A local file that assigns to `$config` can overwrite the bootstrap variable before merge, silently dropping security defaults like headers, timeouts, or cookie settings.
- API keys should be stored hashed, scoped, and revocable. Plaintext keys should exist only at creation time.
- Validate every authenticated session on every protected request with both an idle timeout and a fingerprint check. If validation fails, destroy the session and log the reason.
- Idempotency keys are not a CSRF replacement. Session-authenticated browser routes still need CSRF even when they support duplicate suppression or offline retry semantics.
- If a session cookie option is present in config, apply it in the actual `session_set_cookie_params()` call. Declared settings that never reach PHP runtime are a false security signal.
- Session fingerprinting should be configurable by deployment risk, not hard-coded. For field/mobile workflows, a user-agent plus IP-prefix strategy is usually safer than binding the full IP address.

## Debugging Patterns

- When PHP reports that a session is already active, inspect the earliest required bootstrap file first before changing the entrypoint.
- Duplicate session initialization causes runtime warnings like "Session cookie parameters cannot be changed when a session is active" and usually means ownership of request bootstrap concerns has drifted.
- In derived apps, `app/bootstrap.php` should remain the controlling code path for session startup unless the bootstrap architecture is deliberately replaced end to end.
- If direct CLI execution of `public/index.php` does not reproduce a browser warning, the current source and the served runtime are out of sync; restart the active PHP/web server before assuming the code fix failed.
- `config/app.local.php` must begin with the exact `<?php` bytes and no UTF-8 BOM. A BOM in local config emits output before headers and causes redirect/header failures that can distract from the main bug.
- If the app shell renders but placeholders like "Loading..." or "Checking connection..." remain, treat PHP bootstrap as likely healthy enough to serve the page and move debugging to frontend boot logic, browser console errors, auth/user fetches, service worker state, and API connectivity.
- If authenticated reads work but writes return a generic 500 with no validation errors, inspect the DB abstraction before the controller. A write-path bug in the wrapper can surface as controller-level failure even when the payload and schema are aligned.
- For backend investigation, check both the structured file log (`transaction_log.txt`) and the DB-backed `transaction_logs` table. File logs help even when a migration is missing; DB logs are better for filtering and trend review.
- When request-scoped validation logs show duplicate entries or null request IDs, inspect lifecycle order first. Starting request logging after auth/session checks will fragment observability and hide the controlling flow.
- If DB-backed observability is expected in production, gate it by config rather than probing table existence on every request. Hot-path schema checks add cost to every call and do not improve normal-case behavior.
- Bound logged payload size before persisting request bodies. Redaction alone is not enough when sync queues or nested payloads can grow large enough to swamp file and DB logging.

## UI/UX Rules

- Add UI and workflow lessons here when field behavior, offline flow, or operator guidance reveals a repeatable pattern worth enforcing.

## Review Rule

- Read this file before starting a new feature or module.
- Update this file when a bug fix reveals a reusable starter-level rule. If a lesson is domain-specific to a derived app, move it into that app's own documentation instead of preserving it here by default.
- Keep one executable backend smoke entrypoint in the repo root for local validation. If a regression suite requires multiple manual commands, it will be skipped in normal development.