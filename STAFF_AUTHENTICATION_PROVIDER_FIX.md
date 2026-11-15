# Staff shared-page authentication fix

## Reported issue
Staff users were being logged out or seeing:
"There is no user provider for user App\\Security\\BackOfficeUser..."

## Root cause
The custom session authenticator created `BackOfficeUser` objects, but the Symfony firewall had no configured user provider capable of refreshing that user from the security token stored in the session. This can surface on subsequent requests and appear as intermittent logout or provider errors.

## Fix
- Added `App\\Security\\BackOfficeUserProvider`.
- Implemented `loadUserByIdentifier()`, `refreshUser()`, and `supportsClass()`.
- Configured `backoffice_users` as the main firewall's provider.
- Admin identifiers are resolved against `admin_users`.
- Staff identifiers are resolved against `staff_users`; inactive/missing Staff accounts are rejected.
- Existing Admin/Staff roles and session login flow are preserved.

## After replacing the project
Run:
```bash
docker exec lawrs_burgers_app php bin/console cache:clear
docker exec lawrs_burgers_app php bin/console debug:config security
```
Then log out once, log back in as Staff, and test Burgers, Orders, and Reviews.

No database migration is required. Static PHP syntax and YAML parsing were checked. Live Docker/browser testing remains necessary.
