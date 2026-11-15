# Phase 1 — Back-office authorization update

Implemented:
- Added a session-backed Symfony authenticator for existing Admin and Staff sessions.
- Authenticated Admin sessions are checked against `admin_users`.
- Authenticated Staff sessions are checked against `staff_users` and must remain active.
- Added Symfony `access_control` rules:
  - Admin and Staff may use shared burger and order operations.
  - Staff-only routes require `ROLE_STAFF`.
  - All other `/admin` routes require `ROLE_ADMIN`.
- Kept the existing BackOfficeAccessSubscriber as an additional route/session guard.
- Corrected the burger delete route from the root-level `/{id}/delete` to `/admin/burgers/{id}/delete`, while preserving its route name.

Important:
- Existing custom login and password verification remain unchanged.
- No database migration is required for this phase.
- Admin account CRUD and Staff record ownership are not included in this Phase 1 package; they are the next implementation phases.
- Runtime authentication and direct-URL behavior must still be verified against the actual Docker environment.
