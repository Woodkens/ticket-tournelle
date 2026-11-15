<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * « / » redirige vers l'accueil dans la langue du profil de la personne connectée (US03).
     * C'est ici qu'on arrive juste après la connexion.
     */
    #[Route('/', name: 'app_root', methods: ['GET'])]
    public function root(Request $request): RedirectResponse
    {
        $utilisateur = $this->getUser();
        $locale = $utilisateur instanceof Utilisateur
            ? $utilisateur->getLangue()->value
            : $request->getLocale();

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
