<?php

namespace App\Controller\Staff;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ReviewsController extends AbstractController
{
    #[Route('/staff/reviews', name: 'staff_reviews', methods: ['GET'])]
    public function index(Connection $connection, Request $request): Response
    {
        $reviews = $connection->fetchAllAssociative(
            'SELECT id, customer_name, rating, review_text, created_at
             FROM reviews ORDER BY created_at DESC'
        );

        $stats = $connection->fetchAssociative(
            'SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average FROM reviews'
        );

        return $this->render('Staff/reviews.html.twig', [
            'reviews' => $reviews,
            'total_reviews' => (int) ($stats['total'] ?? 0),
            'average_rating' => (float) ($stats['average'] ?? 0),
            'staff_name' => $request->getSession()->get('staff_name', 'Staff'),
        ]);
    }

}
