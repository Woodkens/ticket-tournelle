<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Security\Voter\AccesAdminVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * « / » redirige vers la page de départ de la personne connectée, dans la langue de son profil (US03) :
     * l'administration des comptes pour un administrateur, l'accueil pour les autres.
     * C'est ici qu'on arrive juste après la connexion.
     */
    #[Route('/', name: 'app_root', methods: ['GET'])]
    public function root(Request $request): RedirectResponse
    {
        $utilisateur = $this->getUser();
        $locale = $utilisateur instanceof Utilisateur
            ? $utilisateur->getLangue()->value
            : $request->getLocale();

        // Le compte administrateur ne sert qu'à administrer (C5)
        if ($this->isGranted(AccesAdminVoter::ACCES_ADMIN)) {
            return $this->redirectToRoute('app_admin_comptes', ['_locale' => $locale]);
        }

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
