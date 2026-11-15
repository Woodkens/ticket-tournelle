<?php

namespace App\Security;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Écrit une action sensible à DEUX endroits (ENF-16) :
 *  - la table journal_audit (ajout seul pour le compte SQL tt_app) ;
 *  - le fichier security.log (canal Monolog « audit »).
 *
 * Ce qui est enregistré : action, adresse IP, date, compte concerné s'il est connu (ENF-15).
 * Ce qui ne l'est JAMAIS : le mot de passe, ni l'identifiant saisi quand il est inconnu
 * (il arrive qu'on tape son mot de passe dans le champ e-mail) — ENF-17.
 */
final class JournalAuditLogger
{
    /** Adresse notée quand l'action vient de la console du serveur (pas de requête web). */
    public const IP_CONSOLE = '127.0.0.1';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $fichierAudit,
    ) {
    }

    public function tracer(
        ActionAudit $action,
        ?Utilisateur $acteur = null,
        ?string $cibleType = null,
        ?int $cibleId = null,
    ): void {
        $ip = $this->requestStack->getMainRequest()?->getClientIp() ?? self::IP_CONSOLE;

        // 1) En base : Doctrine envoie un INSERT préparé (aucune concaténation SQL)
        $this->entityManager->persist(new JournalAudit($action->value, $ip, $acteur, $cibleType, $cibleId));
        $this->entityManager->flush();

        // 2) Dans le fichier : seulement des identifiants numériques, pas de donnée personnelle
        $this->fichierAudit->info($action->value, [
            'ip' => $ip,
            'id_acteur' => $acteur?->getId(),
            'cible_type' => $cibleType,
            'cible_id' => $cibleId,
        ]);
    }
}
