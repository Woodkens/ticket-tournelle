<?php

namespace App\Security\Compte;

use App\Enum\Role;

/**
 * Comptes administrateurs séparés (C5, D-19) : le compte admin d'une personne a une adresse e-mail
 * dédiée, différente de celle de son compte utilisateur. La forme de cette adresse est un réglage
 * (config/services.yaml, paramètre app.comptes.motif_email_admin), pas une règle écrite en dur.
 */
final class ConventionEmailAdmin
{
    public function __construct(
        private readonly string $motifEmailAdmin,
        private readonly string $exempleEmailAdmin,
    ) {
    }

    /** L'adresse a-t-elle la forme réservée aux comptes administrateurs ? */
    public function estUneAdresseAdmin(string $email): bool
    {
        return 1 === preg_match($this->motifEmailAdmin, mb_strtolower(trim($email)));
    }

    /**
     * Les deux sens sont vérifiés : un admin DOIT avoir une adresse dédiée,
     * et une adresse dédiée ne peut PAS servir à un compte ordinaire.
     */
    public function estCoherente(string $email, Role $role): bool
    {
        return $this->estUneAdresseAdmin($email) === (Role::Admin === $role);
    }

    public function exemple(): string
    {
        return $this->exempleEmailAdmin;
    }
}
