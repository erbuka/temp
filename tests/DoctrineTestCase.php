<?php


namespace App\Tests;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

trait DoctrineTestCase
{
    protected static function getConnection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    protected static function getManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    protected static function persist(object $object)
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

    protected static function find(string $className, mixed $id): ?object
    {
        return static::getManager()->find($className, $id);
    }

    protected abstract static function getContainer(): ContainerInterface;
}
