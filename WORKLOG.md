# WORKLOG – Water Treatment Service App

## Summary
Tablet-first PWA for onsite water treatment equipment inspections, built on PHP + MySQL + PWA, hosted on GoDaddy shared hosting. Supports offline-first workflows with sync queue for resilience.

## Standards Applied (from lessons_learned.md)

### 🔴 Critical PHP Patterns to Enforce
- ✅ Output-before-session: Session starts before any output in `app/bootstrap.php`, and must not be restarted in `public/index.php`.
- ✅ CSRF on ALL state-changing requests: Middleware enforced; apply to every POST/PUT/DELETE endpoint.
- ✅ Type safety in MySQLi: Use `'i'` for INT, `'s'` for string/null, never mix types.
- ✅ DECIMAL/FLOAT handling: Null fields converted before binding; null values bind as `'s'` type.
- ✅ POST handler logging: `debug_helper.php` provides `logPostArrival()` for first-line diagnostics.

## Recent Updates

- Added [lessons_learned.md](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/lessons_learned.md) to capture session bootstrap ownership after fixing duplicate session initialization warnings.
- Aligned the core API contract with the live schema: `sites`, `equipment`, and `service_visits` now accept schema-correct fields, the router exposes `GET /api/auth/csrf`, JSON request bodies are normalized in the base controller, and local login works with CSRF + session cookies.
- Updated [setup-local-db.ps1](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/setup-local-db.ps1) and [LOCAL_TESTING_GUIDE.md](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/LOCAL_TESTING_GUIDE.md) so local setup seeds a known admin and testing steps match the actual API contract.
- Fixed the database wrapper so successful INSERT/UPDATE operations no longer fail when `get_result()` is unavailable for non-SELECT statements.
- Added a security baseline: 256-bit encryption helpers, scoped hashed API keys, security headers, CLI API-key generation, and `session-or-API-key` protection for `POST /api/sync`.
- Fixed config merge isolation in [app/bootstrap.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/bootstrap.php) so local overrides no longer wipe inherited security defaults during bootstrap.
- Added a centralized transaction logging layer in [TransactionLogger.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Services/TransactionLogger.php), plus DB persistence via [003_transaction_logs.sql](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/database/migrations/003_transaction_logs.sql) and file-backed logging in `transaction_log.txt`.
- Session validation now runs on each protected request in [AuthMiddleware.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Middleware/AuthMiddleware.php) using idle timeout + session fingerprint checks, and core auth/site/equipment/visit actions now emit audit records.
- Hardened the request lifecycle: [bootstrap.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/bootstrap.php) now applies cookie options with `SameSite`, [AuthMiddleware.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Middleware/AuthMiddleware.php) caches validated auth state per request, and [Controller.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/Controller.php) starts transaction logging before auth resolution so session validation logs carry the correct request ID.
- Closed the visit-create CSRF gap in [ServiceVisitController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/ServiceVisitController.php) so browser session routes still require CSRF even when an `idempotency_key` is present, and corrected sync summary accounting in [SyncController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/SyncController.php) so duplicates no longer count as failed work.
- Added deployment-tunable session fingerprinting in [AuthMiddleware.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Middleware/AuthMiddleware.php) with config support in [app.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/config/app.php) and [app.local.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/config/app.local.php), defaulting to `user_agent_ip_prefix` for better field-network tolerance.
- Removed per-request schema existence probes from [TransactionLogger.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Services/TransactionLogger.php); DB-backed request and audit logging are now controlled by explicit `observability` config flags instead of repeated metadata queries.
- Added executable backend smoke coverage in [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php). It validates CSRF bootstrap, login, authenticated user lookup, visit-create CSRF enforcement, anonymous sync rejection, and transaction-log growth against the live local server.
- Bounded observability payload size in [TransactionLogger.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Services/TransactionLogger.php) using configurable depth, array-item, and string-length limits from [app.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/config/app.php). The backend smoke suite still passes after this change.
- Expanded [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) to cover scoped API-key success on `/api/sync`, successful CSRF-protected logout, and post-logout access denial. Added [validate-backend.ps1](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/validate-backend.ps1) as the one-command local backend validation entrypoint.
- Extended [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) again to cover a successful CSRF-protected site create against a disposable customer fixture and a full idempotent sync retry cycle (`success` then `duplicate`) for visits created through `/api/sync`.
- Expanded [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) again to cover a successful CSRF-protected site update and a negative API-key scope check, proving `/api/sync` distinguishes valid scoped keys from wrong-scope keys during authentication.
- Deepened [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) with audit-log assertions for login, site insert/update, and logout, so backend validation now checks compliance logging as well as HTTP responses and transaction-log growth.
- Fixed [MeasurementController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/MeasurementController.php) to map incoming request data onto the schema-correct `visit_id` field instead of dropping `service_visit_id`, and extended [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) to cover measurement create/list behavior using disposable fixtures.
- Fixed the same request/schema mismatch in [ConsumableController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/ConsumableController.php) and aligned [Consumable.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Models/Consumable.php) list helpers with controller pagination calls. [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) now covers consumable create/list behavior as well.
- Fixed [MediaController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/MediaController.php) to persist schema-correct media fields, hard-delete records instead of writing a nonexistent soft-delete column, and fall back cleanly when `mime_content_type()` is unavailable. [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) now exercises multipart media upload, fetch, and delete behavior end to end.
- Fixed [RepairController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/RepairController.php) to map request fields onto the actual `repair_recommendations` schema, stop querying or deleting through nonexistent `deleted_at` / `completed_at` columns, and aligned [RepairRecommendation.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Models/RepairRecommendation.php) helper signatures with controller pagination calls. [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) now covers repair create/list/update/delete behavior.
- Added audit logging for media upload/delete in [MediaController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/MediaController.php) and repair create/update/delete in [RepairController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/RepairController.php). [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) now asserts those audit rows directly so regressions fail in the standard backend validation run.
- Hardened [RepairRecommendation.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Models/RepairRecommendation.php) so schema-required fields fail validation as `422` responses instead of leaking into database-level failures. [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) now includes negative-path checks for invalid repair payloads, missing-CSRF repair/media writes, missing upload files, and invalid media MIME types.
- Fixed [SyncController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/SyncController.php) so repair and media queue items are normalized from offline aliases like `service_visit_id`, `issue`, `notes`, and `file_name` before insert, and so malformed queue items return explicit per-item validation errors instead of generic insert failures. [MediaItem.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Models/MediaItem.php) now enforces schema-required media fields. [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) now covers positive and negative sync repair/media behavior.
- Added audit logging for sync-created repair and media records in [SyncController.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/app/Controllers/SyncController.php). [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) now asserts those offline audit rows directly, so the sync path is held to the same audit baseline as direct endpoint writes.
- Upgraded [validate-backend.ps1](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/validate-backend.ps1) from a thin wrapper into a local backend gate: it now reuses an existing dev server when available, otherwise starts a temporary PHP server, runs the smoke suite, exits nonzero on failure, and shuts the temporary server down automatically.
- Added [.vscode/tasks.json](.vscode/tasks.json) so VS Code exposes the backend gate as the default test task and the PHP dev server as a reusable workspace task.
- Added [TEMPLATE_GUIDE.md](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/TEMPLATE_GUIDE.md) and [config/app.local.example.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/config/app.local.example.php) to make this repo reusable as a starter instead of relying on tribal knowledge. Parameterized [setup-local-db.ps1](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/setup-local-db.ps1) so new projects can seed their own admin identity without editing the script.
- Refactored [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php) so domain fixtures are concentrated in a single `smokeTemplateProfile()` block instead of being scattered across the harness. Also parameterized [app.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/config/app.php) so new apps can override `APP_NAME` and `APP_TIMEZONE` without editing the platform config directly.
- Added [bootstrap-starter.ps1](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/bootstrap-starter.ps1) so a new project can rename the obvious starter placeholders in preview or apply mode instead of editing README, workspace metadata, local config examples, and setup defaults by hand.
- Extended [bootstrap-starter.ps1](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/bootstrap-starter.ps1) so it also rewrites the default seeded admin password and the smoke-suite admin defaults in [backend_smoke.php](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/tests/backend_smoke.php). New apps no longer inherit `admin@example.com` / `password123` unless explicitly intended.
- Initialized a git repository for the starter on the `main` branch and added [.gitattributes](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/.gitattributes) so PHP, SQL, markdown, and frontend files normalize predictably across Windows and deployment environments while PowerShell scripts keep CRLF. Updated [TEMPLATE_GUIDE.md](C:/Users/rober/OneDrive/0.5-Eclipse/Service%20App/TEMPLATE_GUIDE.md) to make the first clean commit an explicit post-bootstrap step.

