<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Security\JournalAuditLogger;
use App\Security\Totp\LimiteurTotp;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Journalise la saisie du code TOTP à la connexion (ENF-14) et compte les codes faux (ENF-05).
 * Le code lui-même n'est jamais enregistré.
 */
final class TotpAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly JournalAuditLogger $journal,
        private readonly LimiteurTotp $limiteur,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::SUCCESS => 'surCodeAccepte',
            TwoFactorAuthenticationEvents::FAILURE => 'surCodeRefuse',
        ];
    }

    public function surCodeAccepte(TwoFactorAuthenticationEvent $event): void
    {
        $compte = $event->getToken()->getUser();
        if ($compte instanceof Utilisateur) {
            $this->limiteur->reinitialiser($compte);
            $this->journal->tracerCompte(ActionAudit::TotpReussi, $compte, $compte);
        }
    }

    public function surCodeRefuse(TwoFactorAuthenticationEvent $event): void
    {
        $compte = $event->getToken()->getUser();
        if (!$compte instanceof Utilisateur) {
            return;
        }

        // Déjà 5 codes faux en 15 minutes : la saisie est bloquée, même avec le bon code
        if ($this->limiteur->estBloque($compte)) {
            $this->journal->tracerCompte(ActionAudit::TotpBloque, $compte, $compte);

            return;
        }

        $this->limiteur->noterEchec($compte);
        $this->journal->tracerCompte(ActionAudit::TotpEchoue, $compte, $compte);
    }
}
