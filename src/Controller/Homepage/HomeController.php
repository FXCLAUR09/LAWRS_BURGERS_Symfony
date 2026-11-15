<?php

namespace App\Controller\Homepage;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Connection $connection): Response
    {
        // Get available Signature Burgers from the database.
        $signatureBurgers = $connection->fetchAllAssociative(
            "SELECT
                id,
                name,
                description,
                price,
                image
            FROM burgers
            WHERE available = 1
              AND category = 'Signature Burgers'
            ORDER BY id ASC"
        );

        return $this->render('home/index.html.twig', [
            'signature_burgers' => $signatureBurgers,
        ]);
    }
}