### 🎨 UX Standards
- ✅ Sticky table scrollbars: CSS added for wide table horizontal scroll (`.table-responsive` class).
- ✅ Language attribute: `lang="en-CA"` in HTML (Canadian market SEO + accessibility).

### 🔐 Security Standards
- ✅ Data files excluded: `.gitignore` includes `*.log`, `*.csv`, `debug_log.txt`, `error_log.txt`.
- ✅ File encoding: UTF-8 throughout; watch for CRLF issues on multi-file edits.

### Helper Functions Created
- `app/Helpers/csrf_helper.php` – Token generation/verification (already existed).
- `app/Helpers/debug_helper.php` – `logPostArrival()`, `logError()`, `debugDump()`, `probeFile()`.
- `app/Helpers/validation_helper.php` – `sanitizeEmail()`, `sanitizeInt()`, `sanitizeDecimal()`, `validateAndPrepare()`, `getBindTypes()`.

## Phase Completion Status
- [x] Phase 1: GoDaddy hosting constraints locked; architecture finalized.
- [x] Phase 2: Data model designed (10 core tables + audit log).
- [x] Phase 3: Project scaffold created + standards applied.
- [x] Phase 3b: **Backend architecture implemented:**
  - `app/Database/Database.php` – MySQLi wrapper with type-safe prepared statements.
  - `app/Models/Model.php` – Base model with CRUD methods (find, all, where, insert, update, delete).
  - `app/Controllers/Controller.php` – Base controller with auth, CSRF, error handling, response formatting.
  - `app/bootstrap.php` – Initializes config, database, middleware, and models on every request.
  - Specific models created: User, Customer, Site, Equipment, ServiceVisit (with validation + custom queries).
  - AuthController example showing the pattern for implementing endpoints.
  - Router updated to load and route to controllers.
