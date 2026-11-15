<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Security\JournalAuditLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Journalise les refus d'accès (403) d'une personne connectée (ENF-14) : par exemple un utilisateur
 * ou un technicien qui demande une page d'administration. La page d'erreur 403 est ensuite affichée
 * normalement par Symfony.
 */
final class AccesRefuseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly JournalAuditLogger $journal,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité 2 : juste avant l'écouteur de Symfony (priorité 1) qui transforme l'erreur en page 403
        return [KernelEvents::EXCEPTION => ['surErreur', 2]];
    }

    public function surErreur(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof AccessDeniedException) {
            return;
        }

        $compte = $this->security->getUser();

        // Visiteur non connecté ou code TOTP pas encore saisi : il sera redirigé, ce n'est pas un 403
        if (!$compte instanceof Utilisateur || !$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return;
        }

        // On note le nom de la route, pas l'adresse complète (elle pourrait contenir des données)
        $route = (string) $event->getRequest()->attributes->get('_route', 'inconnue');
        $this->journal->tracer(ActionAudit::AccesRefuse, $compte, null, null, $route);
    }
}
