<?php

namespace App\Repository;

use App\Entity\ContractedService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ContractedService|null find($id, $lockMode = null, $lockVersion = null)
 * @method ContractedService|null findOneBy(array $criteria, array $orderBy = null)
 * @method ContractedService[]    findAll()
 * @method ContractedService[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContractedServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractedService::class);
    }

    // /**
    //  * @return ContractedService[] Returns an array of ContractedService objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?ContractedService
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
