<?php

namespace App\Import;

use App\Enum\Role;

/** Une ligne du fichier, telle qu'elle a été lue, avec ses erreurs éventuelles. */
final class LigneImport
{
    /**
     * @param list<array{cle: string, params: array<string, string>}> $erreurs clés de traduction
     */
    public function __construct(
        public readonly int $numero,
        public readonly string $nom,
        public readonly string $prenom,
        public readonly string $email,
        public readonly string $roleSaisi,
        public readonly ?Role $role,
        public readonly array $erreurs,
    ) {
    }

    public function estValide(): bool
    {
        return [] === $this->erreurs;
    }
}
