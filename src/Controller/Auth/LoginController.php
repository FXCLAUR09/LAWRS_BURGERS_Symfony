<?php

namespace App\Controller\Auth;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        Connection $connection
    ): Response {

        $session = $request->getSession();

        /*
         * If logged in na as admin,
         * mu ditsa na admin dashboard.
         */
        if ($session->has('admin_id')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($session->has('staff_id')) {
            return $this->redirectToRoute('staff_dashboard');
        }

        /*
         * If logged in na as customer,
         * muditsa na sa customer homepage.
         */
        if ($session->has('customer_id')) {
            return $this->redirectToRoute('home');
        }

        $error = '';
        $login = '';

        /*
         * Process login.
         */
        if ($request->isMethod('POST')) {

            /*
             * The same input is used for:
             * - Admin username
             * - Customer email
             */
            $login = trim($request->request->get('login', ''));
            $password = $request->request->get('password', '');

            /*
             * =====================================================
             * BASIC VALIDATION
             * =====================================================
             */

            if ($login === '' || $password === '') {

                $error = 'Please enter your username/email and password.';

            /*
             * Check maximum login length.
             */
            } elseif (strlen($login) > 100) {

                $error = 'Username or email is too long.';

            /*
             * Check maximum password length.
             */
            } elseif (strlen($password) > 255) {

                $error = 'Invalid username/email or password.';

            /*
             * =====================================================
             * EMAIL VALIDATION
             * =====================================================
             */
            } elseif (
                strpos($login, '@') !== false &&
                !filter_var($login, FILTER_VALIDATE_EMAIL)
            ) {

                $error = 'Please enter a valid email address.';

            /*
             * =====================================================
             * USERNAME REGEX VALIDATION
             * =====================================================
             */
            } elseif (
                strpos($login, '@') === false &&
                !preg_match('/^[a-zA-Z0-9_-]+$/', $login)
            ) {

                $error = 'Username contains invalid characters.';

            } else {

                /*
                 * =====================================================
                 * CHECK ADMIN ACCOUNT FIRST
                 * =====================================================
                 */

                try {

                    $admin = $connection->fetchAssociative(
                        "
                            SELECT id, username, password
                            FROM admin_users
                            WHERE username = ?
                            LIMIT 1
                        ",
                        [$login]
                    );

                    if ($admin !== false) {

                        /*
                         * Verify admin password.
                         */
                        if (password_verify($password, $admin['password'])) {

                            /*
                             * Prevent session fixation.
                             */
                            $session->migrate(true);

                            /*
                             * Remove any customer session.
                             */
                            $session->remove('customer_id');
                            $session->remove('customer_name');
                            $session->remove('customer_email');
                            $session->remove('staff_id');
                            $session->remove('staff_name');
                            $session->remove('staff_username');
                            $session->remove('staff_email');

                            /*
                             * Create admin session.
                             */
                            $session->set('admin_id', $admin['id']);
                            $session->set('admin_username', $admin['username']);

                            /*
                             * Send admin to dashboard.
                             */
                            return $this->redirectToRoute('admin_dashboard');

                        } else {

                            $error = 'Invalid username/email or password.';

                        }

                    } else {

                        /*
                         * Check Staff account before checking customers.
                         * Staff accounts are maintained by the Admin.
                         */
                        $staff = $connection->fetchAssociative(
                            "SELECT id, name, username, email, password, status
                             FROM staff_users
                             WHERE username = ? OR email = ?
                             LIMIT 1",
                            [$login, $login]
                        );

                        if ($staff !== false) {
                            if (
                                ($staff['status'] ?? 'inactive') === 'active'
                                && password_verify($password, $staff['password'])
                            ) {
                                $session->migrate(true);
                                foreach ([
                                    'admin_id', 'admin_username',
                                    'customer_id', 'customer_name', 'customer_email',
                                ] as $key) {
                                    $session->remove($key);
                                }
                                $session->set('staff_id', (int) $staff['id']);
                                $session->set('staff_name', $staff['name']);
                                $session->set('staff_username', $staff['username']);
                                $session->set('staff_email', $staff['email']);

                                // Record the successful staff sign-in for the Admin on-duty panel.
                                $connection->executeStatement(
                                    'INSERT INTO staff_presence (staff_id, last_seen_at, logged_in_at) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW(), logged_in_at = NOW()',
                                    [(int) $staff['id']]
                                );

                                return $this->redirectToRoute('staff_dashboard');
                            }

                            $error = 'Invalid username/email or password.';
                        } else {

                        /*
                         * Admin and Staff accounts were not found.
                         * We will now check the customer table.
                         */

                        /*
                         * =====================================================
                         * CHECK CUSTOMER ACCOUNT
                         * =====================================================
                         */

                        $customer = $connection->fetchAssociative(
                            "
                                SELECT id, name, email, password
                                FROM customers
                                WHERE email = ?
                                LIMIT 1
                            ",
                            [$login]
                        );

                        if ($customer !== false) {

                            /*
                             * Verify customer password.
                             */
                            if (password_verify($password, $customer['password'])) {

                                /*
                                 * Prevent session fixation.
                                 */
                                $session->migrate(true);

                                /*
                                 * Remove any admin session.
                                 */
                                $session->remove('admin_id');
                                $session->remove('admin_username');
                                $session->remove('staff_id');
                                $session->remove('staff_name');
                                $session->remove('staff_username');
                                $session->remove('staff_email');

                                /*
                                 * Create customer session.
                                 */
                                $session->set('customer_id', $customer['id']);
                                $session->set('customer_name', $customer['name']);
                                $session->set('customer_email', $customer['email']);

                                /*
                                 * =================================================
                                 * LOAD THIS CUSTOMER'S OWN CART
                                 * =================================================
                                 */

                                if (!$session->has('customer_carts')) {
                                    $session->set('customer_carts', []);
                                }

                                $customerCarts = $session->get('customer_carts', []);

                                $customerId = (int) $customer['id'];

                                if (isset($customerCarts[$customerId])) {

                                    $session->set(
                                        'cart',
                                        $customerCarts[$customerId]
                                    );

                                } else {

                                    $session->set('cart', []);

                                }

                                /*
                                 * =================================================
                                 * RETURN CUSTOMER TO PREVIOUS PAGE
                                 * =================================================
                                 */

                                $redirectAfterLogin = $session->get(
                                    'redirect_after_login',
                                    'index.php'
                                );

                                /*
                                 * Remove the saved redirect so it doesn't
                                 * affect future logins.
                                 */
                                $session->remove('redirect_after_login');

                                /*
                                 * Only allow local PHP pages.
                                 * This prevents unwanted external redirects.
                                 */
                                $allowedRedirects = [
                                    'index.php' => '/',
                                    'checkout.php' => '/checkout',
                                    'cart.php' => '/cart',
                                ];

                                if (!isset($allowedRedirects[$redirectAfterLogin])) {
                                    $redirectAfterLogin = 'index.php';
                                }

                                return $this->redirect(
                                    $allowedRedirects[$redirectAfterLogin]
                                );

                            } else {

                                $error = 'Invalid username/email or password.';

                            }

                        } else {

                            $error = 'Invalid username/email or password.';

                        }
                        }
                    }

                } catch (\Throwable $exception) {

                    $error = 'Unable to process login. Please try again.';

                }
            }
        }

        return $this->render('auth/login.html.twig', [
            'error' => $error,
            'login' => $login,
        ]);
    }
}
