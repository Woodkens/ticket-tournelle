<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Repository\UtilisateurRepository;
use App\Security\JournalAuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Journalise les connexions réussies, les échecs, les blocages et les déconnexions (ENF-14).
 * Symfony émet un « événement » à chacun de ces moments : cette classe s'y abonne.
 */
final class AuthentificationAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly JournalAuditLogger $journal,
        private readonly UtilisateurRepository $utilisateurs,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'surConnexionReussie',
            LoginFailureEvent::class => 'surConnexionEchouee',
            LogoutEvent::class => 'surDeconnexion',
        ];
    }

    public function surConnexionReussie(LoginSuccessEvent $event): void
    {
        $utilisateur = $event->getUser();

        $this->journal->tracer(
            ActionAudit::ConnexionReussie,
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
        );
    }

    public function surConnexionEchouee(LoginFailureEvent $event): void
    {
        // L'écran affiche un message générique, mais le journal garde la vraie raison :
        // Symfony range l'erreur d'origine dans getPrevious().
        $erreur = $event->getException();
        $origine = $erreur->getPrevious() ?? $erreur;

        $action = match (true) {
            $origine instanceof TooManyLoginAttemptsAuthenticationException => ActionAudit::ConnexionBloquee,
            $origine instanceof DisabledException => ActionAudit::ConnexionCompteDesactive,
            $origine instanceof InvalidCsrfTokenException => ActionAudit::ConnexionCsrfInvalide,
            default => ActionAudit::ConnexionEchouee,
        };

        // Compte visé, seulement si l'e-mail saisi correspond à un compte existant.
        // Sinon id_acteur reste NULL et l'e-mail saisi n'est pas enregistré.
        $identifiant = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier();
        $compteVise = null !== $identifiant ? $this->utilisateurs->findOneByEmail($identifiant) : null;

        $this->journal->tracer($action, $compteVise);
    }

    public function surDeconnexion(LogoutEvent $event): void
    {
        $utilisateur = $event->getToken()?->getUser();

        $this->journal->tracer(
            ActionAudit::Deconnexion,
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
        );
    }
}