- [x] Phase 4: PWA baseline (service worker, offline shell, sync queue, IndexedDB).
- [ ] Phase 5: Field workflow modules (visits, sites, equipment, media endpoints + form handlers).
- [ ] Phase 6: Reporting & billing (PDF/email, invoices, dashboard).
- [ ] Phase 7: Security hardening (audit logs, backups, monitoring).
- [ ] Phase 8: Pilot & rollout (user testing, friction analysis, v1 release).

## Files Created (Phase 1–3b)

### Backend Architecture (NEW)
- `app/Database/Database.php` – MySQLi wrapper: `execute()`, `insert()`, `update()`, `delete()`, `query()`, with type-safe binding.
- `app/Models/Model.php` – Base model: `find()`, `all()`, `where()`, `count()`, `insert()`, `updateById()`, `deleteById()`, with fillable/guarded filtering.
- `app/Controllers/Controller.php` – Base controller: `requireAuth()`, `requireRole()`, `requireCsrf()`, `success()`, `error()`, `badRequest()`, etc.
- `app/bootstrap.php` – Bootstraps config, session, database, middleware, and auto-loads all models.
- `app/Controllers/AuthController.php` – Example controller with login/logout/user endpoints.

### Models (Ready for Extension)
- `app/Models/User.php` – User CRUD + password hashing + validation.
- `app/Models/Customer.php` – Customer CRUD + validation.
- `app/Models/Site.php` – Site CRUD + validation + custom queries (getBySite, getActiveWithCustomer).
- `app/Models/Equipment.php` – Equipment CRUD + validation + custom queries (getBySite, getActiveWithSite).
- `app/Models/ServiceVisit.php` – Visit CRUD + validation + custom queries (idempotency key lookup, sync status).
- `app/Models/Measurement.php` – Measurement CRUD + validation + custom queries (getByVisit, getByEquipmentAndVisit).
- `app/Models/Consumable.php` – Consumable CRUD + validation + custom queries (getByVisit, getBillableByVisit).
- `app/Models/RepairRecommendation.php` – Repair CRUD + validation + custom queries (getByVisit, getUrgent).
- `app/Models/MediaItem.php` – Media CRUD + validation + custom queries (getByVisit, getUnuploaded for sync).

