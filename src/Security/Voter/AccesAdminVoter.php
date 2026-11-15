<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droit « ACCES_ADMIN » : l'administration est réservée à un compte ROLE_ADMIN dont la double
 * authentification est active ET validée pour cette session (C5, CdC §2.1).
 *
 * C'est une seconde barrière, en plus de la règle access_control sur /admin (défense en profondeur).
 *
 * @extends Voter<string, null>
 */
final class AccesAdminVoter extends Voter
{
    public const ACCES_ADMIN = 'ACCES_ADMIN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCES_ADMIN === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $compte = $token->getUser();

        return $compte instanceof Utilisateur
            && !$token instanceof TwoFactorTokenInterface   // code TOTP pas encore saisi
            && $compte->isActif()
            && $compte->estAdmin()
            && $compte->isTotpActif();
    }
}
