<?php

namespace App\Enum;

/**
 * Les deux usages d'un lien envoyé par e-mail. Valeurs identiques à l'ENUM `type` de jeton_compte (MPD).
 */
enum TypeJeton: string
{
    /** Premier choix du mot de passe, après la création du compte (US06). */
    case Activation = 'activation';
    /** « Mot de passe oublié » (US10). */
    case Reinitialisation = 'reinitialisation';

    /** Durée de validité du lien (D-20) : 72 h pour activer, 30 minutes pour réinitialiser. */
    public function dureeDeValidite(): \DateInterval
    {
        return match ($this) {
            self::Activation => new \DateInterval('PT72H'),
            self::Reinitialisation => new \DateInterval('PT30M'),
        };
    }
}
