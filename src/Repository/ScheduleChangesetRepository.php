<?php

namespace App\Repository;

use App\Entity\ScheduleChangeset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ScheduleChangeset|null find($id, $lockMode = null, $lockVersion = null)
 * @method ScheduleChangeset|null findOneBy(array $criteria, array $orderBy = null)
 * @method ScheduleChangeset[]    findAll()
 * @method ScheduleChangeset[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ScheduleChangesetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduleChangeset::class);
    }

    // /**
    //  * @return ScheduleChangeset[] Returns an array of ScheduleChangeset objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?ScheduleChangeset
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
