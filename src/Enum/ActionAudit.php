<?php

namespace App\Enum;

/**
 * Actions écrites dans la colonne `action` de journal_audit (ENF-14).
 * Une liste fermée évite les fautes de frappe et donne la liste complète en un coup d'œil.
 */
enum ActionAudit: string
{
    // ----- Connexion (S5) -----
    case ConnexionReussie = 'LOGIN_SUCCESS';
    /** Mauvais mot de passe ou identifiant inconnu. */
    case ConnexionEchouee = 'LOGIN_FAILED';
    /** Tentative sur un compte désactivé (actif = false). */
    case ConnexionCompteDesactive = 'LOGIN_DISABLED';
    /** Tentative sur un compte dont le titulaire n'a pas encore choisi son mot de passe. */
    case ConnexionCompteNonActive = 'LOGIN_NOT_ACTIVATED';
    /** Formulaire envoyé sans jeton CSRF valide. */
    case ConnexionCsrfInvalide = 'LOGIN_CSRF_INVALID';
    /** Trop d'essais : connexion bloquée par le login_throttling (ENF-03). */
    case ConnexionBloquee = 'LOGIN_BLOCKED';
    case Deconnexion = 'LOGOUT';
    /** Session fermée automatiquement : inactivité ou durée maximale (ENF-08). */
    case SessionExpiree = 'SESSION_EXPIRED';
    /** Page interdite à ce rôle (403). */
    case AccesRefuse = 'ACCESS_DENIED';

    // ----- Comptes (S6) -----
    case CompteCree = 'USER_CREATED';
    /** E-mail d'activation envoyé ou renvoyé. */
    case ActivationEnvoyee = 'ACTIVATION_SENT';
    /** Le titulaire a choisi son mot de passe avec le lien d'activation. */
    case CompteActive = 'USER_ACTIVATED';
    case RoleChange = 'ROLE_CHANGED';
    case CompteDesactive = 'USER_DISABLED';
    case CompteReactive = 'USER_ENABLED';
    case ImportComptes = 'USERS_IMPORTED';
    case ReinitialisationDemandee = 'PASSWORD_RESET_REQUESTED';
    case ReinitialisationFaite = 'PASSWORD_RESET_DONE';

    // ----- Double authentification (S6) -----
    case TotpEnrole = 'TOTP_ENROLLED';
    case TotpReinitialise = 'TOTP_RESET';
    case TotpReussi = 'TOTP_SUCCESS';
    case TotpEchoue = 'TOTP_FAILED';
    /** Trop de codes faux : la saisie est bloquée 15 minutes. */
    case TotpBloque = 'TOTP_BLOCKED';
    /** Un code déjà accepté a été présenté une seconde fois (rejeu). */
    case TotpRejoue = 'TOTP_REPLAYED';
    case CodeSecoursUtilise = 'BACKUP_CODE_USED';
}
