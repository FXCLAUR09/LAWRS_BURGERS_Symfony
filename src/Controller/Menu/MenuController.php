<?php

namespace App\Controller\Menu;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MenuController extends AbstractController
{
    #[Route('/menu', name: 'menu', methods: ['GET'])]
    public function index(Connection $connection): Response
    {
        $burgers = $connection->fetchAllAssociative(
            "
                SELECT id, name, description, price, image, category
                FROM burgers
                WHERE available = 1
                ORDER BY id ASC
            "
        );

        return $this->render('Home/Menu/menu.html.twig', [
        'burgers' => $burgers,
        ]); 
    }
}
