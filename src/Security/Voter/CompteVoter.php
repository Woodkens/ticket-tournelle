<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Security\Compte\ReglesComptes;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits d'un administrateur sur UN compte donné (CdC §2.1) : sert à n'afficher que les boutons permis.
 * Les mêmes règles (ReglesComptes) sont revérifiées par GestionComptes au moment de l'action.
 *
 * @extends Voter<string, Utilisateur>
 */
final class CompteVoter extends Voter
{
    public const DESACTIVER = 'COMPTE_DESACTIVER';
    public const REACTIVER = 'COMPTE_REACTIVER';
    public const REINITIALISER_2FA = 'COMPTE_REINITIALISER_2FA';

    public function __construct(
        private readonly ReglesComptes $regles,
        private readonly AccessDecisionManagerInterface $decideur,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Utilisateur
            && \in_array($attribute, [self::DESACTIVER, self::REACTIVER, self::REINITIALISER_2FA], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $acteur = $token->getUser();

        // D'abord : être administrateur avec la 2FA validée
        if (!$acteur instanceof Utilisateur || !$this->decideur->decide($token, [AccesAdminVoter::ACCES_ADMIN])) {
            return false;
        }

        return null === match ($attribute) {
            self::DESACTIVER => $this->regles->refusDesactivation($acteur, $subject),
            self::REACTIVER => $this->regles->refusReactivation($subject),
            self::REINITIALISER_2FA => $this->regles->refusReinitialisationTotp($acteur, $subject),
        };
    }
}
