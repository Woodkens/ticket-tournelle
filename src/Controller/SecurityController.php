<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /**
     * « /login » sans langue : redirige vers l'écran de connexion dans la langue du navigateur.
     */
    #[Route('/login', name: 'app_login_redirect', methods: ['GET'])]
    public function loginRedirect(): RedirectResponse
    {
        return $this->redirectToRoute('app_login');
    }

    /**
     * Écran de connexion (maquette W1). Ce contrôleur ne fait qu'AFFICHER le formulaire :
     * la vérification (jeton CSRF, limitation des essais, compte actif, mot de passe)
     * est faite par le pare-feu Symfony « form_login », configuré dans security.yaml.
     */
    #[Route('/{_locale}/login', name: 'app_login', requirements: ['_locale' => 'fr|en'], methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Déjà connecté : inutile de revoir le formulaire
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_root');
        }

        return $this->render('security/login.html.twig', [
            // Dernier e-mail saisi, pour ne pas le retaper après une erreur
            'dernier_email' => $authenticationUtils->getLastUsername(),
            // Erreur de la dernière tentative : toujours un message générique
            'erreur' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Déconnexion : uniquement en POST (un simple lien ou une image ne peut pas déconnecter quelqu'un),
     * avec un jeton CSRF vérifié par le pare-feu. Cette méthode n'est jamais exécutée :
     * le pare-feu intercepte la requête avant.
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par le pare-feu (clé « logout » de security.yaml).');
    }
}
