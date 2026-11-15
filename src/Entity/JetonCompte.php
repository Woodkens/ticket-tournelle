<?php

namespace App\Entity;

use App\Enum\TypeJeton;
use App\Repository\JetonCompteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lien d'activation ou de réinitialisation (ENF-04). Mapping aligné sur la table `jeton_compte` du MPD.
 *
 * Le jeton envoyé par e-mail n'est jamais stocké : la base ne contient que son empreinte SHA-256.
 * Si la base fuit, personne ne peut donc fabriquer un lien valide à partir de son contenu.
 */
#[ORM\Entity(repositoryClass: JetonCompteRepository::class)]
#[ORM\Table(name: 'jeton_compte')]
#[ORM\UniqueConstraint(name: 'uq_jeton_hash', columns: ['jeton_hash'])]
#[ORM\Index(name: 'idx_jeton_utilisateur', columns: ['id_utilisateur', 'type'])]
class JetonCompte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_jeton', type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: Types::ENUM, enumType: TypeJeton::class)]
    private TypeJeton $type;

    /** Empreinte SHA-256 du jeton, en hexadécimal : toujours 64 caractères. */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $jetonHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateExpiration;

    /** NULL tant que le lien est utilisable ; rempli quand il a servi ou a été annulé (usage unique). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateUtilisation = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'id_utilisateur', referencedColumnName: 'id_utilisateur', nullable: false, onDelete: 'RESTRICT')]
    private Utilisateur $utilisateur;

    public function __construct(Utilisateur $utilisateur, TypeJeton $type, string $jetonHash)
    {
        $this->utilisateur = $utilisateur;
        $this->type = $type;
        $this->jetonHash = $jetonHash;
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateExpiration = $this->dateCreation->add($type->dureeDeValidite());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): TypeJeton
    {
        return $this->type;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getDateExpiration(): \DateTimeImmutable
    {
        return $this->dateExpiration;
    }

    /** Utilisable = jamais utilisé ni annulé, et pas encore expiré. */
    public function estUtilisable(): bool
    {
        return null === $this->dateUtilisation && $this->dateExpiration > new \DateTimeImmutable();
    }

    /** Marque le lien comme utilisé (ou annulé) : il ne servira plus jamais. */
    public function invalider(): void
    {
        $this->dateUtilisation ??= new \DateTimeImmutable();
    }
}
