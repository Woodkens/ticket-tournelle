<?php

namespace App\Entity;

use App\Enum\Langue;
use App\Enum\Role;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte nominatif. Le mapping reprend exactement la table `utilisateur` du MPD (D-06) :
 * c'est le MPD qui fait référence, cette classe ne fait que le décrire à Doctrine.
 * Aucune suppression : un compte est désactivé (actif = false), jamais effacé.
 */
#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[ORM\UniqueConstraint(name: 'uq_utilisateur_email', columns: ['email'])]
#[ORM\Index(name: 'idx_utilisateur_role', columns: ['role', 'actif'])]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_utilisateur', type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $nom;

    #[ORM\Column(length: 100)]
    private string $prenom;

    /** Identifiant de connexion (C1), toujours enregistré en minuscules. */
    #[ORM\Column(length: 180)]
    private string $email;

    /** Security by Default : le rôle le plus faible si rien n'est précisé. */
    #[ORM\Column(type: Types::ENUM, enumType: Role::class, options: ['default' => 'ROLE_USER'])]
    private Role $role = Role::User;

    #[ORM\Column(type: Types::ENUM, enumType: Langue::class, options: ['default' => 'fr'])]
    private Langue $langue = Langue::Fr;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** Empreinte Argon2id (ENF-01) : jamais le mot de passe lui-même. */
    #[ORM\Column(length: 255)]
    private string $motDePasseHash = '';

    /** Mot de passe provisoire à changer (ENF-04) : écran prévu en S6. */
    #[ORM\Column(options: ['default' => true])]
    private bool $doitChangerMdp = true;

    /** Point d'extension S6 (D-07) : secret TOTP chiffré, utilisé par scheb/2fa-bundle. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $totpActif = false;

    public function __construct(string $email, string $nom, string $prenom, Role $role = Role::User)
    {
        $this->email = mb_strtolower(trim($email));
        $this->nom = trim($nom);
        $this->prenom = trim($prenom);
        $this->role = $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getLangue(): Langue
    {
        return $this->langue;
    }

    public function setLangue(Langue $langue): void
    {
        $this->langue = $langue;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    /** Désactivation (US06) : remplace la suppression, interdite par le CdC. */
    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }

    public function doitChangerMdp(): bool
    {
        return $this->doitChangerMdp;
    }

    public function setDoitChangerMdp(bool $doitChangerMdp): void
    {
        $this->doitChangerMdp = $doitChangerMdp;
    }

    public function isTotpActif(): bool
    {
        return $this->totpActif;
    }

    public function setMotDePasseHash(string $empreinte): void
    {
        $this->motDePasseHash = $empreinte;
    }

    // ----- Méthodes demandées par Symfony Security -----

    /** L'identifiant de connexion est l'adresse e-mail. */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Un compte a exactement UN rôle, celui de la colonne `role`.
     * On n'ajoute pas ROLE_USER d'office : un admin ou la direction ne sont pas des « utilisateurs » (D-09).
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function getPassword(): string
    {
        return $this->motDePasseHash;
    }

    /** Rien à effacer : le mot de passe en clair n'est jamais gardé dans l'objet. */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    /**
     * Appelée à chaque requête pour comparer le compte de la session avec celui relu en base.
     * Si le compte a été désactivé, ou si son mot de passe ou son rôle a changé,
     * la réponse est « non » et la session ouverte est fermée aussitôt.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->email === $this->email
            && $user->motDePasseHash === $this->motDePasseHash
            && $user->role === $this->role
            && $user->actif
            && $this->actif;
    }
}
