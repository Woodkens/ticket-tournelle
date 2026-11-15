<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux comptes. Toutes les requêtes passent par Doctrine (requêtes préparées) :
 * aucune concaténation SQL, donc pas d'injection possible.
 *
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    public function findOneByEmail(string $email): ?Utilisateur
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }
}
