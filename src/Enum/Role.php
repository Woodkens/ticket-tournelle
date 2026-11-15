<?php

namespace App\Enum;

/**
 * Les 4 rôles de l'application (CdC §2). Valeurs identiques à l'ENUM `role` du MPD.
 * Aucun rôle n'hérite d'un autre : les droits sont ceux de la matrice §2.1 (D-09).
 */
enum Role: string
{
    case User = 'ROLE_USER';
    case Technicien = 'ROLE_TECHNICIEN';
    case Admin = 'ROLE_ADMIN';
    case Direction = 'ROLE_DIRECTION';
}
