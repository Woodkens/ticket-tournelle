<?php

namespace App\Security\Compte;

/**
 * Action refusée par une règle de gestion des comptes. Le message est une CLÉ de traduction
 * (domaine « messages »), affichée à l'administrateur dans sa langue.
 */
final class RegleCompteException extends \DomainException
{
    /** @param array<string, string> $parametres */
    public function __construct(string $cleDeTraduction, public readonly array $parametres = [])
    {
        parent::__construct($cleDeTraduction);
    }
}
