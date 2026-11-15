<?php

namespace App\Controller\Profile\EditProfile;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EditProfileController extends AbstractController
{
    #[Route('/profile/edit', name: 'profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Connection $connection
    ): Response {
        $session = $request->getSession();

        // Keep the rest of your existing edit profile code here.

        // Check if customer is logged in.
        if (!$session->has('customer_id')) {
            return $this->redirectToRoute('app_login');
        }

        $customerId = (int) $session->get('customer_id');

        // Retrieve the current customer's information.
        $customer = $connection->fetchAssociative(
            'SELECT id, name, email, password
             FROM customers
             WHERE id = ?
             LIMIT 1',
            [$customerId]
        );

        if (!$customer) {
            $session->remove('customer_id');
            $session->remove('customer_name');
            $session->remove('customer_email');

            return $this->redirectToRoute('app_login');
        }

        $errors = [];

        $formData = [
            'name' => $customer['name'],
            'email' => $customer['email'],
        ];

        if ($request->isMethod('POST')) {

            // Validate CSRF token.
            if (!$this->isCsrfTokenValid(
                'edit_profile',
                $request->request->get('_token')
            )) {
                $errors[] = 'Invalid form submission. Please try again.';
            }

            $name = trim((string) $request->request->get('name', ''));
            $email = trim((string) $request->request->get('email', ''));

            $newPassword = (string) $request->request->get('new_password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            $formData = [
                'name' => $name,
                'email' => $email,
            ];

            // Validate full name.
            if ($name === '') {
                $errors[] = 'Full name is required.';
            } elseif (strlen($name) > 150) {
                $errors[] = 'Full name must not exceed 150 characters.';
            } elseif (!preg_match("/^[a-zA-Z .'-]+$/", $name)) {
                $errors[] = 'Full name contains invalid characters.';
            }

            // Validate email.
            if ($email === '') {
                $errors[] = 'Email address is required.';
            } elseif (strlen($email) > 150) {
                $errors[] = 'Email address must not exceed 150 characters.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }

            // Check if the email belongs to another customer.
            if (
                $email !== '' &&
                filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                $existingCustomer = $connection->fetchAssociative(
                    'SELECT id
                     FROM customers
                     WHERE email = ? AND id != ?
                     LIMIT 1',
                    [$email, $customerId]
                );

                if ($existingCustomer) {
                    $errors[] = 'This email address is already being used.';
                }
            }

            // Validate optional password change.
            if ($newPassword !== '') {
                if (strlen($newPassword) < 6 || strlen($newPassword) > 255) {
                    $errors[] = 'New password must be between 6 and 255 characters.';
                }

                if ($newPassword !== $confirmPassword) {
                    $errors[] = 'New password and confirmation do not match.';
                }
            } elseif ($confirmPassword !== '') {
                $errors[] = 'Please enter a new password first.';
            }

            // Save changes if there are no validation errors.
            if (empty($errors)) {

                if ($newPassword !== '') {
                    $hashedPassword = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $connection->executeStatement(
                        'UPDATE customers
                         SET name = ?, email = ?, password = ?
                         WHERE id = ?',
                        [$name, $email, $hashedPassword, $customerId]
                    );

                } else {
                    $connection->executeStatement(
                        'UPDATE customers
                         SET name = ?, email = ?
                         WHERE id = ?',
                        [$name, $email, $customerId]
                    );
                }

                // Update session information.
                $session->set('customer_name', $name);
                $session->set('customer_email', $email);

                $this->addFlash(
                    'success',
                    'Your profile has been updated successfully!'
                );

                return $this->redirectToRoute('home');
            }
        }

        return $this->render('Home/Profile/edit_profile.html.twig', [
            'customer' => $formData,
            'errors' => $errors,
        ]);
    }
}
