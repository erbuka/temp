<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jose\Component\Core\JWK;
use Symfony\Component\Uid\Command\InspectUlidCommand;
use Symfony\Component\Uid\Command\InspectUuidCommand;

return function(ContainerConfigurator $configurator) {
    $configurator->parameters()
        ->set('cache_dir', '%kernel.cache_dir%')
        ->set('container.dumper.inline_factories', true)
    ;

    // default configuration for services in *this* file
    $services = $configurator->services()
        ->defaults()
            ->autowire()      // Automatically injects dependencies in your services.
            ->autoconfigure() // Automatically registers your services as commands, event subscribers, etc.
            ->bind('$cacheDir', '%kernel.cache_dir%')
            ->bind('$emailRegione', '%env(trim:EMAIL_REGIONE)%')
    ;

    $services->alias(JWK::class.' $apiJwk', 'jose.key.api');

    // makes classes in src/ available to be used as services
    // this creates a service per class whose id is the fully-qualified class name
    $services->load('App\\', '../src/*')
        ->exclude('../src/{DependencyInjection,Entity,Tests,Kernel.php,DataFixtures}')
        ->exclude('../src/{Utils.php,ScheduleManager.php}')
    ;

    $services
        ->set(InspectUuidCommand::class)
        ->set(InspectUlidCommand::class)
    ;
};
