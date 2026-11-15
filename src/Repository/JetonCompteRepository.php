<?php

namespace App\Repository;

use App\Entity\JetonCompte;
use App\Entity\Utilisateur;
use App\Enum\TypeJeton;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JetonCompte>
 */
class JetonCompteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JetonCompte::class);
    }

    public function findOneByHash(string $jetonHash): ?JetonCompte
    {
        return $this->findOneBy(['jetonHash' => $jetonHash]);
    }

    /**
     * Liens encore utilisables d'un compte, pour les annuler quand on en envoie un nouveau.
     *
     * @return list<JetonCompte>
     */
    public function findUtilisables(Utilisateur $utilisateur, ?TypeJeton $type = null): array
    {
        $requete = $this->createQueryBuilder('j')
            ->andWhere('j.utilisateur = :utilisateur')
            ->andWhere('j.dateUtilisation IS NULL')
            ->setParameter('utilisateur', $utilisateur);

        if (null !== $type) {
            $requete->andWhere('j.type = :type')->setParameter('type', $type);
        }

        return $requete->getQuery()->getResult();
    }
}
