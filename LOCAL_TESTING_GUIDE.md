# LOCAL TESTING GUIDE

## Quick Start (Windows)

### 1. Set Up Local MySQL Database

```powershell
cd "C:\Users\rober\OneDrive\0.5-Eclipse\Service App"

# Copy local config template first if this is a fresh clone
Copy-Item .\config\app.local.example.php .\config\app.local.php

# Run database setup (creates app_local_dev DB, imports schema, and seeds admin user)
.\setup-local-db.ps1 -MySqlPassword admin
```

Expected output:
```
✓ MySQL connection successful
✓ Database created
✓ Schema imported successfully
✓ Default admin seeded (admin@example.com / [configured password])
```

You can override the seeded admin for a new project with:

```powershell
.\setup-local-db.ps1 -MySqlPassword admin -AdminEmail owner@example.com -AdminPassword StrongPassword123! -AdminName "Project Owner"
```

If you are cloning this repo as a new starter-based app, run the bootstrap script first so the seeded admin and smoke-suite defaults are renamed together:

```powershell
.\bootstrap-starter.ps1 -AppName "Your App" -AppSlug "your_app" -AdminEmail owner@example.com -AdminPassword StrongPassword123! -Preview
```

### 2. Start PHP Development Server

```powershell
# In the same directory
.\dev-server.ps1
```

Expected output:
```
Starting PHP built-in server on http://localhost:8000
Press Ctrl+C to stop.
```

### 2b. Run Backend Smoke Suite / Local Gate

Execute:

```powershell
.\validate-backend.ps1
```

Behavior:
- Reuses `http://localhost:8000` if the dev server is already running.
- Otherwise starts a temporary local PHP server, runs the smoke suite, and shuts it down automatically.
- Exits nonzero when validation fails, so it can serve as a local pre-merge or pre-release gate.
- In VS Code, run `Tasks: Run Test Task` to trigger the same gate from the default workspace test task.

Expected output:
```
[PASS] CSRF bootstrap
[PASS] Login
[PASS] Authenticated user lookup
[PASS] Site create succeeds with CSRF
[PASS] Site update succeeds with CSRF
[PASS] Visit create rejects missing CSRF
[PASS] Sync endpoint rejects missing auth
[PASS] Sync endpoint rejects wrong-scope API key
[PASS] Sync endpoint accepts scoped API key
[PASS] Sync creates visit with API key
[PASS] Sync duplicate idempotency is enforced
[PASS] Sync repair and media payloads are normalized and accepted
[PASS] Sync repair and media payloads reject malformed queue items
[PASS] Repair create rejects missing CSRF
[PASS] Repair create rejects invalid payloads
[PASS] Measurement create succeeds with CSRF
[PASS] Measurement list returns created record
[PASS] Consumable create succeeds with CSRF
[PASS] Consumable list returns created record
[PASS] Media upload rejects missing CSRF
[PASS] Media upload rejects missing files
[PASS] Media upload rejects invalid file types
[PASS] Media upload succeeds with CSRF
[PASS] Media show returns created record
[PASS] Media delete succeeds with CSRF
[PASS] Media delete removes record
[PASS] Repair create succeeds with CSRF
[PASS] Repair list returns created record
[PASS] Repair update succeeds with CSRF
[PASS] Repair delete succeeds with CSRF
[PASS] Repair delete removes record
[PASS] Logout succeeds with CSRF
[PASS] Logout clears authenticated session
[PASS] Audit logs recorded login, site changes, sync inserts, media events, repair events, and logout
[PASS] Transaction logs recorded request activity
Smoke suite passed.
```

This is the fastest regression check after auth, CSRF, session, sync, or logging changes.
Use `php .\tests\backend_smoke.php` directly only when you need to pass a custom base URL or debug the raw script.

### 3. Fetch CSRF Token

State-changing API calls require a CSRF token from the current session.

```http
GET http://localhost:8000/api/auth/csrf
```

Expected response:

```json
{
  "status": "success",
  "data": {
    "csrf_token": "<token>",
    "authenticated": false
  }
}
```

### 4. Test Login Endpoint

Open **Postman** or **Thunder Client** and create this request:

```
POST http://localhost:8000/api/auth/login
Content-Type: application/json
Cookie: service_app_session=<session_cookie_from_csrf_request>

{
  "csrf_token": "<token_from_previous_step>",
  "email": "admin@example.com",
  "password": "password123"
}
```

**Expected Response (200):**
```json
{
  "status": "success",
  "data": {
    "user": {
      "id": 1,
      "name": "Admin User",
      "email": "admin@example.com",
      "role": "admin"
    }
  }
}
```

