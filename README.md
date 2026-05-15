# Water Treatment Service App

A tablet-first, offline-capable Progressive Web App for field technicians to perform onsite service inspections and maintenance on water treatment equipment.

**Stack**: PHP 8.x + MySQL + PWA (vanilla JS + service worker)  
**Hosting**: GoDaddy shared hosting (PHP + MySQL support required)

## Project Structure

```
public/                 # Web root (serve from this directory)
  ├── index.php         # Router and entrypoint
  ├── app.html          # PWA shell
  ├── manifest.json     # Web app manifest
  └── css/, js/         # Frontend assets

app/
  ├── Controllers/      # Request handlers
  ├── Models/           # Data access layer
  ├── Services/         # Business logic (sync, reports, media)
  ├── Middleware/       # Auth, CSRF protection
  └── Helpers/          # Utility functions

database/
  ├── migrations/       # SQL schema and migrations
  └── backups/          # Local database backups

config/
  ├── app.php           # Main configuration
  └── app.local.php     # (NOT committed) Environment-specific overrides

frontend/
  ├── js/
  │   ├── sw.js         # Service worker
  │   ├── sync-queue.js # Offline sync manager
  │   └── visit-form.js # Main form workflow
  └── css/
      └── tablet-ui.css # Touch-optimized styles

storage/
  └── uploads/          # User-generated media (photos, documents)

tests/                  # Integration and unit tests
```

## Getting Started

### 1. Deploy to GoDaddy Hosting

1. **Create a GoDaddy account** with PHP + MySQL support (or upgrade existing plan).
2. **Upload files** via FTP or cPanel File Manager:
   - Map the `/public` directory as your domain's public root.
   - Ensure `config/`, `app/`, `database/`, `storage/` are outside the web root (one level up).
3. **Create MySQL database**:
   - In cPanel, create a new database and user.
   - Note the connection credentials (host, user, password, database name).

### 2. Configure Environment

1. Create `config/app.local.php`:
   ```php
   <?php
   return [
       'db' => [
           'host'     => 'your-godaddy-mysql-host',
           'user'     => 'db_user',
           'password' => 'db_password',
           'database' => 'db_name',
       ],
       'mail' => [
           'host' => 'mail.your-domain.com',
           'port' => 587,
           'from' => 'service@your-domain.com',
       ],
   ];
   ```

2. **Run database migrations**:
   - In cPanel **phpMyAdmin**, import `database/migrations/001_initial_schema.sql`.
   - Or use `mysql -u user -p database < migrations/001_initial_schema.sql` (if SSH is available).

### 3. Verify Setup

- Visit `https://your-domain.com/` in a tablet browser.
- You should see the PWA shell with "Loading..." text.
- Check browser console (F12) for errors.
- Install the app to home screen (browser menu → "Install app" or "Add to Home Screen").

### 4. Run Backend Smoke Tests

Run the local backend gate with:

```powershell
.\validate-backend.ps1
```

If `http://localhost:8000` is already serving the app, the script reuses it. If not, it starts a temporary PHP dev server, runs the smoke suite, and stops that temporary server before exiting.

In VS Code, the same gate is exposed as the default test task in [.vscode/tasks.json](.vscode/tasks.json). Run `Tasks: Run Test Task` to execute the backend gate without remembering the command.

This smoke suite validates the hardened backend path:
- CSRF bootstrap
- login
- authenticated user lookup
- site create succeeding with CSRF on a disposable fixture
- site update succeeding with CSRF on that same fixture
- visit-create CSRF enforcement
- sync endpoint rejecting missing auth
- sync endpoint rejecting an API key with the wrong scope
- sync endpoint accepting a scoped API key and reaching business validation
- sync creating a visit successfully via API key
- sync duplicate idempotency returning `duplicate` on retry
- measurement create succeeding with CSRF against the synced visit and disposable equipment fixture
- measurement list returning the created record by visit
- consumable create succeeding with CSRF against the synced visit and disposable equipment fixture
- consumable list returning the created record by visit
- media upload succeeding with CSRF using a multipart image fixture against the synced visit and disposable equipment fixture
- media show returning the created metadata record
- media delete succeeding with CSRF and making the record unavailable afterwards
- repair create succeeding with CSRF against the synced visit and disposable equipment fixture
- repair list returning the created record by visit
- repair update succeeding with CSRF
- repair delete succeeding with CSRF and making the record unavailable afterwards
- audit-log entries for media upload/delete and repair create/update/delete
- negative-path checks for missing-CSRF repair/media writes, missing-file uploads, invalid media MIME types, and invalid repair payload validation
- sync repair/media queue normalization from aliased offline payloads before insert
- sync repair/media malformed queue items returning per-item validation errors instead of blind insert failures
- audit-log entries for sync-created repair and media records
- logout succeeding with CSRF and clearing the session
- audit-log entries for login, site insert/update, and logout
- transaction log row creation

