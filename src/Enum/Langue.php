<?php

namespace App\Enum;

/**
 * Langues de l'interface (contrainte C2). Valeurs identiques à l'ENUM `langue` du MPD.
 */
enum Langue: string
{
    case Fr = 'fr';
    case En = 'en';
}