---

### 5. Test API Key Flow (Non-Browser Clients)

Use API keys only for device or server integrations, not the browser frontend.

1. Import `database/migrations/002_api_keys.sql` into `service_app_dev`.
2. Generate a key:

```powershell
php .\tools\generate_api_key.php sync-device "sync:write" 1
```

3. Exercise the protected sync endpoint:

```http
POST http://localhost:8000/api/sync
X-API-Key: <generated_key>
Content-Type: application/json

{
  "queue": []
}
```

Expected result: request is authenticated by the API key and reaches endpoint validation. With an empty queue, the response should be a `400` business error rather than `401` authentication failure.

---

### 6. Validate Logging Layer

After login or API activity, inspect both logging surfaces:

```powershell
Get-Content .\transaction_log.txt -Tail 10
mysql -u root -padmin service_app_dev -e "SELECT id, request_id, method, path, auth_mode, status_code, created_at FROM transaction_logs ORDER BY id DESC LIMIT 10;"
mysql -u root -padmin service_app_dev -e "SELECT id, action, entity_type, entity_id, timestamp FROM audit_log ORDER BY id DESC LIMIT 10;"
```

Expected result:
- `transaction_log.txt` shows structured request and session validation entries.
- `transaction_logs` shows request rows for `csrf`, `login`, `auth/user`, and other API calls.
- `audit_log` shows login/logout and core entity mutations.
- If DB-backed logging is not available in an environment, set the `observability` config flags to `false` and rely on `transaction_log.txt` for smoke validation.
- If large payloads are being truncated in logs, tune `observability.max_log_depth`, `observability.max_log_array_items`, and `observability.max_log_string_length` instead of disabling logging entirely.

---

## Test Workflow: Complete Service Visit

This walks through a typical technician workflow from login to completing a visit.

### Step 1: Create Customer & Site (Admin)

**POST /api/sites**
```
POST http://localhost:8000/api/sites
Content-Type: application/json
Cookie: service_app_session=<session_cookie_from_login>

{
  "csrf_token": "<token>",
  "customer_id": 1,
  "site_name": "Main Office",
  "address_line1": "123 Main St",
  "city": "Toronto",
  "province": "ON",
  "postal_code": "M5V3A8",
  "contact_person": "John Smith",
  "contact_phone": "416-555-0100"
}
```

**Expected Response (201):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "customer_id": 1,
    "site_name": "Main Office",
    "address_line1": "123 Main St",
    "...": "..."
  }
}
```

Copy the **site id** for next steps.

---

### Step 2: Add Equipment to Site (Admin)

**POST /api/equipment**
```
POST http://localhost:8000/api/equipment
Content-Type: application/json
Cookie: service_app_session=<session_cookie>

{
  "csrf_token": "<token>",
  "site_id": 1,
  "equipment_type": "tank",
  "model": "Softener Tank",
  "capacity_liters": 1000,
  "last_service_date": "2026-04-15"
}
```

**Expected Response (201):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "site_id": 1,
    "equipment_type": "tank",
    "model": "Softener Tank",
    "capacity_liters": 1000,
    "created_at": "2026-05-15 10:30:00"
  }
}
```

Copy the **equipment id** for next steps.

---

### Step 3: List Sites (Technician)

**GET /api/sites**
```
GET http://localhost:8000/api/sites
Cookie: service_app_session=<session_cookie>
```

**Expected Response (200):**
```json
{
  "status": "success",
  "data": {
    "sites": [
      {
        "id": 1,
        "customer_id": 1,
        "name": "Main Office",
        "address": "123 Main St",
        "...": "..."
      }
    ],
    "total": 1,
    "limit": 20,
    "offset": 0
  }
}
```

---

### Step 4: Get Equipment at Site (Technician)

**GET /api/equipment?site_id=1**
```
GET http://localhost:8000/api/equipment?site_id=1
Cookie: service_app_session=<session_cookie>
```

**Expected Response (200):**
```json
{
  "status": "success",
  "data": {
    "equipment": [
      {
        "id": 1,
        "site_id": 1,
        "equipment_type": "tank",
        "name": "Softener Tank",
        "capacity_liters": 1000,
        "recent_measurements": 0
      }
    ],
    "total": 1,
    "limit": 20,
    "offset": 0
  }
}
```

---

### Step 5: Create Service Visit (Technician)

**POST /api/visits**
```
POST http://localhost:8000/api/visits
Content-Type: application/json
Cookie: service_app_session=<session_cookie>

{
  "csrf_token": "<token>",
  "site_id": 1,
  "technician_id": 1,
  "visit_status": "in-progress",
  "visit_date": "2026-05-15",
  "idempotency_key": "visit-1234567890",
  "visit_notes": "Routine maintenance"
}
```

