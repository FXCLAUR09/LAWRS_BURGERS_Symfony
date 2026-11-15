<?php

namespace App\Controller\Checkout;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'checkout', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        Connection $connection,
        LoggerInterface $logger
    ): Response {

        $session = $request->getSession();

        /*
         * Require Customer Login Before Checkout
         */
        if (!$session->has('customer_id')) {
            $session->set('redirect_after_login', 'checkout.php');

            return $this->redirectToRoute('app_login');
        }

        /*
         * Get Logged-In Customer Information
         */
        $customerId = (int) $session->get('customer_id');

        $customer = $connection->fetchAssociative(
            'SELECT name, email FROM customers WHERE id = ? LIMIT 1',
            [$customerId]
        );

        if (!$customer) {
            $session->remove('customer_id');
            $session->remove('customer_name');
            $session->remove('customer_email');

            return $this->redirectToRoute('app_login');
        }

        $customerName = $customer['name'];

        /*
         * Make Sure the Cart Exists
         */
        $cart = $session->get('cart', []);

        if (!is_array($cart)) {
            $cart = [];
            $session->set('cart', []);
        }

        /*
         * Initialize Variables
         */
        $error = '';
        $orderSuccess = false;
        $orderId = null;

        $form = [
            'full_name' => $customerName,
            'contact_number' => '',
            'address' => '',
            'payment_method' => '',
            'gcash_reference' => '',
        ];

        /*
         * Calculate Cart Total
         */
        $total = 0;

        foreach ($cart as $item) {
            $price = isset($item['price'])
                ? (float) $item['price']
                : 0;

            $quantity = isset($item['quantity'])
                ? (int) $item['quantity']
                : 0;

            $total += $price * $quantity;
        }

        /*
         * Process Order
         */
        if ($request->isMethod('POST')) {

            /*
             * Get Customer Information
             */
            $form['full_name'] = trim(
                $request->request->get('full_name', '')
            );

            $form['contact_number'] = trim(
                $request->request->get('contact_number', '')
            );

            $form['address'] = trim(
                $request->request->get('address', '')
            );

            $form['payment_method'] = trim(
                $request->request->get('payment_method', '')
            );

            $form['gcash_reference'] = trim(
                $request->request->get('gcash_reference', '')
            );

            /*
             * Validate Cart and Customer Information
             */
            if (count($cart) === 0) {

                $error = 'Your cart is empty. Please add a burger before checking out.';

            } elseif ($form['full_name'] === '') {

                $error = 'Please enter your full name.';

            } elseif ($form['contact_number'] === '') {

                $error = 'Please enter your contact number.';

            } elseif ($form['address'] === '') {

                $error = 'Please enter your delivery address.';

            } elseif (
                $form['payment_method'] !== 'Cash on Delivery' &&
                $form['payment_method'] !== 'GCash'
            ) {

                $error = 'Please select a valid payment method.';

            } elseif (
                $form['payment_method'] === 'GCash' &&
                $form['gcash_reference'] === ''
            ) {

                $error = 'Please enter your GCash reference number.';

            } else {

                /*
                 * Start Database Transaction
                 */
                $connection->beginTransaction();

                try {

                    /*
                     * Recalculate Order Total Using Database Prices
                     */
                    $orderTotal = 0;
                    $orderItems = [];

                    foreach ($cart as $item) {

                        $quantity = isset($item['quantity'])
                            ? (int) $item['quantity']
                            : 0;

                        if ($quantity <= 0) {
                            throw new \Exception(
                                'Invalid quantity found in your cart.'
                            );
                        }

                        /*
                         * Get Burger ID
                         */
                        if (isset($item['id'])) {

                            $burgerId = (int) $item['id'];

                        } elseif (isset($item['burger_id'])) {

                            $burgerId = (int) $item['burger_id'];

                        } else {

                            $burgerNameSearch = isset($item['name'])
                                ? $item['name']
                                : '';

                            $burgerId = $connection->fetchOne(
                                'SELECT id FROM burgers WHERE name = ? LIMIT 1 FOR UPDATE',
                                [$burgerNameSearch]
                            );

                            if (!$burgerId) {
                                throw new \Exception(
                                    "Burger '{$burgerNameSearch}' could not be found."
                                );
                            }

                            $burgerId = (int) $burgerId;
                        }

                        /*
                         * Lock and Retrieve Burger Information
                         */
                        $burger = $connection->fetchAssociative(
                            'SELECT id, name, price, stock_quantity, available
                             FROM burgers
                             WHERE id = ?
                             FOR UPDATE',
                            [$burgerId]
                        );

                        if (!$burger) {
                            $cartName = $item['name'] ?? 'Burger';

                            throw new \Exception(
                                "Burger '{$cartName}' could not be found in the database."
                            );
                        }

                        /*
                         * Check Availability
                         */
                        if ((int) $burger['available'] !== 1) {
                            throw new \Exception(
                                $burger['name'] . ' is currently unavailable.'
                            );
                        }

                        /*
                         * Check Stock
                         */
                        $currentStock = (int) $burger['stock_quantity'];

                        if ($currentStock < $quantity) {
                            throw new \Exception(
                                'Not enough stock for ' .
                                $burger['name'] .
                                '. Only ' .
                                $currentStock .
                                ' left in stock.'
                            );
                        }

                        /*
                         * Use Database Burger Information
                         */
                        $burgerName = $burger['name'];
                        $price = (float) $burger['price'];
                        $subtotal = $price * $quantity;

                        $orderTotal += $subtotal;

                        $orderItems[] = [
                            'burger_id' => $burgerId,
                            'burger_name' => $burgerName,
                            'price' => $price,
                            'quantity' => $quantity,
                            'subtotal' => $subtotal,
                        ];
                    }

                    /*
                     * Prepare Order Information
                     */
                    $status = 'Pending';
                    $notes = '';

                    /*
                     * Payment Status
                     */
                    $paymentStatus = (
                        $form['payment_method'] === 'GCash'
                    ) ? 'Pending Verification' : 'Unpaid';

                    $gcashReference = (
                        $form['payment_method'] === 'GCash'
                    ) ? $form['gcash_reference'] : null;

                    /*
                     * Insert Order
                     */
                    $connection->executeStatement(
                        'INSERT INTO orders (
                            customer_id,
                            customer_name,
                            phone,
                            address,
                            notes,
                            payment_method,
                            payment_status,
                            gcash_reference,
                            total,
                            status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $customerId,
                            $form['full_name'],
                            $form['contact_number'],
                            $form['address'],
                            $notes,
                            $form['payment_method'],
                            $paymentStatus,
                            $gcashReference,
                            $orderTotal,
                            $status,
                        ]
                    );

                    /*
                     * Get New Order ID
                     */
                    $orderId = (int) $connection->lastInsertId();

                    if ($orderId <= 0) {
                        throw new \Exception(
                            'Failed to retrieve the new order ID.'
                        );
                    }

                    /*
                     * Process Each Order Item
                     */
                    foreach ($orderItems as $item) {

                        /*
                         * Insert Order Item
                         */
                        $connection->executeStatement(
                            'INSERT INTO order_items (
                                order_id,
                                burger_id,
                                burger_name,
                                price,
                                quantity,
                                subtotal
                            ) VALUES (?, ?, ?, ?, ?, ?)',
                            [
                                $orderId,
                                $item['burger_id'],
                                $item['burger_name'],
                                $item['price'],
                                $item['quantity'],
                                $item['subtotal'],
                            ]
                        );

                        /*
                         * Deduct Burger Stock
                         */
                        $affectedRows = $connection->executeStatement(
                            'UPDATE burgers
                             SET stock_quantity = stock_quantity - ?
                             WHERE id = ?
                             AND stock_quantity >= ?',
                            [
                                $item['quantity'],
                                $item['burger_id'],
                                $item['quantity'],
                            ]
                        );

                        /*
                         * Make Sure Stock Was Actually Updated
                         */
                        if ($affectedRows !== 1) {
                            throw new \Exception(
                                'Stock update failed for ' .
                                $item['burger_name'] .
                                '.'
                            );
                        }
                    }

                    /*
                     * Commit Database Transaction
                     */
                    $connection->commit();

                    /*
                     * Clear Cart
                     */
                    $session->set('cart', []);

                    /*
                     * Redirect to Successful Order Page
                     */
                    return $this->redirectToRoute('checkout', [
                        'success' => 1,
                        'order_id' => $orderId,
                    ]);

                } catch (\Throwable $exception) {

                    /*
                     * Roll Back Everything
                     */
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }

                    $logger->error('Checkout order failed.', [
                        'exception' => $exception->getMessage(),
                    ]);

                    $error = $exception->getMessage();
                }
            }
        }

        /*
         * Successful Order
         */
        if (
            $request->query->get('success') === '1' &&
            $request->query->has('order_id')
        ) {
            $orderSuccess = true;
            $orderId = (int) $request->query->get('order_id');
        }

        /*
         * Render Checkout Page
         */
        return $this->render('Home/Checkout/checkout.html.twig', [
            'customer_name' => $customerName,
            'cart' => $cart,
            'total' => $total,
            'error' => $error,
            'order_success' => $orderSuccess,
            'order_id' => $orderId,
            'form' => $form,
        ]);
    }
}
