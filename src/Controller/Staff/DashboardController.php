<?php

namespace App\Controller\Staff;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/staff/dashboard', name: 'staff_dashboard', methods: ['GET'])]
    public function index(Connection $connection, Request $request): Response
    {
        $staffId = (int) $request->getSession()->get('staff_id');
        $staff = $connection->fetchAssociative(
            "SELECT id, name, username, status FROM staff_users WHERE id = ? LIMIT 1",
            [$staffId]
        );

        if (!$staff || $staff['status'] !== 'active') {
            $request->getSession()->invalidate();
            return $this->redirectToRoute('app_login');
        }

        $stats = [
            'burgers' => (int) $connection->fetchOne('SELECT COUNT(*) FROM burgers'),
            'orders' => (int) $connection->fetchOne('SELECT COUNT(*) FROM orders'),
            'pending' => (int) $connection->fetchOne("SELECT COUNT(*) FROM orders WHERE status = 'Pending'"),
            'reviews' => (int) $connection->fetchOne('SELECT COUNT(*) FROM reviews'),
        ];

        $completed = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE LOWER(status) IN ('completed', 'delivered')"
        );
        $available = (int) $connection->fetchOne('SELECT COUNT(*) FROM burgers WHERE available = 1');
        $totalBurgers = $stats['burgers'];
        $totalOrders = $stats['orders'];
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

        $recentOrders = $connection->fetchAllAssociative(
            'SELECT id, customer_name, total, status, created_at FROM orders ORDER BY created_at DESC LIMIT 6'
        );

        return $this->render('Staff/dashboard.html.twig', [
            'staff' => $staff,
            'stats' => $stats,
            'recent_orders' => $recentOrders,
            'sales_trend' => $salesTrend,
            'order_status_rows' => $orderStatusRows,
            'completion_rate' => $totalOrders > 0 ? (int) round(($completed / $totalOrders) * 100) : 0,
            'availability_rate' => $totalBurgers > 0 ? (int) round(($available / $totalBurgers) * 100) : 0,
            'pending_rate' => $totalOrders > 0 ? (int) round(($stats['pending'] / $totalOrders) * 100) : 0,
        ]);
    }
}
