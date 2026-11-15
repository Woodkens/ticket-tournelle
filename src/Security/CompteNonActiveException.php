<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Le titulaire n'a pas encore choisi son mot de passe avec le lien d'activation (ENF-04).
 * À l'écran, cette erreur est remplacée par le message générique (expose_security_errors: none).
 */
final class CompteNonActiveException extends AccountStatusException
{
    public function getMessageKey(): string
    {
        return 'Account is not activated.';
    }
}
