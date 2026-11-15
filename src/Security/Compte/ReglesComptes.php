<?php

namespace App\Security\Compte;

use App\Entity\Utilisateur;
use App\Enum\Role;
use App\Repository\UtilisateurRepository;

/**
 * Les règles de gestion des comptes, écrites UNE fois (CdC §2.1, C5, D-19). Elles servent à la fois :
 *  - au Voter, pour cacher les boutons interdits ;
 *  - au service GestionComptes, qui refuse l'action même si la requête est forgée à la main.
 *
 * Chaque méthode renvoie NULL si l'action est permise, sinon la clé de traduction du motif de refus.
 */
final class ReglesComptes
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
    ) {
    }

    public function refusDesactivation(Utilisateur $acteur, Utilisateur $cible): ?string
    {
        return match (true) {
            !$cible->isActif() => 'admin.rule.already_disabled',
            // Un administrateur ne peut pas se couper lui-même l'accès
            $this->memeCompte($acteur, $cible) => 'admin.rule.self_disable',
            // Il doit toujours rester au moins un administrateur actif
            $cible->estAdmin() && $this->estLeDernierAdminActif($cible) => 'admin.rule.last_admin',
            default => null,
        };
    }

    public function refusReactivation(Utilisateur $cible): ?string
    {
        return $cible->isActif() ? 'admin.rule.already_enabled' : null;
    }

    public function refusChangementDeRole(Utilisateur $acteur, Utilisateur $cible, Role $nouveauRole): ?string
    {
        return match (true) {
            $nouveauRole === $cible->getRole() => 'admin.rule.same_role',
            // Pas de promotion : un compte admin est un compte À PART, créé comme tel (C5, D-19)
            Role::Admin === $nouveauRole => 'admin.rule.no_promotion',
            // Retirer le rôle admin : jamais à soi-même, jamais au dernier administrateur actif
            $cible->estAdmin() && $this->memeCompte($acteur, $cible) => 'admin.rule.self_demote',
            $cible->estAdmin() && $this->estLeDernierAdminActif($cible) => 'admin.rule.last_admin',
            default => null,
        };
    }

    public function refusReinitialisationTotp(Utilisateur $acteur, Utilisateur $cible): ?string
    {
        return match (true) {
            !$cible->estAdmin() => 'admin.rule.totp_not_admin',
            // C'est un AUTRE administrateur qui réinitialise la 2FA (CdC §7.1, H4)
            $this->memeCompte($acteur, $cible) => 'admin.rule.self_totp_reset',
            null === $cible->getTotpSecret() => 'admin.rule.totp_not_enrolled',
            default => null,
        };
    }

    private function memeCompte(Utilisateur $a, Utilisateur $b): bool
    {
        return null !== $a->getId() && $a->getId() === $b->getId();
    }

    private function estLeDernierAdminActif(Utilisateur $cible): bool
    {
        return $cible->isActif() && 0 === $this->utilisateurs->compterAdminsActifsSauf($cible);
    }
}
