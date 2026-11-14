<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * « / » redirige vers la langue du navigateur (fr ou en), français par défaut.
     */
    #[Route('/', name: 'app_root', methods: ['GET'])]
    public function root(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage(['fr', 'en']) ?? 'fr';

        return $this->redirectToRoute('app_home', ['_locale' => $locale]);
    }

    /**
     * Page d'accueil. La langue fait partie de l'URL (/fr, /en) :
     * le paramètre _locale est limité à fr|en, toute autre valeur donne une 404.
     */
    #[Route('/{_locale}', name: 'app_home', requirements: ['_locale' => 'fr|en'], methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }
}
