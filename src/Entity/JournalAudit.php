<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne du journal d'audit (ENF-14, ENF-15). Mapping aligné sur la table `journal_audit` du MPD.
 *
 * Journal en AJOUT SEUL : la classe n'a aucun « setter », donc le code ne peut pas modifier une ligne,
 * et le compte SQL tt_app n'a de toute façon ni UPDATE ni DELETE sur cette table (ENF-16).
 * La date est posée par MariaDB (DEFAULT CURRENT_TIMESTAMP) : l'application ne peut pas la falsifier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'journal_audit')]
#[ORM\Index(name: 'idx_audit_date', columns: ['date_action'])]
#[ORM\Index(name: 'idx_audit_acteur', columns: ['id_acteur', 'date_action'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action', 'date_action'])]
class JournalAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_log', type: Types::BIGINT, options: ['unsigned' => true])]
    private ?int $id = null;

    /**
     * Colonne remplie par MariaDB, jamais par PHP (insertable: false).
     * Le MPD la déclare en DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3). Doctrine ne connaît pas
     * la précision « (3) » et lit cette valeur par défaut comme un texte : on la recopie telle quelle
     * pour que doctrine:schema:validate ne signale aucun écart. La définition exacte reste celle du MPD (D-06).
     */
    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        insertable: false,
        updatable: false,
        options: ['default' => 'current_timestamp(3)'],
        generated: 'INSERT',
    )]
    private ?\DateTimeImmutable $dateAction = null;

    #[ORM\Column(length: 50)]
    private string $action;

    /** 45 caractères = longueur maximale d'une adresse IPv6. */
    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cibleType;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $cibleId;

    /** NULL quand l'identifiant saisi ne correspond à aucun compte (échec de connexion anonyme). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'id_acteur', referencedColumnName: 'id_utilisateur', nullable: true, onDelete: 'RESTRICT')]
    private ?Utilisateur $acteur;

    public function __construct(
        string $action,
        string $ip,
        ?Utilisateur $acteur = null,
        ?string $cibleType = null,
        ?int $cibleId = null,
    ) {
        $this->action = $action;
        $this->ip = $ip;
        $this->acteur = $acteur;
        $this->cibleType = $cibleType;
        $this->cibleId = $cibleId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateAction(): ?\DateTimeImmutable
    {
        return $this->dateAction;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getCibleType(): ?string
    {
        return $this->cibleType;
    }

    public function getCibleId(): ?int
    {
        return $this->cibleId;
    }

    public function getActeur(): ?Utilisateur
    {
        return $this->acteur;
    }
}
