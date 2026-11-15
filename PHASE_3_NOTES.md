# LAWRS BURGERS — Phase 3 Notes

## Staff record ownership and restrictions

### Implemented
- Added nullable `orders.created_by_staff_id` ownership field through Doctrine migration `Version20261115120000`.
- Staff-created back-office orders record the authenticated Staff ID.
- Existing website orders and older records remain `NULL` (legacy/online) and are not automatically assigned to a Staff member.
- Orders list and order details display the creator where available.
- Staff can delete only orders whose `created_by_staff_id` matches their own session Staff ID. Admin can delete any order.
- Order deletion retains the existing transaction, linked notification cleanup, order-item cleanup, and stock-restoration logic.
- Burger catalog remains viewable to Staff, but add/restock/edit/delete actions now require `ROLE_ADMIN`.
- Staff reviews are read-only because customer reviews do not have Staff ownership fields.
- The new order-delete route is allowed through the existing back-office route guard; server-side role and ownership checks remain authoritative.

### Database migration
`migrations/Version20261115120000.php`

The migration adds:
- `created_by_staff_id INT DEFAULT NULL`
- an index on `created_by_staff_id`
- a foreign key to `staff_users(id)` with `ON DELETE SET NULL`

No migration has been executed. Review the migration SQL and take a database backup before applying it.

Suggested review commands from the VS Code terminal:

```powershell
docker exec lawrs_burgers_app php bin/console doctrine:migrations:status
```

```powershell
docker exec lawrs_burgers_app php bin/console doctrine:migrations:migrate --dry-run
```

Only after reviewing and approving the generated SQL, apply the migration with:

```powershell
docker exec lawrs_burgers_app php bin/console doctrine:migrations:migrate
```

## Validation performed
- PHP syntax check passed for all 32 PHP files under `src`, `migrations`, and `config`.
- ZIP archive integrity check performed after packaging.
- Live Docker, database migration, and browser workflow testing were not performed in this environment.
