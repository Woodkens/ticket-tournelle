<?php

namespace App\Repository;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux comptes. Toutes les requêtes passent par Doctrine (requêtes préparées) :
 * aucune concaténation SQL, donc pas d'injection possible.
 *
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 25;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    public function findOneByEmail(string $email): ?Utilisateur
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /** Nombre d'administrateurs actifs, sans compter le compte donné (règle « au moins un admin actif »). */
    public function compterAdminsActifsSauf(Utilisateur $compte): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.role = :role')
            ->andWhere('u.actif = true')
            ->andWhere('u.id <> :id')
            ->setParameter('role', Role::Admin)
            ->setParameter('id', $compte->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Liste paginée de l'écran d'administration : recherche sur le nom, le prénom ou l'e-mail,
     * filtres par rôle et par état. Le texte cherché est passé en PARAMÈTRE, jamais collé dans la requête.
     *
     * @return Paginator<Utilisateur>
     */
    public function rechercher(?string $recherche, ?Role $role, ?bool $actif, int $page): Paginator
    {
        $requete = $this->createQueryBuilder('u')->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');

        $recherche = trim((string) $recherche);
        if ('' !== $recherche) {
            // % et _ sont des jokers de LIKE : on les neutralise pour chercher le texte tel quel
            $motif = '%'.addcslashes(mb_strtolower($recherche), '%_\\').'%';
            $requete
                ->andWhere('LOWER(u.nom) LIKE :motif OR LOWER(u.prenom) LIKE :motif OR u.email LIKE :motif')
                ->setParameter('motif', $motif);
        }
        if (null !== $role) {
            $requete->andWhere('u.role = :role')->setParameter('role', $role);
        }
        if (null !== $actif) {
            $requete->andWhere('u.actif = :actif')->setParameter('actif', $actif);
        }

        $requete->setFirstResult((max(1, $page) - 1) * self::PAR_PAGE)->setMaxResults(self::PAR_PAGE);

        return new Paginator($requete->getQuery());
    }

    /**
     * Dernière connexion réussie de chaque compte, lue dans le journal d'audit : pas besoin d'une colonne
     * de plus dans `utilisateur` (l'index idx_audit_acteur rend la recherche rapide).
     *
     * @param list<int> $ids
     *
     * @return array<int, \DateTimeImmutable> numéro du compte => date
     */
    public function dernieresConnexions(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(j.acteur) AS id', 'MAX(j.dateAction) AS derniere')
            ->from(JournalAudit::class, 'j')
            ->andWhere('j.action = :action')
            ->andWhere('j.acteur IN (:ids)')
            ->groupBy('j.acteur')
            ->setParameter('action', ActionAudit::ConnexionReussie->value)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        foreach ($lignes as $ligne) {
            $dates[(int) $ligne['id']] = new \DateTimeImmutable((string) $ligne['derniere']);
        }

        return $dates;
    }
}
