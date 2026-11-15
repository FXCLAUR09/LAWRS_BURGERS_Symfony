<?php

namespace App\Controller\Cart;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CartController extends AbstractController
{
    /*
     * DISPLAY CART
     */
    #[Route('/cart', name: 'cart', methods: ['GET'])]
    public function index(
        Request $request,
        Connection $connection
    ): Response {
        $session = $request->getSession();

        // Get cart
        $cart = $session->get('cart', []);

        // Calculate total
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

        // Load customer's recent orders
        $customerOrders = [];
        $orderHistoryError = false;

        if ($session->has('customer_id')) {
            $customerId = (int) $session->get('customer_id');

            try {
                $customerOrders = $connection->fetchAllAssociative(
                    "
                        SELECT
                            id,
                            created_at,
                            total,
                            status,
                            payment_status,
                            payment_method
                        FROM orders
                        WHERE customer_id = ?
                        ORDER BY id DESC
                        LIMIT 10
                    ",
                    [$customerId]
                );
            } catch (\Throwable $exception) {
                $orderHistoryError = true;
            }
        }

        return $this->render('Home/Cart/cart.html.twig', [
            'cart' => $cart,
            'total' => $total,
            'customer_orders' => $customerOrders,
            'order_history_error' => $orderHistoryError,
        ]);
    }

    /*
     * ADD TO CART
     */
    #[Route('/cart/add', name: 'cart_add', methods: ['POST'])]
    public function add(
        Request $request,
        Connection $connection
    ): Response {
        $session = $request->getSession();

        // Make sure burger_id was sent
        if (!$request->request->has('burger_id')) {
            throw $this->createNotFoundException(
                'No burger ID was received.'
            );
        }

        $burgerId = (int) $request->request->get('burger_id');

        // Get available burger from database
        $burger = $connection->fetchAssociative(
            "
                SELECT id, name, price, image
                FROM burgers
                WHERE id = ?
                AND available = 1
                LIMIT 1
            ",
            [$burgerId]
        );

        // Check if burger exists
        if (!$burger) {
            throw $this->createNotFoundException(
                'Burger not found.'
            );
        }

        // Create cart if it does not exist
        $cart = $session->get('cart', []);

        // Check if burger is already in cart
        if (isset($cart[$burgerId])) {
            $cart[$burgerId]['quantity']++;
        } else {
            $cart[$burgerId] = [
                'id' => $burger['id'],
                'name' => $burger['name'],
                'price' => $burger['price'],
                'image' => $burger['image'],
                'quantity' => 1,
            ];
        }

        $session->set('cart', $cart);

        return $this->redirectToRoute('cart');
    }

    /*
     * UPDATE CART QUANTITY
     */
    #[Route('/cart/update', name: 'cart_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $session = $request->getSession();

        // Make sure the required values were submitted
        if (
            !$request->request->has('burger_id') ||
            !$request->request->has('quantity')
        ) {
            return $this->redirectToRoute('cart');
        }

        $burgerId = (int) $request->request->get('burger_id');
        $quantity = (int) $request->request->get('quantity');

        // Quantity must be at least 1
        if ($quantity < 1) {
            $quantity = 1;
        }

        $cart = $session->get('cart', []);

        // Find the burger inside the session cart
        foreach ($cart as $key => $item) {
            if (
                isset($item['id']) &&
                (int) $item['id'] === $burgerId
            ) {
                $cart[$key]['quantity'] = $quantity;
                break;
            }
        }

        $session->set('cart', $cart);

        return $this->redirectToRoute('cart');
    }

    /*
     * REMOVE FROM CART
     */
    #[Route('/cart/remove/{id}', name: 'cart_remove', methods: ['GET'])]
    public function remove(
        int $id,
        Request $request
    ): Response {
        $session = $request->getSession();

        $cart = $session->get('cart', []);

        if (isset($cart[$id])) {
            unset($cart[$id]);
        }

        $session->set('cart', $cart);

        return $this->redirectToRoute('cart');
    }
}
