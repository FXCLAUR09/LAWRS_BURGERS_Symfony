<?php

namespace App\Controller\Staff;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PresenceController extends AbstractController
{
    #[Route('/staff/orders/pending-count', name: 'staff_orders_pending_count', methods: ['GET'])]
    public function pendingOrdersCount(
        Request $request,
        Connection $connection
    ): JsonResponse {
        $staffId = (int) $request->getSession()->get('staff_id', 0);
        if ($staffId <= 0) {
            return $this->json(['ok' => false, 'count' => 0], 401);
        }

        $active = $connection->fetchOne(
            "SELECT COUNT(*) FROM staff_users WHERE id = ? AND status = 'active'",
            [$staffId]
        );
        if (!(int) $active) {
            return $this->json(['ok' => false, 'count' => 0], 403);
        }

        // Shared indicator: it stays visible while any order is still Pending.
        // It disappears for every Staff member once the order is processed.
        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE status = 'Pending'"
        );

        return $this->json(['ok' => true, 'count' => $count]);
    }

    #[Route('/staff/presence/heartbeat', name: 'staff_presence_heartbeat', methods: ['POST'])]
    public function heartbeat(
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {
        $session = $request->getSession();
        $staffId = (int) $session->get('staff_id', 0);
        if ($staffId <= 0) {
            return $this->json(['ok' => false], 401);
        }

        $submittedToken = (string) $request->headers->get('X-CSRF-TOKEN', '');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('staff_presence', $submittedToken))) {
            return $this->json(['ok' => false], 403);
        }

        $active = $connection->fetchOne(
            "SELECT COUNT(*) FROM staff_users WHERE id = ? AND status = 'active'",
            [$staffId]
        );
        if (!(int) $active) {
            $session->invalidate();
            return $this->json(['ok' => false], 403);
        }

        $connection->executeStatement(
            'INSERT INTO staff_presence (staff_id, last_seen_at, logged_in_at, logged_out_at) VALUES (?, NOW(), NOW(), NULL) ON DUPLICATE KEY UPDATE last_seen_at = NOW(), logged_out_at = NULL',
            [$staffId]
        );

        return $this->json(['ok' => true]);
    }
}
