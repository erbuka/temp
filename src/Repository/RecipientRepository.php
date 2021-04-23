<?php

namespace App\Repository;

use App\Entity\Recipient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Recipient|null find($id, $lockMode = null, $lockVersion = null)
 * @method Recipient|null findOneBy(array $criteria, array $orderBy = null)
 * @method Recipient[]    findAll()
 * @method Recipient[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recipient::class);
    }

    public function findOneByTaxId(string $taxId): ?Recipient
    {
        return $this->createQueryBuilder('r')
            ->where('r.vatId = :taxid OR r.fiscalCode = :taxid')
            ->setParameter('taxid', $taxId)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /*
    public function findOneBySomeField($value): ?Recipient
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
