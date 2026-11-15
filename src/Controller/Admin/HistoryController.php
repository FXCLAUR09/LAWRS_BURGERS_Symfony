<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HistoryController extends AbstractController
{
    /**
     * Display completed and cancelled orders.
     */
    #[Route('/admin/history', name: 'admin_history', methods: ['GET'])]
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
         * SEARCH AND FILTER
         * --------------------------------------------------
         */

        $search = trim((string) $request->query->get('search', ''));

        $filterStatus = trim(
            (string) $request->query->get('status', 'All')
        );

        if (!in_array($filterStatus, ['All', 'Completed', 'Cancelled'], true)) {
            $filterStatus = 'All';
        }

        /*
         * --------------------------------------------------
         * BUILD HISTORY QUERY
         * --------------------------------------------------
         *
         * History contains only:
         * - Completed orders
         * - Cancelled orders
         *
         */

        $sql = '
            SELECT
                id,
                customer_name,
                phone,
                payment_method,
                payment_status,
                total,
                status,
                created_at,
                updated_at
            FROM orders
            WHERE status IN (?, ?)
        ';

        $params = ['Completed', 'Cancelled'];

        /*
         * --------------------------------------------------
         * SEARCH
         * --------------------------------------------------
         */

        if ($search !== '') {
            $sql .= '
                AND (
                    CAST(id AS CHAR) LIKE ?
                    OR customer_name LIKE ?
                    OR phone LIKE ?
                )
            ';

            $searchValue = '%' . $search . '%';

            $params[] = $searchValue;
            $params[] = $searchValue;
            $params[] = $searchValue;
        }

        /*
         * --------------------------------------------------
         * STATUS FILTER
         * --------------------------------------------------
         */

        if ($filterStatus === 'Completed' || $filterStatus === 'Cancelled') {
            $sql .= ' AND status = ?';

            $params[] = $filterStatus;
        }

        /*
         * --------------------------------------------------
         * ORDER SORTING
         * --------------------------------------------------
         */

        $sql .= '
            ORDER BY
                COALESCE(updated_at, created_at) DESC,
                id DESC
        ';

        /*
         * --------------------------------------------------
         * EXECUTE QUERY
         * --------------------------------------------------
         */

        $historyOrders = $connection->fetchAllAssociative(
            $sql,
            $params
        );

        /*
         * --------------------------------------------------
         * HISTORY COUNTS
         * --------------------------------------------------
         */

        $totalHistory = count($historyOrders);

        $completedCount = 0;
        $cancelledCount = 0;

        foreach ($historyOrders as $historyOrder) {
            if ($historyOrder['status'] === 'Completed') {
                $completedCount++;
            }

            if ($historyOrder['status'] === 'Cancelled') {
                $cancelledCount++;
            }
        }

        /*
         * --------------------------------------------------
         * RENDER HISTORY PAGE
         * --------------------------------------------------
         */

        return $this->render('Admin/history.html.twig', [
            'history_orders' => $historyOrders,
            'total_history' => $totalHistory,
            'completed_count' => $completedCount,
            'cancelled_count' => $cancelledCount,
            'search' => $search,
            'filter_status' => $filterStatus,
            'admin_username' => $adminUsername,
        ]);
    }
}
