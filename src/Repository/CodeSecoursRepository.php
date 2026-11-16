<?php

namespace App\Repository;

use App\Entity\CodeSecours;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CodeSecours>
 */
class CodeSecoursRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeSecours::class);
    }

    /**
     * Codes encore utilisables d'un administrateur.
     *
     * @return list<CodeSecours>
     */
    public function findUtilisables(Utilisateur $utilisateur): array
    {
        return $this->findBy(['utilisateur' => $utilisateur, 'dateUtilisation' => null]);
    }
}
