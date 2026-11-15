<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class StaffPresenceController
{
    #[Route('/admin/staff-presence', name: 'admin_staff_presence', methods: ['GET'])]
    public function index(Request $request, Connection $connection): JsonResponse
    {
        if (!$request->getSession()->has('admin_id')) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $staff = $connection->fetchAllAssociative(
            "SELECT su.id, su.name, su.username, sp.last_seen_at,
                    CASE WHEN sp.last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS is_online
             FROM staff_users su
             LEFT JOIN staff_presence sp ON sp.staff_id = su.id
             WHERE su.status = 'active'
             ORDER BY is_online DESC, su.name ASC"
        );

        return new JsonResponse(['staff' => $staff]);
    }
}
