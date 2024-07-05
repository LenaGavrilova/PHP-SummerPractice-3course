<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SecondPageController extends AbstractController
{
    /**
     * @Route("/second_page", name="second")
     */
    public function index(): Response
    {
        return $this->render('secondPage.html.twig');
    }
}

