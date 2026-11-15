<?php

namespace App\Tests\Support;

use App\Entity\Utilisateur;
use App\Enum\Role;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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

    /** Repart d'une base vide et remet à zéro le compteur d'essais de connexion. */
    protected function viderLaBase(): void
    {
        $connexion = $this->connexionSql();

        // Garde-fou : on ne vide jamais autre chose qu'une base de test
        $base = (string) $connexion->fetchOne('SELECT DATABASE()');
        if (!str_ends_with($base, '_test')) {
            self::fail(sprintf('Les tests doivent tourner sur une base « _test », pas sur « %s ».', $base));
        }

        $connexion->executeStatement('DELETE FROM journal_audit');
        $connexion->executeStatement('DELETE FROM utilisateur');
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    /** Mot de passe tiré au hasard à chaque test : aucun mot de passe en clair dans Git. */
    protected function motDePasseAleatoire(): string
    {
        return 'Tt!9-'.bin2hex(random_bytes(12));
    }

    protected function creerUtilisateur(string $email, string $motDePasse, Role $role = Role::User, bool $actif = true): Utilisateur
    {
        $conteneur = static::getContainer();

        $utilisateur = new Utilisateur($email, 'Durand', 'Camille', $role);
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
     * Lignes du journal d'audit, de la plus ancienne à la plus récente.
     *
     * @return list<array<string, mixed>>
     */
    protected function journal(): array
    {
        return $this->connexionSql()->fetchAllAssociative(
            'SELECT action, ip, id_acteur, cible_type, cible_id FROM journal_audit ORDER BY id_log',
        );
    }

    /** @return list<string> */
    protected function actionsDuJournal(): array
    {
        return array_column($this->journal(), 'action');
    }
}