### Configuration & Bootstrap
- `config/app.php` – Main app configuration with env fallbacks
- `config/app.local.php` – (Template, not committed) Environment secrets
- `app/bootstrap.php` – App initialization (NEW)
- `public/index.php` – Router, now using bootstrap
- `.gitignore` – Exclude secrets, logs, uploads, vendor

### Views & PWA
- `public/app.html` – App shell (responsive header, main container, footer)
- `public/manifest.json` – Web app manifest (icons, display modes, start URL)
- `frontend/css/tablet-ui.css` – Touch-first styles, large buttons, landscape optimization

### Backend Middleware
- `app/Middleware/AuthMiddleware.php` – Session auth, role checks
- `app/Middleware/CsrfMiddleware.php` – CSRF token validation on state-changing requests
- `app/Helpers/csrf_helper.php` – Token generation and form helpers

### Frontend (JavaScript)
- `frontend/js/sw.js` – Service worker (asset caching, offline shell, cache busting)
- `frontend/js/sync-queue.js` – IndexedDB queue, offline submission batching, exponential backoff retry
- `frontend/js/visit-form.js` – Visit workflow form (sites, equipment, measurements, consumables, repairs, signature, photo upload)

### Database & Helpers
- `database/migrations/001_initial_schema.sql` – 10 core tables + indexes, optimized for MySQL 5.7+
- `app/Helpers/csrf_helper.php` – Token generation/verification
- `app/Helpers/debug_helper.php` – `logPostArrival()`, `logError()`, `debugDump()`, `probeFile()`
- `app/Helpers/validation_helper.php` – `sanitizeEmail()`, `sanitizeInt()`, `sanitizeDecimal()`, `validateAndPrepare()`, `getBindTypes()`

### Documentation
- `README.md` – Getting started, deployment checklist, security notes, troubleshooting
- `WORKLOG.md` – Phase status, implementation guide (THIS FILE)

## Implementation Guide for Phase 5+

### How to Create a New Endpoint

1. **Create a Model** (if not already created):
   ```php
   // app/Models/Measurement.php
   class Measurement extends Model {
       protected $table = 'measurements';
       protected $fillable = ['visit_id', 'equipment_id', 'measurement_type', 'value', 'unit', 'status'];
       
       public function validate($data) {
           $errors = [];
           if (isset($data['value']) && !is_numeric($data['value'])) {
               $errors['value'] = 'Value must be numeric';
           }
           return $errors;
       }
   }
   ```

2. **Create a Controller** (or add a method to an existing one):
   ```php
   // app/Controllers/MeasurementController.php
   class MeasurementController extends Controller {
       
       public function store() {
           $this->requireAuth();
           $this->requireCsrf();
           
           $model = new Measurement($this->db);
           
           $data = [
               'visit_id' => $this->getPost('visit_id'),
               'equipment_id' => $this->getPost('equipment_id'),
               'measurement_type' => $this->getPost('measurement_type'),
               'value' => $this->getPost('value'),
               'unit' => $this->getPost('unit'),
               'status' => $this->getPost('status', 'normal'),
           ];
           
           // Validate
           $errors = $model->validate($data);
           if (!empty($errors)) {
               $this->unprocessable('Validation failed', $errors);
           }
           
           // Insert
           $id = $model->insert($data);
           if (!$id) {
               $this->internalError('Failed to create measurement');
           }
           
           $this->success(['id' => $id, 'message' => 'Measurement created'], 201);
       }
       
       public function index() {
           $this->requireAuth();
           
           $visitId = $this->getQuery('visit_id');
           $model = new Measurement($this->db);
           $measurements = $model->where(['visit_id' => $visitId]);
           
           $this->success(['measurements' => $measurements]);
       }
   }
   ```

