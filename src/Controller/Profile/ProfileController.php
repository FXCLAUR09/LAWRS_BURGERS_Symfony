<?php

namespace App\Controller\Profile;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /*
         * CUSTOMER ACCESS CHECK
         * Only logged-in customers can access their profile.
         */
        if (!$session->has('customer_id')) {
            return $this->redirectToRoute('app_login');
        }

        /*
         * CUSTOMER INFORMATION
         * Retrieve existing session information.
         */
        $customerId = $session->get('customer_id');

        $customerName = $session->get('customer_name', '');
        $customerEmail = $session->get('customer_email', '');

        /*
         * PROFILE DATA
         * Do not modify login or database functionality.
         */
        return $this->render('Home/Profile/profile.html.twig', [
            'customerId' => $customerId,
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
        ]);
    }
}
