# Production Runbook (GoDaddy + Offline Tablets)

## Scope

This runbook confirms and operationalizes the deployment model where:

- The backend is hosted on GoDaddy shared PHP hosting.
- The database is MySQL (single source of truth).
- Field clients are tablets using the PWA both online and offline.

## Target Architecture

1. Web server: GoDaddy shared hosting serves `public/` as document root.
2. API + app shell: Same PHP app handles API routes and static PWA assets.
3. Data store: One MySQL database for all durable records.
4. Tablet mode:
- Online: writes go directly to API endpoints.
- Offline: writes queue in IndexedDB and sync later with idempotency.

## Security Model (Required)

1. Browser/tablet sessions use session auth + CSRF for state-changing requests.
2. API keys are for non-browser integrations only.
3. API key semantics:
- Missing/invalid key -> 401
- Insufficient scope -> 403
4. Production cookies must be secure:
- `cookie_secure = true`
- `cookie_httponly = true`
- `cookie_samesite = Lax` (or stricter if workflow allows)

## Pre-Deployment Checklist

1. DNS and TLS
- Domain points to GoDaddy host.
- HTTPS certificate valid and active.
- HTTP redirects to HTTPS.

2. File layout
- `public/` is web root.
- `app/`, `config/`, `database/`, `storage/` are not directly web-browsable.

3. Environment config
- Create `config/app.local.php` on server with production DB and mail settings.
- Set strong `security.encryption_key`.
- Set `app.debug = false` in production.

4. Database
- Apply all migrations in `database/migrations/` in order.
- Verify required schema updates exist before traffic cutover.

5. Writable paths
- Ensure `storage/uploads/` is writable by PHP process.
- Ensure log destinations are writable if enabled.

## Go-Live Validation

1. Authentication path
- GET `/api/auth/csrf` returns token and session cookie.
- Login succeeds for admin and technician accounts.
- Logout invalidates session.

2. CSRF behavior
- State-changing requests without CSRF fail with 403.
- State-changing requests with valid CSRF succeed.

3. API key behavior
- Missing key returns 401 on key-protected routes.
- Wrong scope returns 403.
- Correct scope is accepted.

4. Offline sync behavior
- Submit queued item once -> `success`.
- Replay same idempotency key -> `duplicate`.
- Confirm no duplicate records created.

5. Audit/transaction logs
- Login/logout and write actions appear in audit logs.
- Request activity appears in transaction logs.

## Tablet Operational Method

1. Install PWA on each tablet from the production URL.
2. Verify service worker is active and shell loads with weak/no signal.
3. Validate offline submission queue and automatic sync on reconnect.
4. Train users on expected behavior:
- Offline submissions are queued locally.
- Sync occurs when connectivity returns.
- Failed sync items should be visible and retryable.

## Day-2 Operations

1. Daily checks
- Error log rate.
- Sync failure/duplicate rates.
- 401/403 trend anomalies.

2. Weekly checks
- Storage usage for uploads/logs.
- Backup restore test sample.
- Spot-check audit completeness.

3. Incident triage order
- Confirm deployment version and migration level.
- Check DB connectivity.
- Check session/CSRF path (`/api/auth/csrf`, login, write endpoint).
- Check sync queue behavior from tablet repro.

## Rollback Strategy

1. Keep previous release bundle available for quick file rollback.
2. Use backward-compatible migrations when possible.
3. If rollback needs schema reversal, stop writes first, backup DB, then revert.

## Canonical Local Verification Command

Run before release and after production cutover:

```powershell
.\validate-backend.ps1 -BaseUrl "https://your-production-domain"
```

If smoke cannot run directly against production, run it against an identical staging environment immediately before deployment.