3. **Register Route in Router**:
   ```php
   // public/index.php
   if (strpos($path, '/api/measurements') === 0) {
       require_once __DIR__ . '/../app/Controllers/MeasurementController.php';
       $controller = new MeasurementController($config, $db, $auth);
       
       if ($path === '/api/measurements' && $method === 'GET') {
           $controller->index();
       } elseif ($path === '/api/measurements' && $method === 'POST') {
           $controller->store();
       }
   }
   ```

### Key Architecture Patterns

| Pattern | Usage |
|---------|-------|
| **Model.find($id)** | Get single record by ID |
| **Model.all()** | Get all records |
| **Model.where($conditions)** | Query by conditions |
| **Model.insert($data)** | Create new record (respects fillable) |
| **Model.updateById($id, $data)** | Update record |
| **Model.deleteById($id)** | Delete record |
| **$this->requireAuth()** | Enforce login; exit with 401 if not authenticated |
| **$this->requireRole('admin')** | Enforce role; exit with 403 if denied |
| **$this->requireCsrf()** | Verify CSRF token; exit with 403 if invalid |
| **$this->success($data)** | Send 200 JSON response |
| **$this->badRequest($message, $errors)** | Send 400 JSON response |
| **$this->unauthorized()** | Send 401 JSON response |
| **$this->forbidden()** | Send 403 JSON response |
| **$this->unprocessable($message, $errors)** | Send 422 JSON response (validation errors) |

### Model Fillable/Guarded Pattern

```php
// Whitelist mode (recommended): only allow specified columns
protected $fillable = ['email', 'name', 'phone'];

// Blacklist mode: remove specified columns
protected $guarded = ['id', 'created_at', 'password_hash'];

// insert() and update() automatically filter data based on fillable/guarded
```

### Database Type Safety

```php
// Database::execute() auto-detects types (int, float, string, null)
// No need to specify types manually; it uses getBindTypes() internally

// But if you need custom types:
$db->execute($sql, $params, 'iss'); // int, string, string
```

## Current State (Phase 5 Complete - Field Workflow Endpoints)

**What's Built:**
- ✅ All 8 core API endpoints for field workflow (27 total endpoint methods)
- ✅ Full CRUD operations (Create, Read, Update, Delete) for all resources
- ✅ Offline-first sync system with idempotency key conflict resolution
- ✅ Media upload handling (photos, videos, documents)
- ✅ Service visit tracking with status workflow (scheduled → in-progress → pending-review → completed)
- ✅ Local development environment setup (dev-server.ps1, setup-local-db.ps1, app.local.php)

