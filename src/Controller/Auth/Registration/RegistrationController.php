<?php

namespace App\Controller\Auth\Registration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, Connection $connection): Response
    {
        $error = '';
        $success = '';

        $name = '';
        $email = '';

        /*
         * Process customer registration.
         */
        if ($request->isMethod('POST')) {

            $name = trim($request->request->get('name', ''));
            $email = trim($request->request->get('email', ''));

            $password = $request->request->get('password', '');
            $confirmPassword = $request->request->get('confirm_password', '');

            /*
             * Validate required fields.
             */
            if (
                $name === '' ||
                $email === '' ||
                $password === '' ||
                $confirmPassword === ''
            ) {

                $error = 'Please fill in all fields.';

            /*
             * Validate name using Regex.
             *
             * Allows letters, spaces, periods,
             * apostrophes, and hyphens.
             */
            } elseif (!preg_match("/^[a-zA-Z .'-]+$/", $name)) {

                $error = 'Name can only contain letters, spaces, periods, apostrophes, and hyphens.';

            /*
             * Validate email.
             */
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

                $error = 'Please enter a valid email address.';

            /*
             * Check password length.
             */
            } elseif (strlen($password) < 6) {

                $error = 'Password must be at least 6 characters long.';

            /*
             * Check maximum password length.
             */
            } elseif (strlen($password) > 255) {

                $error = 'Password is too long.';

            /*
             * Check password confirmation.
             */
            } elseif ($password !== $confirmPassword) {

                $error = 'Passwords do not match.';

            } else {

                try {

                    /*
                     * Check if email already exists.
                     *
                     * Uses the existing customers table.
                     */
                    $existingCustomer = $connection->fetchOne(
                        '
                            SELECT id
                            FROM customers
                            WHERE email = ?
                            LIMIT 1
                        ',
                        [$email]
                    );

                    if ($existingCustomer !== false) {

                        $error = 'An account with this email already exists.';

                    } else {

                        /*
                         * Securely hash the password.
                         */
                        $hashedPassword = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        /*
                         * Insert customer account.
                         *
                         * Uses the existing customers table
                         * and its name, email, and password columns.
                         */
                        $connection->executeStatement(
                            '
                                INSERT INTO customers
                                (name, email, password)
                                VALUES (?, ?, ?)
                            ',
                            [
                                $name,
                                $email,
                                $hashedPassword
                            ]
                        );

                        /*
                         * Registration successful.
                         */
                        $success = 'Account created successfully! You can now log in.';

                        /*
                         * Clear form values.
                         */
                        $name = '';
                        $email = '';
                    }

                } catch (\Throwable $exception) {

                    /*
                     * Keep the original registration error message.
                     */
                    $error = 'Something went wrong while creating your account. Please try again.';
                }
            }
        }

        /*
         * Display the registration page.
         *
         * The Twig template must contain the
         * migrated registration form.
         */
        return $this->render('auth/Register/register.html.twig', [
            'error' => $error,
            'success' => $success,
            'name' => $name,
            'email' => $email,
        ]);
    }
}
