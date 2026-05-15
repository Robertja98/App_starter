# Task Module Roadmap

## Purpose

Use this file to track planned modules, current implementation status, dependencies, and the next concrete slice of work.

## Project Snapshot

- Project: Water Treatment Service App
- Stack: PHP, MySQL, PWA frontend
- Deployment target: GoDaddy shared hosting
- Primary workflow: Tablet-first onsite service visits with offline-first sync

## Module Status

| Module | Status | Scope | Dependencies | Next Step |
| --- | --- | --- | --- | --- |
| Authentication | In progress | Login, logout, session auth, role checks | `app/bootstrap.php`, `AuthMiddleware`, `AuthController` | Verify end-to-end login flow and protected route redirects |
| Customers | Planned | Customer CRUD and account context | Base model/controller patterns, DB schema | Add controller endpoints and basic list/detail UI |
| Sites | In progress | Site CRUD, customer-linked service locations | `Site` model, customer relationship | Complete controller coverage and validate create/update flows |
| Equipment | In progress | Equipment CRUD, site-linked assets | `Equipment` model, site relationship | Complete controller coverage and confirm field validation |
| Service Visits | In progress | Core inspection workflow, visit records, sync lifecycle | `ServiceVisit` model, auth, CSRF, sync queue | Wire complete API surface and test visit creation/completion |
| Measurements | Planned | Visit measurements and pass/fail capture | Visits, equipment | Add controller and route registration |
| Consumables | Planned | Chemicals/materials used during visits | Visits, billing rules | Add API endpoints and billable summary handling |
| Repair Recommendations | Planned | Suggested repairs and urgency tracking | Visits, equipment | Add endpoints and reporting fields |
| Media Uploads | Planned | Visit photos and related upload/sync behavior | Visits, storage path, sync queue | Define upload endpoint and offline retry behavior |
| Reporting & Billing | Planned | Service summaries, invoice inputs, dashboard metrics | Visits, consumables, repairs | Define report outputs and billing data contract |
| Security Hardening | Planned | Audit logging, backup checks, deployment hardening | Auth, DB, hosting config | Add audit log design and production checklist |

## Current Priorities

1. Finish CRUD/API coverage for Sites, Equipment, and Service Visits.
2. Validate CSRF, auth, and session behavior across all state-changing endpoints.
3. Complete the Phase 5 field workflow modules before widening reporting scope.
4. Preserve the generic bootstrap, security, logging, and validation layers so future apps can reuse this repo as a starter without inheriting service-specific domain assumptions.

## Implementation Rules

- Read `lessons_learned.md` before starting a new feature or module.
- Update this roadmap when module status changes or scope expands.
- Append major implementation results to `WORKLOG.md` after substantial changes.
- Keep module next steps concrete enough to become the next coding task.

## Next Review

- Review after the next completed backend module or any architecture change affecting auth, session, sync, or data model ownership.