**Phase 5 Endpoints (Fully Implemented):**

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/sites` | GET | List customer sites with pagination & filtering |
| `/api/sites` | POST | Create new site |
| `/api/sites/{id}` | GET | Get site details with equipment count |
| `/api/sites/{id}` | PUT | Update site information |
| `/api/sites/{id}` | DELETE | Soft-delete site |
| `/api/equipment` | GET | List equipment at site (requires ?site_id) |
| `/api/equipment` | POST | Add equipment to site |
| `/api/equipment/{id}` | GET | Get equipment with recent measurement history |
| `/api/equipment/{id}` | PUT | Update equipment details |
| `/api/equipment/{id}` | DELETE | Soft-delete equipment |
| `/api/visits` | GET | List visits (filter by site/status) |
| `/api/visits` | POST | Create service visit (idempotency key support) |
| `/api/visits/{id}` | GET | Get complete visit with all related data |
| `/api/visits/{id}` | PUT | Update visit status |
| `/api/visits/{id}/complete` | POST | Mark visit as complete |
| `/api/measurements` | GET | List measurements (by visit or equipment) |
| `/api/measurements` | POST | Log chemical reading |
| `/api/measurements/{id}` | GET | Get measurement details |
| `/api/consumables` | GET | List consumables (filter by billable status) |
| `/api/consumables` | POST | Log consumable used/replaced |
| `/api/consumables/{id}` | GET | Get consumable details |
| `/api/repairs` | GET | List repair recommendations (filter by priority/urgent) |
| `/api/repairs` | POST | Create repair recommendation |
| `/api/repairs/{id}` | GET | Get repair details |
| `/api/repairs/{id}` | PUT | Update repair status (approved/completed/declined) |
| `/api/repairs/{id}` | DELETE | Delete repair |
| `/api/media/upload` | POST | Upload photo/video/document (multipart/form-data) |
| `/api/media/{id}` | GET | Get media metadata |
| `/api/media/{id}` | DELETE | Delete media file & record |
| `/api/sync` | POST | Process offline queue from tablet IndexedDB (idempotency handling) |

**Controllers Created:**
- `app/Controllers/SiteController.php` – Full CRUD for sites
- `app/Controllers/EquipmentController.php` – Full CRUD for equipment
- `app/Controllers/ServiceVisitController.php` – Visit workflow + idempotency
- `app/Controllers/MeasurementController.php` – Chemical readings
- `app/Controllers/ConsumableController.php` – Parts/materials tracking
- `app/Controllers/RepairController.php` – Maintenance recommendations
- `app/Controllers/MediaController.php` – File upload & management
- `app/Controllers/SyncController.php` – Offline queue processing

**Local Development Setup:**
- `dev-server.ps1` – PowerShell script to start PHP built-in server (php -S localhost:8000)
- `dev-server.sh` – Bash script for Linux/Mac
- `setup-local-db.ps1` – PowerShell script to create MySQL DB and import schema
- `config/app.local.php` – Local dev config (localhost MySQL, HTTP cookies, debug mode)

**Next Steps:**
1. Test all endpoints locally with Postman or Thunder Client
2. Deploy to GoDaddy (upload files, create DB, import schema, set up app.local.php with GoDaddy MySQL credentials)
2. Test login endpoint (POST /api/auth/login).
3. Build Phase 5 endpoints one by one, using AuthController as the pattern.
4. Update tablet form (visit-form.js) to call new endpoints instead of using offline-only queue initially.
5. Test offline workflow: submit without network, verify IndexedDB queue, sync when online.

## Next Immediate Steps

1. **Deploy to GoDaddy**:
   - Upload `/app`, `/config`, `/database`, `/storage`, `/frontend` to hosting (outside web root).
   - Upload `/public` contents as web root.
   - Create MySQL database and import schema from `001_initial_schema.sql`.
   - Create `config/app.local.php` with GoDaddy credentials on server.

2. **Implement Phase 5 (Field Workflow Endpoints)**:
   - `POST /api/auth/login` – Validate user, set session.
   - `GET /api/sites` – Fetch sites for current user's role.
   - `GET /api/equipment?site_id=X` – Fetch equipment at site.
   - `POST /api/visits` – Insert service visit record, parse form data, check for idempotency.
   - `POST /api/media/upload` – Handle file upload and metadata.

3. **Test Offline Workflow**:
   - In browser DevTools, disable network.
   - Fill visit form, submit.
   - Verify form data in IndexedDB (`ServiceAppDB` → `queued_visits`).
   - Re-enable network, check sync console logs.
   - Verify API receives submission.

4. **Hardening**:
   - Add prepared statement examples to controller templates.
   - Add input validation layer (email, numeric ranges, file MIME types).
   - Add error response format (JSON error codes + messages).

## Notes
- GoDaddy hosting is shared, so no long-running cron jobs; sync is client-driven.
- CSRF token regeneration should NOT invalidate session; use persistent token or rotate in-session.
- Media uploads compressed client-side before queueing to reduce storage pressure.
- Invoicing is optional v1; can be added as Phase 6 module after MVP stabilization.

## Key Decision Points for Implementation
1. **Login UI**: Choose between HTTP form-based login or modal popup in app shell.
2. **Report format**: Decide PDF library (e.g., TCPDF, mPDF) or pre-rendered HTML + browser print.
3. **Billing integration**: If in scope, choose payment processor (Stripe, Square) or manual invoice + manual payment tracking.
4. **Measurement templates**: Pre-define chemical thresholds per equipment type or allow freeform entry?

---

**Last updated**: 2026-05-15  
**Status**: Ready for Phase 5 (field workflow endpoint implementation)
