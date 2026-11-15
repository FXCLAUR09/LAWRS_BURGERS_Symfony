<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportsController extends AbstractController
{
    #[Route('/admin/reports', name: 'admin_reports', methods: ['GET'])]
    public function index(
        Connection $connection,
        Request $request
    ): Response {

        /*
         * --------------------------------------------------
         * ADMIN INFORMATION
         * --------------------------------------------------
         */

        $adminUsername = $request->getSession()->get(
            'admin_username',
            'Admin'
        );


        /*
         * --------------------------------------------------
         * TOTAL SALES
         * Cancelled orders are excluded.
         * --------------------------------------------------
         */

        $totalSales = $connection->fetchOne("
            SELECT COALESCE(SUM(total), 0)
            FROM orders
            WHERE status != 'Cancelled'
        ");

        $totalSales = (float) $totalSales;


        /*
         * --------------------------------------------------
         * TOTAL ORDERS
         * --------------------------------------------------
         */

        $totalOrders = (int) $connection->fetchOne("
            SELECT COUNT(*)
            FROM orders
        ");


        /*
         * --------------------------------------------------
         * COMPLETED ORDERS
         * --------------------------------------------------
         */

        $completedOrders = (int) $connection->fetchOne("
            SELECT COUNT(*)
            FROM orders
            WHERE status = 'Completed'
        ");


        /*
         * --------------------------------------------------
         * CANCELLED ORDERS
         * --------------------------------------------------
         */

        $cancelledOrders = (int) $connection->fetchOne("
            SELECT COUNT(*)
            FROM orders
            WHERE status = 'Cancelled'
        ");


        /*
         * --------------------------------------------------
         * ACTIVE ORDERS
         * --------------------------------------------------
         */

        $activeOrders = (int) $connection->fetchOne("
            SELECT COUNT(*)
            FROM orders
            WHERE status NOT IN ('Completed', 'Cancelled')
        ");


        /*
         * --------------------------------------------------
         * BEST-SELLING BURGERS
         * Top 5 based on quantity sold.
         * Cancelled orders are excluded.
         * --------------------------------------------------
         */

        $bestSellers = $connection->fetchAllAssociative("
            SELECT
                oi.burger_name,
                SUM(oi.quantity) AS total_quantity,
                SUM(oi.subtotal) AS total_sales
            FROM order_items oi
            INNER JOIN orders o
                ON oi.order_id = o.id
            WHERE o.status != 'Cancelled'
            GROUP BY oi.burger_id, oi.burger_name
            ORDER BY total_quantity DESC
            LIMIT 5
        ");


        /*
         * --------------------------------------------------
         * DAILY SALES
         * Latest 7 days with orders.
         * Cancelled orders are excluded.
         * --------------------------------------------------
         */

        $dailySales = $connection->fetchAllAssociative("
            SELECT
                DATE(created_at) AS sale_date,
                COUNT(*) AS order_count,
                SUM(total) AS daily_sales
            FROM orders
            WHERE status != 'Cancelled'
            GROUP BY DATE(created_at)
            ORDER BY sale_date DESC
            LIMIT 7
        ");


        /*
         * --------------------------------------------------
         * MONTHLY SALES
         * Latest 6 months with orders.
         * Cancelled orders are excluded.
         * --------------------------------------------------
         */

        $monthlySales = $connection->fetchAllAssociative("
            SELECT
                DATE_FORMAT(created_at, '%Y-%m') AS sale_month,
                COUNT(*) AS order_count,
                SUM(total) AS monthly_sales
            FROM orders
            WHERE status != 'Cancelled'
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY sale_month DESC
            LIMIT 6
        ");


        /*
         * --------------------------------------------------
         * BURGER STOCK REPORT
         * --------------------------------------------------
         */

        $stockReport = $connection->fetchAllAssociative("
            SELECT
                id,
                name,
                category,
                stock_quantity,
                available
            FROM burgers
            ORDER BY stock_quantity ASC, name ASC
        ");


        /*
         * --------------------------------------------------
         * LOW STOCK COUNT
         * Stock quantity of 10 or below.
         * --------------------------------------------------
         */

        $lowStock = (int) $connection->fetchOne("
            SELECT COUNT(*)
            FROM burgers
            WHERE stock_quantity <= 10
        ");


        /*
         * --------------------------------------------------
         * RENDER REPORTS PAGE
         * --------------------------------------------------
         */

        return $this->render('Admin/reports.html.twig', [

            'admin_username' => $adminUsername,

            'total_sales' => $totalSales,
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'active_orders' => $activeOrders,
            'low_stock' => $lowStock,

            'best_sellers' => $bestSellers,
            'daily_sales' => $dailySales,
            'monthly_sales' => $monthlySales,
            'stock_report' => $stockReport,

        ]);
    }
}