**Expected Response (201):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "site_id": 1,
    "equipment_id": 1,
    "technician_id": 2,
    "status": "in-progress",
    "idempotency_key": "visit-1234567890",
    "sync_status": "synced",
    "created_at": "2026-05-15 10:45:00"
  }
}
```

Copy the **visit id** for next steps.

---

### Step 6: Log Measurement (Technician)

**POST /api/measurements**
```
POST http://localhost:8000/api/measurements
Content-Type: application/x-www-form-urlencoded
Cookie: service_app_session=<session_cookie>

service_visit_id=1&equipment_id=1&measurement_type=pH&value=7.2&unit=pH&status=normal&notes=Normal pH reading
```

**Expected Response (201):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "service_visit_id": 1,
    "equipment_id": 1,
    "measurement_type": "pH",
    "value": "7.2",
    "unit": "pH",
    "status": "normal",
    "created_at": "2026-05-15 10:50:00"
  }
}
```

Log multiple measurements (chlorine, hardness, etc.):
```
POST http://localhost:8000/api/measurements
service_visit_id=1&equipment_id=1&measurement_type=chlorine&value=2.8&unit=ppm&status=normal
```

---

### Step 7: Log Consumable (Technician)

**POST /api/consumables**
```
POST http://localhost:8000/api/consumables
Content-Type: application/x-www-form-urlencoded
Cookie: service_app_session=<session_cookie>

service_visit_id=1&equipment_id=1&name=Water Filter&quantity_used=1&unit=unit&cost=45.00&is_billable=1&reason=Routine replacement
```

**Expected Response (201):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "service_visit_id": 1,
    "equipment_id": 1,
    "name": "Water Filter",
    "quantity_used": 1,
    "cost": "45.00",
    "is_billable": 1,
    "created_at": "2026-05-15 10:55:00"
  }
}
```

---

### Step 8: Create Repair Recommendation (Technician)

**POST /api/repairs**
```
POST http://localhost:8000/api/repairs
Content-Type: application/x-www-form-urlencoded
Cookie: service_app_session=<session_cookie>

service_visit_id=1&equipment_id=1&issue=Pump seal is leaking&priority=high&estimated_cost=500.00&status=recommended
```

**Expected Response (201):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "service_visit_id": 1,
    "equipment_id": 1,
    "issue": "Pump seal is leaking",
    "priority": "high",
    "estimated_cost": "500.00",
    "status": "recommended",
    "created_at": "2026-05-15 11:00:00"
  }
}
```

---

### Step 9: Upload Media (Technician)

**POST /api/media/upload**
```
POST http://localhost:8000/api/media/upload
Cookie: service_app_session=<session_cookie>
Content-Type: multipart/form-data

service_visit_id=1
equipment_id=1
file=<image_file.jpg>
notes=Photo of damaged seal
```

Using cURL:
```bash
curl -X POST http://localhost:8000/api/media/upload \
  -H "Cookie: service_app_session=<session_cookie>" \
  -F "service_visit_id=1" \
  -F "equipment_id=1" \
  -F "file=@/path/to/image.jpg" \
  -F "notes=Photo of damaged seal"
```

**Expected Response (201):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "service_visit_id": 1,
    "equipment_id": 1,
    "media_type": "photo",
    "file_name": "image.jpg",
    "url": "/storage/uploads/abc123def456.jpg",
    "file_size": 2048576,
    "created_at": "2026-05-15 11:05:00"
  }
}
```

---

### Step 10: Get Complete Visit (Technician)

**GET /api/visits/1**
```
GET http://localhost:8000/api/visits/1
Cookie: service_app_session=<session_cookie>
```

**Expected Response (200):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "site_id": 1,
    "equipment_id": 1,
    "status": "in-progress",
    "created_at": "2026-05-15 10:45:00",
    "measurements": [
      {
        "id": 1,
        "measurement_type": "pH",
        "value": "7.2",
        "status": "normal"
      }
    ],
    "consumables": [
      {
        "id": 1,
        "name": "Water Filter",
        "quantity_used": 1,
        "cost": "45.00",
        "is_billable": 1
      }
    ],
    "repairs": [
      {
        "id": 1,
        "issue": "Pump seal is leaking",
        "priority": "high",
        "estimated_cost": "500.00"
      }
    ],
    "media": [
      {
        "id": 1,
        "media_type": "photo",
        "url": "/storage/uploads/abc123def456.jpg"
      }
    ]
  }
}
```

