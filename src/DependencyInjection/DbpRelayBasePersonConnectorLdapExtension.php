<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection;

use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DbpRelayBasePersonConnectorLdapExtension extends ConfigurableExtension
{
    public function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');

        $definition = $container->getDefinition(LDAPPersonProvider::class);
        $definition->addMethodCall('setConfig', [$mergedConfig]);

        $postEventSubscriber = $container->getDefinition(PersonEventSubscriber::class);
        $postEventSubscriber->addMethodCall('setConfig', [$mergedConfig]);
    }
}
