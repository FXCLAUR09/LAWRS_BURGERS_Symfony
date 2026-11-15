<?php

namespace App\Controller\Notification;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class NotificationController extends AbstractController
{
    /*
     * =========================================================
     * GET CUSTOMER NOTIFICATIONS
     * =========================================================
     */

    #[Route(
        '/notifications',
        name: 'customer_notifications',
        methods: ['GET']
    )]
    public function index(
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {

        $session = $request->getSession();

        if (!$session->has('customer_id')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer login is required.',
            ], 401);
        }

        $customerId = (int) $session->get('customer_id');

        if ($customerId <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid customer session.',
            ], 401);
        }

        /*
         * Retrieve Existing Notifications
         */

        $notifications = $connection->fetchAllAssociative(
            'SELECT
                id,
                customer_id,
                order_id,
                title,
                message,
                is_read,
                created_at
             FROM notifications
             WHERE customer_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 20',
            [$customerId]
        );

        /*
         * Generate CSRF Tokens for Each Notification
         */

        foreach ($notifications as &$notification) {
            $id = (int) $notification['id'];

            $notification['read_token'] = $csrfTokenManager
                ->getToken('notification_read_' . $id)
                ->getValue();

            $notification['delete_token'] = $csrfTokenManager
                ->getToken('notification_delete_' . $id)
                ->getValue();
        }

        unset($notification);

        /*
         * Count Unread Notifications
         */

        $unreadCount = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM notifications
             WHERE customer_id = ?
             AND is_read = 0',
            [$customerId]
        );

        /*
         * Return Notification Data
         */

        return new JsonResponse([
            'success' => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }


    /*
     * =========================================================
     * MARK NOTIFICATION AS READ
     * =========================================================
     */

    #[Route(
        '/notifications/{id}/read',
        name: 'customer_notification_read',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function markAsRead(
        int $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {

        $session = $request->getSession();

        if (!$session->has('customer_id')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer login is required.',
            ], 401);
        }

        $customerId = (int) $session->get('customer_id');

        if ($customerId <= 0 || $id <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid notification request.',
            ], 400);
        }

        $token = (string) $request->request->get('_token', '');

        if (!$csrfTokenManager->isTokenValid(
            new CsrfToken('notification_read_' . $id, $token)
        )) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid security token.',
            ], 403);
        }

        $affectedRows = $connection->executeStatement(
            'UPDATE notifications
             SET is_read = 1
             WHERE id = ?
             AND customer_id = ?
             AND is_read = 0',
            [$id, $customerId]
        );

        $unreadCount = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM notifications
             WHERE customer_id = ?
             AND is_read = 0',
            [$customerId]
        );

        return new JsonResponse([
            'success' => true,
            'message' => $affectedRows > 0
                ? 'Notification marked as read.'
                : 'Notification is already read or unavailable.',
            'unread_count' => $unreadCount,
        ]);
    }


    /*
     * =========================================================
     * DELETE CUSTOMER NOTIFICATION
     * =========================================================
     */

    #[Route(
        '/notifications/{id}/delete',
        name: 'customer_notification_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function delete(
        int $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {

        $session = $request->getSession();

        if (!$session->has('customer_id')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Customer login is required.',
            ], 401);
        }

        $customerId = (int) $session->get('customer_id');

        if ($customerId <= 0 || $id <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid notification request.',
            ], 400);
        }

        $token = (string) $request->request->get('_token', '');

        if (!$csrfTokenManager->isTokenValid(
            new CsrfToken('notification_delete_' . $id, $token)
        )) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid security token.',
            ], 403);
        }

        $affectedRows = $connection->executeStatement(
            'DELETE FROM notifications
             WHERE id = ?
             AND customer_id = ?',
            [$id, $customerId]
        );

        if ($affectedRows === 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $unreadCount = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM notifications
             WHERE customer_id = ?
             AND is_read = 0',
            [$customerId]
        );

        return new JsonResponse([
            'success' => true,
            'message' => 'Notification deleted successfully.',
            'unread_count' => $unreadCount,
        ]);
    }
}