---

### Step 11: Mark Visit Complete (Technician)

**POST /api/visits/1/complete**
```
POST http://localhost:8000/api/visits/1/complete
Cookie: service_app_session=<session_cookie>
Content-Type: application/x-www-form-urlencoded

X-CSRF-Token=<csrf_token>
```

**Expected Response (200):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "status": "completed",
    "completed_at": "2026-05-15 11:30:00",
    "sync_status": "synced"
  }
}
```

---

## Test Offline Sync Workflow

### Simulate Offline Submission

When tablet is offline, it queues items in IndexedDB. When it comes back online, it submits the queue to `/api/sync`.

**POST /api/sync**
```
POST http://localhost:8000/api/sync
Content-Type: application/json
Cookie: service_app_session=<session_cookie>

{
  "queue": [
    {
      "type": "visit",
      "action": "create",
      "data": {
        "site_id": 1,
        "equipment_id": 1,
        "technician_id": 2,
        "status": "in-progress",
        "scheduled_date": "2026-05-15",
        "notes": "Offline visit"
      },
      "idempotency_key": "offline-visit-uuid-1",
      "timestamp": 1715775600
    },
    {
      "type": "measurement",
      "action": "create",
      "data": {
        "service_visit_id": 2,
        "equipment_id": 1,
        "measurement_type": "pH",
        "value": 7.1,
        "unit": "pH",
        "status": "normal"
      },
      "idempotency_key": "offline-meas-uuid-1",
      "timestamp": 1715775610
    }
  ]
}
```

**Expected Response (200):**
```json
{
  "status": "success",
  "data": {
    "processed": 2,
    "failed": 0,
    "total": 2,
    "results": [
      {
        "type": "visit",
        "status": "success",
        "id": 2,
        "timestamp": 1715775600
      },
      {
        "type": "measurement",
        "status": "success",
        "id": 2,
        "timestamp": 1715775610
      }
    ]
  }
}
```

### Test Idempotency (Duplicate Submission)

Submit the same queue again (simulating retry on network failure):

**Expected Response (200):**
```json
{
  "status": "success",
  "data": {
    "processed": 1,  // Only the new measurement
    "failed": 1,     // Visit was skipped (duplicate)
    "total": 2,
    "results": [
      {
        "type": "visit",
        "status": "duplicate",  // Same idempotency key
        "id": 2,
        "timestamp": 1715775600
      },
      {
        "type": "measurement",
        "status": "success",
        "id": 3,
        "timestamp": 1715775610
      }
    ]
  }
}
```

---

## Error Testing

### Missing Required Parameter

**POST /api/visits**
```
service_visit_id=&equipment_id=1&...
```

**Expected Response (422):**
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "site_id": "Site ID is required"
  }
}
```

### Not Authenticated

**GET /api/sites** (without login cookie)

**Expected Response (401):**
```json
{
  "status": "error",
  "message": "Unauthorized"
}
```

### Invalid Resource ID

**GET /api/sites/abc**

**Expected Response (400):**
```json
{
  "status": "error",
  "message": "Invalid site ID"
}
```

### Resource Not Found

**GET /api/sites/999**

**Expected Response (404):**
```json
{
  "status": "error",
  "message": "Site not found"
}
```

---

## Debugging Tips

### Enable Debug Logging

Set `APP_DEBUG=true` in `config/app.local.php`:
```php
'app' => [
    'debug' => true,
],
```

Check error logs:
```bash
tail -f error_log.txt
```

### Check POST Arrival Logs

Every controller has a `logPostArrival()` call at the start. Check:
```bash
tail -f debug_log.txt
```

### Test Database Connection

```php
// Create a test file: public/test-db.php
<?php
require_once __DIR__ . '/../app/bootstrap.php';

$stmt = $db->execute("SELECT COUNT(*) as cnt FROM users", [], '');
$result = $stmt->fetch_assoc();
echo "Users: " . $result['cnt'];
```

Then visit: `http://localhost:8000/test-db.php`

---

## Next: Deploy to GoDaddy

Once local testing is complete and all endpoints are working:

1. **Create MySQL database on GoDaddy** via cPanel
2. **Import schema**: Upload `database/migrations/001_initial_schema.sql` and import via cPanel phpMyAdmin
3. **Upload project files** to GoDaddy public_html (or subdomain)
4. **Update app.local.php** with GoDaddy MySQL credentials
5. **Test endpoints** against live GoDaddy server
6. **Configure tablet app** to point to GoDaddy server URL instead of localhost

See `README.md` for detailed GoDaddy deployment steps.
