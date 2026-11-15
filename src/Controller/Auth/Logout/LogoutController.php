<?php

namespace App\Controller\Auth\Logout;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;

class LogoutController extends AbstractController
{
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(Request $request, Connection $connection): Response
    {
        $session = $request->getSession();

        /*
         * Save the current customer's cart.
         */
        if ($session->has('customer_id')) {

            $customerId = (int) $session->get('customer_id');

            $customerCarts = $session->get('customer_carts', []);

            $customerCarts[$customerId] = $session->get('cart', []);

            $session->set('customer_carts', $customerCarts);
        }

        // Mark staff as offline when they explicitly log out.
        if ($session->has('staff_id')) {
            $connection->executeStatement(
                'UPDATE staff_presence SET logged_out_at = NOW() WHERE staff_id = ?',
                [(int) $session->get('staff_id')]
            );
        }

        /*
         * Remove account login information.
         */
        $session->remove('admin_id');
        $session->remove('admin_username');
        $session->remove('customer_id');
        $session->remove('customer_name');
        $session->remove('customer_email');
        $session->remove('staff_id');
        $session->remove('staff_name');
        $session->remove('staff_username');
        $session->remove('staff_email');

        /*
         * Remove the active cart.
         */
        $session->remove('cart');

        /*
         * Return to login.
         */
        return $this->redirectToRoute('app_login');
    }
}