You can still run the raw script directly with `php .\tests\backend_smoke.php` when needed.

## Using This Repo As A Starter

This repo can be reused as a starter for new PHP + MySQL applications with the same infrastructure profile. Use [TEMPLATE_GUIDE.md](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/TEMPLATE_GUIDE.md) to separate reusable platform pieces from service-app-specific code.

For a new app:

1. Run `./bootstrap-starter.ps1 -AppName "Your App" -AppSlug "your_app" -AdminEmail "owner@example.com" -AdminPassword "StrongPassword123!" -Preview` to inspect the starter placeholder changes, then rerun without `-Preview` to apply them.
2. Copy `config/app.local.example.php` to `config/app.local.php`.
3. Set the app name and timezone through `config/app.local.php` or environment values such as `APP_NAME` and `APP_TIMEZONE`.
4. Replace the domain schema and business controllers before building features.
5. Update the `smokeTemplateProfile()` block in `tests/backend_smoke.php` so the gate reflects the new domain fixtures.
6. Keep the generic bootstrap, auth, logging, validation, and gate layers unless the new app has a simpler stack.
7. Keep `validate-backend.ps1` as the single backend validation entrypoint.

## Core Modules (Phase 1–3 Ready)

### Authentication & Authorization
- Session-based login (email + password).
- Roles: `technician`, `admin`, `manager`.
- CSRF token middleware on all state-changing requests.
- Scoped API keys for non-browser clients such as sync devices or server-to-server integrations.
- 256-bit secret handling via libsodium XChaCha20-Poly1305 when available, with AES-256-GCM fallback.

### Offline-First PWA
- Service worker caches static assets for offline use.
- IndexedDB stores pending form submissions and media uploads.
- Sync engine retries with exponential backoff when online.
- Idempotency keys prevent duplicate submissions on retry.

### Database Schema
- **Users**: auth and role management.
- **Customers**: client records.
- **Sites**: customer locations.
- **Equipment**: tanks, pumps, filters, etc.
- **Service Visits**: main workflow record.
- **Measurements**: chemical readings (pH, chlorine, etc.).
- **Consumables**: replaced parts tracking.
- **Repair Recommendations**: maintenance findings.
- **Media**: photos and attachments.
- **Invoices**: optional billing records.
- **Audit Log**: compliance trail.

## API Endpoints (To Implement)

### Authentication
- `GET /api/auth/csrf` — issue session CSRF token for browser clients
- `POST /api/auth/login` — user login
- `POST /api/auth/logout` — session end
- `GET /api/auth/user` — current user info

### Field Operations
- `GET /api/sites` — list active sites
- `GET /api/equipment?site_id=X` — list equipment at site
- `POST /api/visits` — submit a service visit
- `POST /api/media/upload` — upload photos/media

### Admin
- `GET /api/reports` — reports and dashboards
- `POST /api/invoices` — generate invoice from visit

## Development Workflow

### Using Helper Functions

#### Logging (debug_helper.php)
```php
require_once __DIR__ . '/../app/Helpers/debug_helper.php';

// Log POST arrivals first (diagnose missing submissions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logPostArrival('VisitController::store', ['site_id' => $_POST['site_id'] ?? 'missing']);
}

// Log errors
logError('Database connection failed', ['host' => 'localhost', 'port' => 3306], 'error');

// Probe which file is being served (debugging routing issues)
probeFile('CHECK_ROUTING');
```

#### Validation & Type Safety (validation_helper.php)
```php
require_once __DIR__ . '/../app/Helpers/validation_helper.php';

$schema = [
    'email' => ['type' => 'email', 'required' => true],
    'quantity' => ['type' => 'int', 'required' => true],
    'price' => ['type' => 'decimal', 'required' => false, 'precision' => 2],
];

[$validated, $errors] = validateAndPrepare($_POST, $schema);
if (!empty($errors)) {
    http_response_code(400);
    exit(json_encode(['errors' => $errors]));
}

// Now safe to bind: use getBindTypes() for MySQLi
$types = getBindTypes(array_values($validated));
$stmt->bind_param($types, ...$validated);
```

