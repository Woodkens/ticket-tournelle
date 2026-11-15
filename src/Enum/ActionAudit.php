<?php

namespace App\Enum;

/**
 * Actions écrites dans la colonne `action` de journal_audit (ENF-14).
 * Une liste fermée évite les fautes de frappe et donne la liste complète en un coup d'œil.
 */
enum ActionAudit: string
{
    case ConnexionReussie = 'LOGIN_SUCCESS';
    /** Mauvais mot de passe ou identifiant inconnu. */
    case ConnexionEchouee = 'LOGIN_FAILED';
    /** Tentative sur un compte désactivé (actif = false). */
    case ConnexionCompteDesactive = 'LOGIN_DISABLED';
    /** Formulaire envoyé sans jeton CSRF valide. */
    case ConnexionCsrfInvalide = 'LOGIN_CSRF_INVALID';
    /** Trop d'essais : connexion bloquée par le login_throttling (ENF-03). */
    case ConnexionBloquee = 'LOGIN_BLOCKED';
    case Deconnexion = 'LOGOUT';
    /** Session fermée automatiquement : inactivité ou durée maximale (ENF-08). */
    case SessionExpiree = 'SESSION_EXPIRED';
    case CompteCree = 'USER_CREATED';
}
