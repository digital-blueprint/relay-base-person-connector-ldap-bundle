<?php

declare(strict_types=1);

namespace Dbp\Relay\LdapPersonProviderBundle\DependencyInjection;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DbpRelayLdapPersonProviderExtension extends ConfigurableExtension
{
    public function loadInternal(array $mergedConfig, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');

        $ldapCache = $container->register('dbp_api.cache.ldap_person_provider.ldap', FilesystemAdapter::class);
        $ldapCache->setArguments(['core-ldap', 360, '%kernel.cache_dir%/dbp/ldap-person-provider-ldap']);
        $ldapCache->setPublic(true);
        $ldapCache->addTag('cache.pool');

        $personCacheDef = $container->register('dbp_api.cache.ldap_person_provider.auth_person', FilesystemAdapter::class);
        $personCacheDef->setArguments(['core-auth-person', 60, '%kernel.cache_dir%/dbp/ldap-person-provider-auth-person']);
        $personCacheDef->addTag('cache.pool');

        // Inject the config value into the UCardService service
        $definition = $container->getDefinition('Dbp\Relay\LdapPersonProviderBundle\Service\LDAPApi');
        $definition->addMethodCall('setConfig', [$mergedConfig]);
        $definition->addMethodCall('setLDAPCache', [$ldapCache, 360]);
        $definition->addMethodCall('setPersonCache', [$personCacheDef]);
    }

    private function extendArrayParameter(ContainerBuilder $container, string $parameter, array $values)
    {
        if (!$container->hasParameter($parameter)) {
            $container->setParameter($parameter, []);
        }
        $oldValues = $container->getParameter($parameter);
        assert(is_array($oldValues));
        $container->setParameter($parameter, array_merge($oldValues, $values));
    }
}