#### Key Standards
- ✅ Always call `logPostArrival()` as the FIRST line in POST handlers.
- ✅ Use `sanitizeDecimal()` for DECIMAL/FLOAT columns (binds as `'s'` type, not `'d'`).
- ✅ Bind null values as `'s'` type; MySQLi correctly sends SQL NULL.
- ✅ Never use `'s'` bind type for INT columns; always use `'i'`.

### Creating a PHP Controller
   ```php
   class VisitController {
       public function store($request) {
           // Validate & insert visit record
       }
   }
   ```

2. **Create a corresponding endpoint** in `public/index.php`:
   ```php
   if ($path === '/api/visits' && $method === 'POST') {
       require_once __DIR__ . '/../app/Controllers/VisitController.php';
       $controller = new VisitController($config);
       $controller->store($_REQUEST);
   }
   ```

3. **Test offline**: disable network in browser DevTools, fill form, verify IndexedDB stores it, reconnect, check sync.

## Security Checklist

- ✅ CSRF tokens on all POST/PUT/DELETE.
- ✅ Session authentication required for protected routes.
- ✅ Scoped API-key authentication available for non-browser clients.
- ✅ API keys stored hashed at rest; plaintext shown only at creation time.
- ✅ 256-bit encryption helper available for sensitive values.
- ✅ Prepared statements (MySQLi) for all database queries.
- ✅ File upload whitelist: only `jpg`, `jpeg`, `png`, `gif`, `pdf`.
- ✅ Output encoding: `htmlspecialchars()` on user data in HTML.
- ✅ Error logging (not displayed in production).
- ✅ Secure cookies: `HttpOnly`, `Secure`, `SameSite=Lax`.

## Observability

- `transaction_log.txt` records structured JSON lines for requests, validation outcomes, security events, and failures.
- `transaction_logs` stores DB-backed request history for filtering by path, auth mode, user, or status code.
- `audit_log` records sensitive business events such as login, logout, and core entity changes.
- `observability.db_request_logging` and `observability.db_audit_logging` can disable DB-backed logging when migrations are unavailable, while file logging remains active.
- `observability.max_log_depth`, `observability.max_log_array_items`, and `observability.max_log_string_length` bound logged payload size so large sync bodies do not overwhelm file or DB storage.

Use the file log when migrations are missing or DB writes fail. Use the DB tables when you need historical analysis or validation review.

## API Key Workflow

Browser clients should continue using session login plus CSRF. Do not embed API keys in frontend JavaScript.

For device or server integrations:

1. Run the migration `database/migrations/002_api_keys.sql`.
2. Generate a key:
  ```powershell
  php tools/generate_api_key.php sync-device "sync:write" 1
  ```
3. Send the key in `X-API-Key` or `Authorization: ApiKey <key>`.
4. Use scoped keys only on non-browser endpoints such as `POST /api/sync`.

## Deployment Checklist

- [ ] GoDaddy hosting plan confirmed (PHP 8.x, MySQL 5.7+, SSL enabled).
- [ ] Database created and migrated.
- [ ] `config/app.local.php` created with secrets.
- [ ] Public root set to `/public` in cPanel.
- [ ] Storage/uploads directory writable by web server.
- [ ] Error logs enabled (`error_log.txt` writable).
- [ ] SSL certificate active (HTTPS).
- [ ] Tested login workflow and visit submission offline + sync.

## Next Steps

1. **Phase 4**: Implement full offline sync with conflict resolution.
2. **Phase 5**: Build field workflow forms (measurements, consumables, repairs, narrative, signature).
3. **Phase 6**: Implement report generation (HTML/PDF) and email dispatch.
4. **Phase 7**: Add security hardening (audit logs, backup automation).
5. **Phase 8**: Pilot with 1–2 technicians; gather feedback; release v1.

## Support & Troubleshooting

### App not loading
- Check `error_log.txt` in the project root.
- Verify database connection in `config/app.local.php`.
- Confirm `/public/index.php` is being served.

### Sync not working
- Open browser DevTools → Application tab.
- Check IndexedDB for `ServiceAppDB` → `queued_visits`.
- Check service worker status: should be "activated and running".
- Check network console for failed `/api/visits` calls.

### CSRF token errors
- Ensure `session_start()` is called before form output.
- Verify `csrf_helper.php` is included.
- Check that form has hidden `csrf_token` input.

## License

Internal use only. All rights reserved.
