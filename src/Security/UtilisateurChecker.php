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
     * Avant la vérification du mot de passe : un compte désactivé (actif = false) est refusé (US06).
     * Symfony remplace cette erreur par le message générique (expose_security_errors: none) :
     * l'écran ne révèle donc pas que le compte existe ni qu'il est désactivé.
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Utilisateur && !$user->isActif()) {
            throw new DisabledException('Compte désactivé.');
        }
    }

    /**
     * Après la vérification du mot de passe : rien en S5.
     * Point d'extension S6 : mot de passe provisoire à changer (ENF-04).
     */
    public function checkPostAuth(UserInterface $user): void
    {
    }
}
