<?php

namespace App\Security\Compte;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Langue;
use App\Enum\Role;
use App\Enum\TypeJeton;
use App\Mail\CompteMailer;
use App\Repository\UtilisateurRepository;
use App\Security\JournalAuditLogger;
use App\Security\Totp\GestionCodesSecours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Toutes les actions d'administration sur les comptes (US06, C1, C5), au même endroit :
 * chaque action vérifie ses règles, modifie le compte et écrit dans le journal d'audit (ENF-14).
 *
 * Il n'existe AUCUNE méthode de suppression : un compte est désactivé, jamais effacé (CdC §2.1).
 */
final class GestionComptes
{
    /** Empreinte d'une valeur aléatoire que personne ne connaît, calculée une fois par requête. */
    private ?string $empreinteInutilisable = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly ReglesComptes $regles,
        private readonly ConventionEmailAdmin $conventionAdmin,
        private readonly GestionJetons $jetons,
        private readonly GestionCodesSecours $codesSecours,
        private readonly CompteMailer $mailer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JournalAuditLogger $journal,
    ) {
    }

    /**
     * Crée un compte SANS mot de passe utilisable : aucun mot de passe provisoire ne circule (ENF-04).
     * Le titulaire choisira le sien avec le lien d'activation ; d'ici là, la connexion est refusée.
     *
     * @param string $origine précision notée au journal : « manuel », « import » ou « console »
     */
    public function creer(
        ?Utilisateur $acteur,
        string $email,
        string $nom,
        string $prenom,
        Role $role,
        Langue $langue = Langue::Fr,
        string $origine = 'manuel',
    ): Utilisateur {
        $this->verifierAdresse($email, $role);

        $compte = new Utilisateur($email, $nom, $prenom, $role);
        $compte->setLangue($langue);
        $compte->setDoitChangerMdp(true);
        // La colonne est obligatoire : on y met l'empreinte Argon2id d'une valeur tirée au hasard
        // puis oubliée. Aucun mot de passe ne peut donc ouvrir ce compte avant son activation.
        $compte->setMotDePasseHash($this->empreinteInutilisable ??= $this->hasher->hashPassword($compte, bin2hex(random_bytes(32))));

        $this->entityManager->persist($compte);
        $this->entityManager->flush();

        $this->journal->tracerCompte(ActionAudit::CompteCree, $acteur, $compte, sprintf('%s (%s)', $role->value, $origine));

        return $compte;
    }

    /** Envoie (ou renvoie) le lien d'activation : valable 72 h, usage unique, annule les liens précédents. */
    public function envoyerActivation(?Utilisateur $acteur, Utilisateur $cible): void
    {
        if (!$cible->isActif() || !$cible->doitChangerMdp()) {
            throw new RegleCompteException('admin.rule.already_activated');
        }

        [$jeton, $clair] = $this->jetons->creer($cible, TypeJeton::Activation);
        $this->mailer->envoyerLien($jeton, $clair);

        $this->journal->tracerCompte(ActionAudit::ActivationEnvoyee, $acteur, $cible);
    }

    public function changerRole(Utilisateur $acteur, Utilisateur $cible, Role $nouveauRole): void
    {
        $this->exiger($this->regles->refusChangementDeRole($acteur, $cible, $nouveauRole));

        $ancienRole = $cible->getRole();
        $cible->setRole($nouveauRole);

        // Un ancien administrateur n'a plus de double authentification à garder
        if (Role::Admin === $ancienRole) {
            $cible->reinitialiserTotp();
            $this->codesSecours->revoquer($cible);
        }
        $this->entityManager->flush();

        $this->journal->tracerCompte(ActionAudit::RoleChange, $acteur, $cible, sprintf('%s > %s', $ancienRole->value, $nouveauRole->value));
    }

    /** Désactivation : le compte reste en base avec tout son historique, mais ne peut plus se connecter. */
    public function desactiver(Utilisateur $acteur, Utilisateur $cible): void
    {
        $this->exiger($this->regles->refusDesactivation($acteur, $cible));

        $cible->setActif(false);
        $this->jetons->annulerLesLiens($cible);
        $this->entityManager->flush();

        $this->journal->tracerCompte(ActionAudit::CompteDesactive, $acteur, $cible);
    }

    public function reactiver(Utilisateur $acteur, Utilisateur $cible): void
    {
        $this->exiger($this->regles->refusReactivation($cible));

        $cible->setActif(true);
        $this->entityManager->flush();

        $this->journal->tracerCompte(ActionAudit::CompteReactive, $acteur, $cible);
    }

    /**
     * Téléphone perdu : un AUTRE administrateur remet la 2FA à zéro (CdC §7.1). Le secret et les codes
     * de secours sont annulés ; l'enrôlement sera redemandé à la prochaine connexion.
     */
    public function reinitialiserTotp(Utilisateur $acteur, Utilisateur $cible): void
    {
        $this->exiger($this->regles->refusReinitialisationTotp($acteur, $cible));

        $cible->reinitialiserTotp();
        $this->codesSecours->revoquer($cible);
        $this->entityManager->flush();

        $this->journal->tracerCompte(ActionAudit::TotpReinitialise, $acteur, $cible);
    }

    /** Adresse libre, et conforme à la convention des comptes administrateurs (D-19). */
    public function verifierAdresse(string $email, Role $role): void
    {
        if (null !== $this->utilisateurs->findOneByEmail($email)) {
            throw new RegleCompteException('account.email_taken');
        }
        if (Role::Admin === $role && !$this->conventionAdmin->estUneAdresseAdmin($email)) {
            throw new RegleCompteException('account.admin_email_required', ['%exemple%' => $this->conventionAdmin->exemple()]);
        }
        if (Role::Admin !== $role && $this->conventionAdmin->estUneAdresseAdmin($email)) {
            throw new RegleCompteException('account.admin_email_forbidden');
        }
    }

    private function exiger(?string $motifDeRefus): void
    {
        if (null !== $motifDeRefus) {
            throw new RegleCompteException($motifDeRefus);
        }
    }
}
