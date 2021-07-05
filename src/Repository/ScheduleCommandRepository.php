<?php

namespace App\Repository;

use App\Entity\ScheduleCommand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ScheduleCommand|null find($id, $lockMode = null, $lockVersion = null)
 * @method ScheduleCommand|null findOneBy(array $criteria, array $orderBy = null)
 * @method ScheduleCommand[]    findAll()
 * @method ScheduleCommand[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ScheduleCommandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduleCommand::class);
    }

    // /**
    //  * @return ScheduleCommand[] Returns an array of ScheduleCommand objects
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
    public function findOneBySomeField($value): ?ScheduleCommand
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
