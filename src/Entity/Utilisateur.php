<?php

namespace App\Entity;

use App\Enum\Langue;
use App\Enum\Role;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
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
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, TwoFactorInterface
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

    /**
     * VRAI tant que le titulaire n'a pas choisi son mot de passe avec le lien d'activation (ENF-04, D-20).
     * Dans cet état, la connexion est refusée.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $doitChangerMdp = true;

    /** Secret TOTP CHIFFRÉ (ENF-05, D-21). Seul App\Security\Totp\ChiffreurTotp sait le déchiffrer. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecret = null;

    /** VRAI quand l'administrateur a terminé son enrôlement (QR code scanné + premier code correct). */
    #[ORM\Column(options: ['default' => false])]
    private bool $totpActif = false;

    /** Numéro du dernier créneau de 30 secondes accepté : un même code ne peut pas servir deux fois. */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $totpDernierCreneau = null;

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

    public function setRole(Role $role): void
    {
        $this->role = $role;
    }

    public function estAdmin(): bool
    {
        return Role::Admin === $this->role;
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

    public function setMotDePasseHash(string $empreinte): void
    {
        $this->motDePasseHash = $empreinte;
    }

    // ----- Double authentification TOTP (administrateurs) -----

    public function isTotpActif(): bool
    {
        return $this->totpActif;
    }

    /** Secret tel qu'il est en base : chiffré. */
    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    /** Début de l'enrôlement : on enregistre le secret chiffré, la 2FA n'est pas encore active. */
    public function preparerTotp(string $secretChiffre): void
    {
        $this->totpSecret = $secretChiffre;
        $this->totpActif = false;
        $this->totpDernierCreneau = null;
    }

    /** Fin de l'enrôlement : le premier code était correct. La base exige qu'un secret existe (ck_totp_secret). */
    public function activerTotp(): void
    {
        if (null === $this->totpSecret) {
            throw new \LogicException('Impossible d\'activer la TOTP sans secret.');
        }

        $this->totpActif = true;
    }

    /** Réinitialisation par un autre administrateur : l'enrôlement sera redemandé à la prochaine connexion. */
    public function reinitialiserTotp(): void
    {
        $this->totpActif = false;
        $this->totpSecret = null;
        $this->totpDernierCreneau = null;
    }

    public function getTotpDernierCreneau(): ?int
    {
        return $this->totpDernierCreneau;
    }

    /** Un administrateur qui n'a pas terminé son enrôlement n'a accès à rien d'autre (C5). */
    public function doitEnrolerTotp(): bool
    {
        return $this->estAdmin() && !$this->totpActif;
    }

    // ----- Méthodes demandées par scheb/2fa-bundle -----

    /** La 2FA est demandée à la connexion pour tout administrateur enrôlé. */
    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->estAdmin() && $this->totpActif;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    /**
     * Le bundle veut savoir qu'un secret existe. On lui remet le secret CHIFFRÉ, inutilisable tel quel :
     * la vérification des codes est faite par notre classe App\Security\Totp\AuthentificateurTotp,
     * qui déchiffre le secret seulement au moment du calcul.
     */
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecret || '' === $this->totpSecret) {
            return null;
        }

        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
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

    /**
     * Ce qui est rangé dans la session : le strict nécessaire pour retrouver le compte et le comparer
     * (isEqualTo). Le secret TOTP, même chiffré, n'y est jamais copié ; le compte complet est relu
     * en base à chaque requête.
     *
     * @return array{id: ?int, email: string, motDePasseHash: string, role: string, actif: bool}
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'motDePasseHash' => $this->motDePasseHash,
            'role' => $this->role->value,
            'actif' => $this->actif,
        ];
    }

    /** @param array{id: ?int, email: string, motDePasseHash: string, role: string, actif: bool} $donnees */
    public function __unserialize(array $donnees): void
    {
        $this->id = $donnees['id'];
        $this->email = $donnees['email'];
        $this->motDePasseHash = $donnees['motDePasseHash'];
        $this->role = Role::from($donnees['role']);
        $this->actif = $donnees['actif'];
        $this->nom = '';
        $this->prenom = '';
    }
}
