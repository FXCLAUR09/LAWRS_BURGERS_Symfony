<?php

namespace App\Controller\Reviews;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

class ReviewsController extends AbstractController
{
    #[Route('/reviews', name: 'reviews', methods: ['GET', 'POST'])]
    public function index(
    Request $request,
    Connection $connection,
    LoggerInterface $logger
): Response
    {
        $message = "";
        $messageType = "";

        $customerName = "";
        $rating = 0;
        $reviewText = "";

        // SUBMIT REVIEW
        if ($request->isMethod('POST')) {

            $customerName = trim($request->request->get('customer_name', ''));
            $rating = (int) $request->request->get('rating', 0);
            $reviewText = trim($request->request->get('review_text', ''));

            // Validate name
            if ($customerName === "") {

                $message = "Please enter your name.";
                $messageType = "error";

            }

            // Validate rating
            elseif ($rating < 1 || $rating > 5) {

                $message = "Please select a rating between 1 and 5 stars.";
                $messageType = "error";

            }

            // Validate review
            elseif ($reviewText === "") {

                $message = "Please write a review.";
                $messageType = "error";

            }

            // Insert review
            else {

                try {

                    $connection->executeStatement(
                        'INSERT INTO reviews
                        (customer_name, rating, review_text)
                        VALUES (?, ?, ?)',
                        [
                            $customerName,
                            $rating,
                            $reviewText
                        ]
                    );

                    $this->addFlash('success', 'Thank you for your review! 🍔⭐');

                    return $this->redirectToRoute('reviews');

                } catch (\Throwable $exception) {

                    $logger->error('Reviews submission failed.', [
                        'exception' => $exception->getMessage(),
                    ]);

                    $message = "Something went wrong while submitting your review.";
                    $messageType = "error";

                }
            }
        }

        // GET REVIEW STATISTICS
        $averageRating = 0;
        $totalReviews = 0;

        $stats = $connection->fetchAssociative(
            'SELECT
                COUNT(*) AS total_reviews,
                COALESCE(AVG(rating), 0) AS average_rating
             FROM reviews'
        );

        if ($stats) {

            $totalReviews = (int) $stats['total_reviews'];
            $averageRating = (float) $stats['average_rating'];

        }

        // GET STAR COUNTS
        $starCounts = [
            5 => 0,
            4 => 0,
            3 => 0,
            2 => 0,
            1 => 0
        ];

        $starRows = $connection->fetchAllAssociative(
            'SELECT
                rating,
                COUNT(*) AS total
             FROM reviews
             GROUP BY rating'
        );

        foreach ($starRows as $row) {

            $star = (int) $row['rating'];

            if (isset($starCounts[$star])) {

                $starCounts[$star] = (int) $row['total'];

            }
        }

        // GET REVIEWS
        $reviews = $connection->fetchAllAssociative(
            'SELECT
                id,
                customer_name,
                rating,
                review_text,
                created_at
             FROM reviews
             ORDER BY created_at DESC'
        );

        return $this->render('Home/Reviews/reviews.html.twig', [

            'message' => $message,
            'message_type' => $messageType,

            'customer_name' => $customerName,
            'rating' => $rating,
            'review_text' => $reviewText,

            'average_rating' => $averageRating,
            'total_reviews' => $totalReviews,

            'star_counts' => $starCounts,
            'reviews' => $reviews,

        ]);
    }
}
