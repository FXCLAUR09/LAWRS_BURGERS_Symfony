# Lawr's Burgers — Staff Operations Update

## Included changes

- Added Staff login using the existing `staff_users` table (`username` or `email`, password hash, and `status = active`).
- Added a Staff dashboard with burger, order, pending-order, and review counts plus recent orders.
- Added a Staff-only Reviews page to view all reviews and delete reviews using CSRF protection.
- Staff can use the existing burger management pages (view, add, edit, restock, delete).
- Staff can use existing order viewing, status updates, and payment verification.
- Added Staff order creation using an existing customer account and current burger prices/stock.
- Added Staff order deletion. Reserved stock is restored when an order is deleted, except when the order was already cancelled (the existing cancellation flow restores it).
- Added server-side role checks: Staff cannot access Admin dashboard, reports, or history; Admin cannot access Staff-only routes.
- Updated logout to clear Staff session data.
- Standardized Admin Twig template path casing to match the actual `templates/Admin/` directory for Linux/Docker.
- Updated shared navigation and page labels so Staff sees Staff navigation instead of Admin-only links.

## Expected existing database objects

This update does not add or alter database tables. It expects the existing project database to already contain:
- `staff_users` with `id`, `name`, `username`, `email`, `password`, and `status`
- `burgers`
- `customers`
- `orders`
- `order_items`
- `reviews`
- `notifications`

The Staff order creation form requires selecting an existing customer account. It stores the selected `customer_id` and creates order items using the current burger prices. It does not create customer accounts.

## Apply and verify in Docker

From the project folder:

```powershell
docker compose up -d --build
docker exec lawrs_burgers_app php bin/console cache:clear
docker exec lawrs_burgers_app php bin/console debug:router
```

Then test:
1. Log in with an active Staff account.
2. Confirm the Staff dashboard opens.
3. Open Burgers and test view/add/edit/restock/delete.
4. Open Orders and test view/status/payment updates, create an order, and delete a test order.
5. Open Reviews and delete a test review.
6. While logged in as Staff, manually visit `/admin/dashboard`, `/admin/reports`, and `/admin/history`; these should be denied/redirected.
7. Log in as Admin and confirm Admin dashboard, reports, and history remain available.
8. Test the customer login, review submission, and checkout flow.

## Validation performed here

- PHP syntax validation (`php -l`) passed for every PHP file under `src/`.
- The live Docker database and Symfony runtime were not available in this workspace, so database integration, route compilation, Twig rendering, and browser testing still need to be run in the user's Docker environment.
