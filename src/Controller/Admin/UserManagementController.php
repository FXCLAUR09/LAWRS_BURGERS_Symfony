<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users', name: 'admin_users_')]
final class UserManagementController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, Connection $db): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $term = trim((string) $request->query->get('q', ''));
        $like = '%' . $term . '%';

        $admins = $db->fetchAllAssociative(
            'SELECT id, username, created_at FROM admin_users WHERE username LIKE ? ORDER BY created_at DESC, id DESC',
            [$like]
        );
        $staff = $db->fetchAllAssociative(
            'SELECT id, name, username, email, status, created_at, updated_at FROM staff_users WHERE name LIKE ? OR username LIKE ? OR email LIKE ? ORDER BY created_at DESC, id DESC',
            [$like, $like, $like]
        );

        return $this->render('Admin/users.html.twig', [
            'admins' => $admins,
            'staff' => $staff,
            'query' => $term,
            'total_accounts' => count($admins) + count($staff),
            'admin_username' => $request->getSession()->get('admin_username', 'Admin'),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request, Connection $db): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('create_account', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token. Please try again.');
        }

        $type = (string) $request->request->get('account_type', 'staff');
        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');
        $name = trim((string) $request->request->get('name', ''));
        $email = strtolower(trim((string) $request->request->get('email', '')));

        if (!in_array($type, ['admin', 'staff'], true)) {
            $this->addFlash('error', 'Invalid account type.');
            return $this->redirectToRoute('admin_users_index');
        }
        if (!preg_match($type === 'admin' ? '/^[A-Za-z0-9_-]{3,100}$/' : '/^[A-Za-z0-9_-]{3,50}$/', $username)) {
            $this->addFlash('error', 'Username must be 3–50 characters and contain only letters, numbers, underscores, or hyphens.');
            return $this->redirectToRoute('admin_users_index');
        }
        if (strlen($password) < 8 || strlen($password) > 255) {
            $this->addFlash('error', 'Password must contain at least 8 characters.');
            return $this->redirectToRoute('admin_users_index');
        }

        $duplicateAdmin = $db->fetchOne('SELECT id FROM admin_users WHERE username = ?', [$username]);
        $duplicateStaff = $db->fetchOne('SELECT id FROM staff_users WHERE username = ? OR email = ?', [$username, $username]);
        $duplicateStaffEmail = $type === 'staff' ? $db->fetchOne('SELECT id FROM staff_users WHERE email = ?', [$email]) : false;
        if ($duplicateAdmin || $duplicateStaff || $duplicateStaffEmail) {
            $this->addFlash('error', 'That username is already used by another back-office account.');
            return $this->redirectToRoute('admin_users_index');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($type === 'admin') {
            $db->insert('admin_users', ['username' => $username, 'password' => $hash]);
            $this->addFlash('success', 'Admin account created successfully.');
        } else {
            if ($name === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                $this->addFlash('error', 'Enter a valid staff name and email address.');
                return $this->redirectToRoute('admin_users_index');
            }
            $duplicateEmail = $db->fetchOne('SELECT id FROM staff_users WHERE email = ?', [$email]);
            if ($duplicateEmail || $db->fetchOne('SELECT id FROM admin_users WHERE username = ?', [$email])) {
                $this->addFlash('error', 'That email address is already in use.');
                return $this->redirectToRoute('admin_users_index');
            }
            $db->insert('staff_users', [
                'name' => $name, 'username' => $username, 'email' => $email,
                'password' => $hash, 'status' => 'active',
            ]);
            $this->addFlash('success', 'Staff account created successfully.');
        }

        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{type}/{id}/edit', name: 'edit', requirements: ['type' => 'admin|staff', 'id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(string $type, int $id, Request $request, Connection $db): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!in_array($type, ['admin', 'staff'], true)) {
            throw $this->createNotFoundException();
        }
        $table = $type === 'admin' ? 'admin_users' : 'staff_users';
        $account = $db->fetchAssociative("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if (!$account) {
            throw $this->createNotFoundException('Account not found.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_' . $type . '_' . $id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid form token. Please try again.');
            }
            $username = trim((string) $request->request->get('username', ''));
            $password = (string) $request->request->get('password', '');
            if (!preg_match($type === 'admin' ? '/^[A-Za-z0-9_-]{3,100}$/' : '/^[A-Za-z0-9_-]{3,50}$/', $username)) {
                $this->addFlash('error', 'Enter a valid username.');
                return $this->redirectToRoute('admin_users_edit', ['type' => $type, 'id' => $id]);
            }
            $otherAdmin = $db->fetchOne('SELECT id FROM admin_users WHERE username = ? AND id <> ?', [$username, $type === 'admin' ? $id : 0]);
            $otherStaff = $db->fetchOne('SELECT id FROM staff_users WHERE username = ? AND id <> ?', [$username, $type === 'staff' ? $id : 0]);
            if ($otherAdmin || $otherStaff) {
                $this->addFlash('error', 'That username is already taken.');
                return $this->redirectToRoute('admin_users_edit', ['type' => $type, 'id' => $id]);
            }

            $changes = ['username' => $username];
            if ($type === 'staff') {
                $name = trim((string) $request->request->get('name', ''));
                $email = strtolower(trim((string) $request->request->get('email', '')));
                if ($name === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                    $this->addFlash('error', 'Enter a valid name and email address.');
                    return $this->redirectToRoute('admin_users_edit', ['type' => $type, 'id' => $id]);
                }
                $emailOwner = $db->fetchOne('SELECT id FROM staff_users WHERE email = ? AND id <> ?', [$email, $id]);
                if ($emailOwner) {
                    $this->addFlash('error', 'That email address is already used by another staff member.');
                    return $this->redirectToRoute('admin_users_edit', ['type' => $type, 'id' => $id]);
                }
                $changes += ['name' => $name, 'email' => $email];
            }
            if ($password !== '') {
                if (strlen($password) < 8 || strlen($password) > 255) {
                    $this->addFlash('error', 'New password must contain at least 8 characters.');
                    return $this->redirectToRoute('admin_users_edit', ['type' => $type, 'id' => $id]);
                }
                $changes['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $db->update($table, $changes, ['id' => $id]);
            $this->addFlash('success', ucfirst($type) . ' account updated successfully.');
            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('Admin/user_edit.html.twig', [
            'type' => $type, 'account' => $account,
            'admin_username' => $request->getSession()->get('admin_username', 'Admin'),
        ]);
    }

    #[Route('/staff/{id}/status', name: 'staff_status', methods: ['POST'])]
    public function toggleStaffStatus(int $id, Request $request, Connection $db): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('staff_status_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $staff = $db->fetchAssociative('SELECT id, status FROM staff_users WHERE id = ?', [$id]);
        if (!$staff) {
            throw $this->createNotFoundException('Staff account not found.');
        }
        $next = $staff['status'] === 'active' ? 'inactive' : 'active';
        $db->update('staff_users', ['status' => $next], ['id' => $id]);
        if ($next === 'inactive') {
            $db->executeStatement('UPDATE staff_presence SET logged_out_at = NOW() WHERE staff_id = ?', [$id]);
        }
        $this->addFlash('success', 'Staff account is now ' . $next . '.');
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{type}/{id}/delete', name: 'delete', requirements: ['type' => 'admin|staff', 'id' => '\\d+'], methods: ['POST'])]
    public function delete(string $type, int $id, Request $request, Connection $db): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!in_array($type, ['admin', 'staff'], true) || !$this->isCsrfTokenValid('delete_' . $type . '_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid delete request.');
        }
        $session = $request->getSession();
        if ($type === 'admin' && (int) $session->get('admin_id') === $id) {
            $this->addFlash('error', 'You cannot delete the Admin account currently signed in.');
            return $this->redirectToRoute('admin_users_index');
        }
        if ($type === 'admin') {
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM admin_users');
            if ($count <= 1) {
                $this->addFlash('error', 'The last Admin account cannot be deleted.');
                return $this->redirectToRoute('admin_users_index');
            }
            $db->delete('admin_users', ['id' => $id]);
        } else {
            $db->delete('staff_users', ['id' => $id]);
        }
        $this->addFlash('success', ucfirst($type) . ' account deleted.');
        return $this->redirectToRoute('admin_users_index');
    }
}
