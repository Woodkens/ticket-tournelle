<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Role;
use App\Security\JournalAuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Expiration des sessions (ENF-08, R1 §6.3) :
 *  - 30 minutes sans activité (15 pour un administrateur) : postes partagés des salles ;
 *  - 8 heures au maximum, même si la personne est active.
 *
 * À la connexion, on note l'heure dans la session. À chaque page, on compare avec l'heure actuelle :
 * si un délai est dépassé, la session est détruite et on revient à l'écran de connexion.
 */
final class SessionExpirationSubscriber implements EventSubscriberInterface
{
    public const INACTIVITE_MAX = 30 * 60;
    public const INACTIVITE_MAX_ADMIN = 15 * 60;
    public const DUREE_MAX = 8 * 3600;

    public const CLE_DEBUT = '_tt_session_debut';
    public const CLE_ACTIVITE = '_tt_derniere_activite';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly JournalAuditLogger $journal,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'surConnexionReussie',
            // Priorité 7 : juste après le pare-feu (priorité 8), qui a retrouvé le compte connecté
            KernelEvents::REQUEST => ['surRequete', 7],
        ];
    }

    public function surConnexionReussie(LoginSuccessEvent $event): void
    {
        $session = $event->getRequest()->getSession();
        $session->set(self::CLE_DEBUT, time());
        $session->set(self::CLE_ACTIVITE, time());
    }

    public function surRequete(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $utilisateur = $this->tokenStorage->getToken()?->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return; // personne n'est connecté : rien à faire expirer
        }

        $session = $event->getRequest()->getSession();
        $maintenant = time();
        $debut = (int) $session->get(self::CLE_DEBUT, $maintenant);
        $derniereActivite = (int) $session->get(self::CLE_ACTIVITE, $maintenant);

        $inactiviteMax = Role::Admin === $utilisateur->getRole()
            ? self::INACTIVITE_MAX_ADMIN
            : self::INACTIVITE_MAX;

        if ($maintenant - $derniereActivite > $inactiviteMax || $maintenant - $debut > self::DUREE_MAX) {
            // Déconnexion côté serveur : on oublie le compte et on détruit la session (ENF-09)
            $this->tokenStorage->setToken(null);
            $session->invalidate();
            $this->journal->tracer(ActionAudit::SessionExpiree, $utilisateur);

            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('app_login', ['expire' => 1]),
            ));

            return;
        }

        $session->set(self::CLE_DEBUT, $debut);
        $session->set(self::CLE_ACTIVITE, $maintenant);
    }
}
