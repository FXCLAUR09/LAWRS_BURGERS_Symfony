<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class OrdersController extends AbstractController
{
    /**
     * Display all orders.
     */
    
   

    #[Route('/admin/orders', name: 'admin_orders', methods: ['GET'])]
    public function index(
        Connection $connection,
        Request $request
    ): Response {
        $orders = $connection->fetchAllAssociative(
            'SELECT o.id, o.customer_name, o.phone, o.payment_method, o.total,
                    o.status, o.created_at, o.created_by_staff_id, su.name AS created_by_staff_name
             FROM orders o
             LEFT JOIN staff_users su ON su.id = o.created_by_staff_id
             ORDER BY o.created_at DESC'
        );

        $totalOrders = count($orders);

        return $this->render('Admin/orders.html.twig', [
            'orders' => $orders,
            'total_orders' => $totalOrders,
            'admin_username' => $request->getSession()->get(
                'admin_username',
                $request->getSession()->get('staff_name', 'Admin')
            ),
        ]);
    }




    /**
     * Display a single order and its items.
     */
    #[Route('/admin/orders/{id}', name: 'admin_order_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, Connection $connection): Response
    {
        $order = $connection->fetchAssociative(
            'SELECT o.*, su.name AS created_by_staff_name
             FROM orders o
             LEFT JOIN staff_users su ON su.id = o.created_by_staff_id
             WHERE o.id = ?',
            [$id]
        );

        if (!$order) {
            throw $this->createNotFoundException('Order not found.');
        }

        $items = $connection->fetchAllAssociative(
            'SELECT * FROM order_items WHERE order_id = ?',
            [$id]
        );

        return $this->render('Admin/view_order.html.twig', [
            'order' => $order,
            'items' => $items,
        ]);
    }

    /**
     * Update order status and restore stock on cancellation.
     */
    #[Route('/admin/orders/{id}/status', name: 'admin_order_update_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(
        int $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrf
    ): Response {
        $token = new CsrfToken(
            'order_status_' . $id,
            (string) $request->request->get('_token')
        );

        if (!$csrf->isTokenValid($token)) {
            throw $this->createAccessDeniedException(
                'Invalid security token.'
            );
        }

        $newStatus = (string) $request->request->get('status');

        $allowedStatuses = [
            'Pending',
            'Confirmed',
            'Preparing',
            'Ready',
            'Out for Delivery',
            'Completed',
            'Cancelled',
        ];

        if (!in_array($newStatus, $allowedStatuses, true)) {
            $this->addFlash('error', 'Invalid order status.');

            return $this->redirectToRoute(
                'admin_order_view',
                ['id' => $id]
            );
        }

        try {
            $connection->beginTransaction();

            $order = $connection->fetchAssociative(
                'SELECT id, status, customer_id
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE',
                [$id]
            );

            if (!$order) {
                throw new \RuntimeException('Order not found.');
            }

            $oldStatus = (string) $order['status'];

            if ($oldStatus === 'Completed') {
                throw new \RuntimeException(
                    'Completed orders cannot be changed.'
                );
            }

            if (
                $oldStatus === 'Cancelled'
                && $newStatus !== 'Cancelled'
            ) {
                throw new \RuntimeException(
                    'Cancelled orders cannot be reopened.'
                );
            }

            // Restore stock only when an order is cancelled
            // for the first time.
            if (
                $newStatus === 'Cancelled'
                && $oldStatus !== 'Cancelled'
            ) {
                $items = $connection->fetchAllAssociative(
                    'SELECT burger_id, quantity
                     FROM order_items
                     WHERE order_id = ?',
                    [$id]
                );

                foreach ($items as $item) {
                    if ($item['burger_id'] === null) {
                        continue;
                    }

                    $affected = $connection->executeStatement(
                        'UPDATE burgers
                         SET stock_quantity = stock_quantity + ?
                         WHERE id = ?',
                        [
                            (int) $item['quantity'],
                            (int) $item['burger_id'],
                        ]
                    );

                    if ($affected !== 1) {
                        throw new \RuntimeException(
                            'Unable to restore stock for a burger.'
                        );
                    }
                }
            }

            if ($newStatus !== $oldStatus) {
                $connection->executeStatement(
                    'UPDATE orders
                     SET status = ?,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?',
                    [$newStatus, $id]
                );

                if (!empty($order['customer_id'])) {
                    $connection->insert('notifications', [
                        'customer_id' => $order['customer_id'],
                        'order_id' => $id,
                        'title' => 'Order Status Updated',
                        'message' => sprintf(
                            'Your order #%d is now %s.',
                            $id,
                            $newStatus
                        ),
                        'is_read' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $connection->commit();

            $this->addFlash(
                'success',
                'Order status updated successfully.'
            );
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->addFlash(
                'error',
                'Unable to update order: ' . $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'admin_order_view',
            ['id' => $id]
        );
    }

    /**
     * Verify GCash payments or confirm cash received for COD.
     */
    #[Route('/admin/orders/{id}/payment', name: 'admin_order_payment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updatePayment(
        int $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrf
    ): Response {
        $token = new CsrfToken(
            'order_payment_' . $id,
            (string) $request->request->get('_token')
        );

        if (!$csrf->isTokenValid($token)) {
            throw $this->createAccessDeniedException(
                'Invalid security token.'
            );
        }

        $action = (string) $request->request->get('payment_action');

        try {
            $order = $connection->fetchAssociative(
                'SELECT id, customer_id, payment_method, payment_status
                 FROM orders
                 WHERE id = ?',
                [$id]
            );

            if (!$order) {
                throw new \RuntimeException('Order not found.');
            }

            $method = (string) $order['payment_method'];
            $paymentStatus = (string) $order['payment_status'];

            if (
                $method === 'GCash'
                && $paymentStatus === 'Pending Verification'
            ) {
                if ($action === 'confirm') {
                    $newPaymentStatus = 'Paid';
                    $message = sprintf(
                        'Your GCash payment for order #%d has been verified.',
                        $id
                    );
                } elseif ($action === 'reject') {
                    $newPaymentStatus = 'Rejected';
                    $message = sprintf(
                        'Your GCash payment for order #%d was rejected. Please contact the store.',
                        $id
                    );
                } else {
                    throw new \RuntimeException(
                        'Invalid payment action.'
                    );
                }
            } elseif (
                $method === 'Cash on Delivery'
                && $paymentStatus === 'Unpaid'
                && $action === 'confirm'
            ) {
                $newPaymentStatus = 'Paid';
                $message = sprintf(
                    'Payment for order #%d has been received.',
                    $id
                );
            } else {
                throw new \RuntimeException(
                    'This payment cannot be updated in its current state.'
                );
            }

            $affected = $connection->executeStatement(
                'UPDATE orders
                 SET payment_status = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND payment_status = ?',
                [$newPaymentStatus, $id, $paymentStatus]
            );

            if ($affected !== 1) {
                throw new \RuntimeException(
                    'Payment status changed already. Please refresh.'
                );
            }

            // Payment remains saved even if notification insertion fails.
            if (!empty($order['customer_id'])) {
                try {
                    $connection->insert('notifications', [
                        'customer_id' => $order['customer_id'],
                        'order_id' => $id,
                        'title' => 'Payment Update',
                        'message' => $message,
                        'is_read' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $notificationError) {
                    // Notification is best-effort.
                }
            }

            $this->addFlash(
                'success',
                'Payment status updated successfully.'
            );
        } catch (\Throwable $e) {
            $this->addFlash(
                'error',
                'Unable to update payment: ' . $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'admin_order_view',
            ['id' => $id]
        );
    }


    /**
     * Staff-created walk-in/manual order. Supports one or more burger lines.
     */
    #[Route('/staff/orders/add', name: 'staff_order_add', methods: ['GET', 'POST'])]
    public function addStaffOrder(
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrf
    ): Response {
        $burgers = $connection->fetchAllAssociative(
            'SELECT id, name, price, stock_quantity, available
             FROM burgers WHERE available = 1 AND stock_quantity > 0
             ORDER BY name ASC'
        );
        $customers = $connection->fetchAllAssociative(
            'SELECT id, name, email FROM customers ORDER BY name ASC'
        );

        if ($request->isMethod('POST')) {
            $token = new CsrfToken('staff_create_order', (string) $request->request->get('_token'));
            if (!$csrf->isTokenValid($token)) {
                throw $this->createAccessDeniedException('Invalid security token.');
            }

            $customerId = (int) $request->request->get('customer_id', 0);
            $customer = $customerId > 0
                ? $connection->fetchAssociative('SELECT id FROM customers WHERE id = ?', [$customerId])
                : false;
            $name = trim((string) $request->request->get('customer_name', ''));
            $phone = trim((string) $request->request->get('phone', ''));
            $address = trim((string) $request->request->get('address', ''));
            $paymentMethod = (string) $request->request->get('payment_method', '');
            $gcashReference = trim((string) $request->request->get('gcash_reference', ''));
            $quantities = $request->request->all('quantities');

            if (!$customer) {
                $this->addFlash('error', 'Select an existing customer account for this order.');
            } elseif ($name === '' || $phone === '' || $address === '') {
                $this->addFlash('error', 'Customer name, phone, and address are required.');
            } elseif (!in_array($paymentMethod, ['Cash on Delivery', 'GCash'], true)) {
                $this->addFlash('error', 'Select a valid payment method.');
            } elseif ($paymentMethod === 'GCash' && $gcashReference === '') {
                $this->addFlash('error', 'Enter the GCash reference number.');
            } else {
                try {
                    $connection->beginTransaction();
                    $items = [];
                    $total = 0.0;

                    foreach ($quantities as $burgerId => $quantityValue) {
                        $quantity = filter_var($quantityValue, FILTER_VALIDATE_INT);
                        if (!$quantity || $quantity < 0) {
                            throw new \RuntimeException('Enter valid item quantities.');
                        }
                        if ($quantity === 0) {
                            continue;
                        }

                        $burger = $connection->fetchAssociative(
                            'SELECT id, name, price, stock_quantity, available
                             FROM burgers WHERE id = ? FOR UPDATE',
                            [(int) $burgerId]
                        );
                        if (!$burger || (int) $burger['available'] !== 1) {
                            throw new \RuntimeException('One selected burger is unavailable.');
                        }
                        if ((int) $burger['stock_quantity'] < $quantity) {
                            throw new \RuntimeException('Insufficient stock for ' . $burger['name'] . '.');
                        }

                        $subtotal = (float) $burger['price'] * $quantity;
                        $total += $subtotal;
                        $items[] = [
                            'id' => (int) $burger['id'],
                            'name' => $burger['name'],
                            'price' => (float) $burger['price'],
                            'quantity' => $quantity,
                            'subtotal' => $subtotal,
                        ];
                    }

                    if (!$items) {
                        throw new \RuntimeException('Add at least one burger to the order.');
                    }

                    $paymentStatus = $paymentMethod === 'GCash' ? 'Pending Verification' : 'Unpaid';
                    $connection->insert('orders', [
                        'customer_id' => $customerId,
                        'customer_name' => $name,
                        'phone' => $phone,
                        'address' => $address,
                        'notes' => 'Created by staff',
                        'payment_method' => $paymentMethod,
                        'payment_status' => $paymentStatus,
                        'gcash_reference' => $paymentMethod === 'GCash' ? $gcashReference : null,
                        'total' => $total,
                        'status' => 'Pending',
                        'created_by_staff_id' => (int) $request->getSession()->get('staff_id'),
                    ]);
                    $orderId = (int) $connection->lastInsertId();

                    foreach ($items as $item) {
                        $connection->insert('order_items', [
                            'order_id' => $orderId,
                            'burger_id' => $item['id'],
                            'burger_name' => $item['name'],
                            'price' => $item['price'],
                            'quantity' => $item['quantity'],
                            'subtotal' => $item['subtotal'],
                        ]);
                        $changed = $connection->executeStatement(
                            'UPDATE burgers SET stock_quantity = stock_quantity - ?
                             WHERE id = ? AND stock_quantity >= ?',
                            [$item['quantity'], $item['id'], $item['quantity']]
                        );
                        if ($changed !== 1) {
                            throw new \RuntimeException('Stock changed while creating the order. Please retry.');
                        }
                    }

                    $connection->commit();
                    $this->addFlash('success', sprintf('Order #%d created successfully.', $orderId));
                    return $this->redirectToRoute('admin_order_view', ['id' => $orderId]);
                } catch (\Throwable $e) {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    $this->addFlash('error', 'Unable to create order: ' . $e->getMessage());
                }
            }
        }

        return $this->render('Staff/add_order.html.twig', [
            'burgers' => $burgers,
            'customers' => $customers,
            'staff_name' => $request->getSession()->get('staff_name', 'Staff'),
        ]);
    }

    /**
     * Delete an order and restore its reserved stock unless it was already cancelled.
     */
    #[Route('/admin/orders/{id}/delete', name: 'admin_order_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteStaffOrder(
        int $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrf
    ): Response {
        $token = new CsrfToken('delete_order_' . $id, (string) $request->request->get('_token'));
        if (!$csrf->isTokenValid($token)) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        try {
            $connection->beginTransaction();
            $order = $connection->fetchAssociative(
                'SELECT id, status, created_by_staff_id FROM orders WHERE id = ? FOR UPDATE',
                [$id]
            );
            if (!$order) {
                throw new \RuntimeException('Order not found.');
            }

            // Staff may delete only orders they personally created. Admin retains full access.
            $sessionStaffId = (int) $request->getSession()->get('staff_id', 0);
            if ($this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_ADMIN')) {
                if ($sessionStaffId <= 0 || (int) ($order['created_by_staff_id'] ?? 0) !== $sessionStaffId) {
                    throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('You may delete only orders you created.');
                }
            }

            $items = $connection->fetchAllAssociative(
                'SELECT burger_id, quantity FROM order_items WHERE order_id = ?',
                [$id]
            );
            if ($order['status'] !== 'Cancelled') {
                foreach ($items as $item) {
                    if ($item['burger_id'] !== null) {
                        $connection->executeStatement(
                            'UPDATE burgers SET stock_quantity = stock_quantity + ? WHERE id = ?',
                            [(int) $item['quantity'], (int) $item['burger_id']]
                        );
                    }
                }
            }
            // Remove linked customer notifications first to avoid foreign-key conflicts.
            $connection->delete('notifications', ['order_id' => $id]);
            $connection->delete('order_items', ['order_id' => $id]);
            $connection->delete('orders', ['id' => $id]);
            $connection->commit();
            $this->addFlash('success', sprintf('Order #%d deleted.', $id));
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->addFlash('error', 'Unable to delete order: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_orders');
    }

}
