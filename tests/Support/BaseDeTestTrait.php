<?php

namespace App\Tests\Support;

use App\Entity\Utilisateur;
use App\Enum\Role;
use App\Security\Totp\AuthentificateurTotp;
use App\Security\Totp\ChiffreurTotp;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Outils communs aux tests qui utilisent la base de TEST (tickettournelle_test, compte tt_test).
 * Voir deploy/dev/create-test-db.sh.
 */
trait BaseDeTestTrait
{
    protected function connexionSql(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    /** Repart d'une base vide et remet à zéro les compteurs d'essais (connexion, TOTP, mot de passe oublié). */
    protected function viderLaBase(): void
    {
        $connexion = $this->connexionSql();

        // Garde-fou : on ne vide jamais autre chose qu'une base de test
        $base = (string) $connexion->fetchOne('SELECT DATABASE()');
        if (!str_ends_with($base, '_test')) {
            self::fail(sprintf('Les tests doivent tourner sur une base « _test », pas sur « %s ».', $base));
        }

        foreach (['code_secours', 'jeton_compte', 'journal_audit', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    /**
     * Après une modification faite directement en SQL : Doctrine oublie les objets déjà lus,
     * pour que la requête suivante relise bien la base (comme le ferait une vraie nouvelle requête web).
     */
    protected function oublierLesEntites(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** Mot de passe tiré au hasard à chaque test : aucun mot de passe en clair dans Git. */
    protected function motDePasseAleatoire(): string
    {
        return 'Tt!9-'.bin2hex(random_bytes(12));
    }

    protected function creerUtilisateur(
        string $email,
        string $motDePasse,
        Role $role = Role::User,
        bool $actif = true,
        string $nom = 'Durand',
        string $prenom = 'Camille',
    ): Utilisateur {
        $conteneur = static::getContainer();

        $utilisateur = new Utilisateur($email, $nom, $prenom, $role);
        $utilisateur->setActif($actif);
        $utilisateur->setDoitChangerMdp(false);
        $utilisateur->setMotDePasseHash(
            $conteneur->get(UserPasswordHasherInterface::class)->hashPassword($utilisateur, $motDePasse),
        );

        $entityManager = $conteneur->get(EntityManagerInterface::class);
        $entityManager->persist($utilisateur);
        $entityManager->flush();

        return $utilisateur;
    }

    /**
     * Compte administrateur dont la double authentification est déjà active.
     *
     * @return array{0: Utilisateur, 1: string} le compte et son secret TOTP en clair (connu du test seulement)
     */
    protected function creerAdminEnrole(string $email, string $motDePasse, string $nom = 'Admin', string $prenom = 'Alice'): array
    {
        $conteneur = static::getContainer();
        $admin = $this->creerUtilisateur($email, $motDePasse, Role::Admin, nom: $nom, prenom: $prenom);

        $secret = $conteneur->get(AuthentificateurTotp::class)->generateSecret();
        $admin->preparerTotp($conteneur->get(ChiffreurTotp::class)->chiffrer($secret));
        $admin->activerTotp();
        $conteneur->get(EntityManagerInterface::class)->flush();

        return [$admin, $secret];
    }

    /** Le code à 6 chiffres que montrerait le téléphone en ce moment (ou un autre créneau de 30 s). */
    protected function codeTotp(string $secret, int $decalageCreneaux = 0): string
    {
        $totp = TOTP::createFromSecret($secret, new NativeClock());
        $totp->setPeriod(30);
        $totp->setDigits(6);
        $totp->setDigest('sha1');

        return $totp->at(time() + 30 * $decalageCreneaux);
    }

    /** Un code à 6 chiffres qui n'est valable dans aucun des créneaux acceptés par le serveur. */
    protected function codeTotpFaux(string $secret): string
    {
        $valides = [$this->codeTotp($secret, -2), $this->codeTotp($secret, -1), $this->codeTotp($secret), $this->codeTotp($secret, 1), $this->codeTotp($secret, 2)];
        $code = '000000';
        while (\in_array($code, $valides, true)) {
            $code = str_pad((string) ((int) $code + 1), 6, '0', \STR_PAD_LEFT);
        }

        return $code;
    }

    /**
     * Lignes du journal d'audit, de la plus ancienne à la plus récente.
     *
     * @return list<array<string, mixed>>
     */
    protected function journal(): array
    {
        return $this->connexionSql()->fetchAllAssociative(
            'SELECT action, ip, id_acteur, cible_type, cible_id, detail FROM journal_audit ORDER BY id_log',
        );
    }

    /** @return list<string> */
    protected function actionsDuJournal(): array
    {
        return array_column($this->journal(), 'action');
    }

    /** @return array<string, mixed> */
    protected function compteEnBase(string $email): array
    {
        $ligne = $this->connexionSql()->fetchAssociative('SELECT * FROM utilisateur WHERE email = ?', [$email]);
        self::assertIsArray($ligne, sprintf('Le compte %s devrait exister.', $email));

        return $ligne;
    }
}
