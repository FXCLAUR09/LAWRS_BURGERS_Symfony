# LAWRS BURGERS — Staff On Duty

## What was added
- Admin Dashboard now lists every active staff account and shows **On Duty** or **Offline**.
- Successful Staff login records the sign-in.
- Staff pages send a CSRF-protected heartbeat every 25 seconds while the page is visible.
- Staff logout records the logout time.
- If a browser is closed, the device loses connection, or heartbeats stop, the account automatically appears Offline after 90 seconds.
- Staff presence is stored separately in `staff_presence`; existing staff accounts and passwords are not changed.

## Required database migration
Run from the project directory using the VS Code integrated terminal:

```bash
docker exec lawrs_burgers_app php bin/console doctrine:migrations:migrate
```

Review the migration prompt and confirm it. The migration creates `staff_presence` with a foreign key to `staff_users`. Do not create the table manually in phpMyAdmin.

Then clear cache:

```bash
docker exec lawrs_burgers_app php bin/console cache:clear
```

Refresh the Admin Dashboard. The on-duty list updates whenever the dashboard is loaded. Presence changes are reflected on the next dashboard refresh.

## Important behavior
“On Duty” means the Staff account is signed in and has sent a heartbeat within the last 90 seconds. This is an application activity indicator, not a guarantee that the person is physically at the workplace. A browser that is closed or disconnected may take up to 90 seconds to become Offline.
