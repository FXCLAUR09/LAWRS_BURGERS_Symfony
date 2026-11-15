<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/admin/dashboard', name: 'admin_dashboard')]
    public function index(
        Connection $connection,
        Request $request
    ): Response {
        // Protect dashboard using the existing admin session.
        $session = $request->getSession();  

        $adminUsername = $session->get('admin_username', 'Admin');

        // Dashboard statistics
        $totalBurgers = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM burgers'
        );

        $availableBurgers = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM burgers WHERE available = 1'
        );

        $totalOrders = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM orders'
        );

        $pendingOrders = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE status = 'Pending'"
        );

        $totalSales = (float) $connection->fetchOne(
            'SELECT COALESCE(SUM(total), 0) FROM orders'
        );

        $todaySales = (float) $connection->fetchOne(
            'SELECT COALESCE(SUM(total), 0)
             FROM orders
             WHERE DATE(created_at) = CURDATE()'
        );

        // Database-backed chart data: sales for the last seven calendar days.
        $salesTrend = $connection->fetchAllAssociative(
            "SELECT DATE(created_at) AS day, COALESCE(SUM(total), 0) AS sales
             FROM orders
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day ASC"
        );

        $orderStatusRows = $connection->fetchAllAssociative(
            'SELECT status, COUNT(*) AS total FROM orders GROUP BY status ORDER BY status ASC'
        );

        $completedOrders = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE LOWER(status) IN ('completed', 'delivered')"
        );
        $completionRate = $totalOrders > 0
            ? (int) round(($completedOrders / $totalOrders) * 100)
            : 0;
        $availabilityRate = $totalBurgers > 0
            ? (int) round(($availableBurgers / $totalBurgers) * 100)
            : 0;
        $pendingRate = $totalOrders > 0
            ? (int) round(($pendingOrders / $totalOrders) * 100)
            : 0;

        // Staff currently on duty: heartbeat must be no older than 90 seconds.
        // A stale heartbeat automatically becomes Offline if a browser is closed or disconnected.
        $staffOnDuty = $connection->fetchAllAssociative(
            "SELECT su.id, su.name, su.username, su.status, sp.last_seen_at, sp.logged_in_at,
                    CASE WHEN sp.last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS is_online
             FROM staff_users su
             LEFT JOIN staff_presence sp ON sp.staff_id = su.id
             WHERE su.status = 'active'
             ORDER BY is_online DESC, su.name ASC"
        );

        // Latest five orders
        $recentOrders = $connection->fetchAllAssociative(
            'SELECT id, customer_name, phone, total, status, created_at
             FROM orders
             ORDER BY created_at DESC
             LIMIT 5'
        );

        // Five burgers with the lowest stock
        $lowStockBurgers = $connection->fetchAllAssociative(
            'SELECT id, name, stock_quantity, available
             FROM burgers
             ORDER BY stock_quantity ASC
             LIMIT 5'
        );

        return $this->render('Admin/dashboard.html.twig', [
            'admin_username' => $adminUsername,
            'total_burgers' => $totalBurgers,
            'available_burgers' => $availableBurgers,
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'total_sales' => $totalSales,
            'today_sales' => $todaySales,
            'recent_orders' => $recentOrders,
            'low_stock_burgers' => $lowStockBurgers,
            'sales_trend' => $salesTrend,
            'order_status_rows' => $orderStatusRows,
            'completion_rate' => $completionRate,
            'availability_rate' => $availabilityRate,
            'pending_rate' => $pendingRate,
            'staff_on_duty' => $staffOnDuty,
        ]);
    }
}
