<?php


namespace App\Tests;


use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

trait DoctrineTrait
{
    protected static function getConnection(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    protected static function getManager(): \Doctrine\ORM\EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    protected static function persist(object $object): void
    {
        static::getManager()->persist($object);
    }

    protected static function flush(): void
    {
        static::getManager()->flush();
    }

    protected static function remove(object $object): void
    {
        static::getManager()->remove($object);
    }

    protected static function contains(object $object): bool
    {
        return static::getManager()->contains($object);
    }

    protected abstract static function getContainer(): ContainerInterface;
}
