<?php

namespace App\Controller\About;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AboutController extends AbstractController
{
    #[Route('/about', name: 'about', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('Home/About/about.html.twig');
    }
}
