<?php

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Contrôles faits sur le compte pendant la connexion, en plus du mot de passe.
 */
final class UtilisateurChecker implements UserCheckerInterface
{
    /**
     * Avant la vérification du mot de passe :
     *  - un compte désactivé (actif = false) est refusé (US06) ;
     *  - un compte dont le titulaire n'a pas encore choisi son mot de passe est refusé (ENF-04, D-20).
     *
     * Symfony remplace ces erreurs par le message générique (expose_security_errors: none), et
     * TempsDeReponseConnexionSubscriber fait durer la réponse autant qu'un mauvais mot de passe (D-18) :
     * ni l'écran ni le chronomètre ne révèlent l'état du compte.
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        if (!$user->isActif()) {
            throw new DisabledException('Compte désactivé.');
        }

        if ($user->doitChangerMdp()) {
            throw new CompteNonActiveException('Compte non activé.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
