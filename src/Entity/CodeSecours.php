<?php

namespace App\Entity;

use App\Repository\CodeSecoursRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Code de secours de la double authentification (ENF-05) : 8 par administrateur, à usage unique.
 * Mapping aligné sur la table `code_secours` du MPD. Seule l'empreinte Argon2id du code est stockée.
 */
#[ORM\Entity(repositoryClass: CodeSecoursRepository::class)]
#[ORM\Table(name: 'code_secours')]
#[ORM\Index(name: 'idx_code_utilisateur', columns: ['id_utilisateur', 'date_utilisation'])]
class CodeSecours
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_code', type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $codeHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateCreation;

    /** NULL tant que le code est utilisable ; rempli quand il a servi ou a été révoqué. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateUtilisation = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'id_utilisateur', referencedColumnName: 'id_utilisateur', nullable: false, onDelete: 'RESTRICT')]
    private Utilisateur $utilisateur;

    public function __construct(Utilisateur $utilisateur, string $codeHash)
    {
        $this->utilisateur = $utilisateur;
        $this->codeHash = $codeHash;
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function estUtilisable(): bool
    {
        return null === $this->dateUtilisation;
    }

    /** Usage unique : un code utilisé (ou révoqué) ne revient jamais. Aucune ligne n'est supprimée. */
    public function invalider(): void
    {
        $this->dateUtilisation ??= new \DateTimeImmutable();
    }
}
