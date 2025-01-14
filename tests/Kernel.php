<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Dbp\Relay\BasePersonBundle\DbpRelayBasePersonBundle;
use Dbp\Relay\BasePersonConnectorLdapBundle\DbpRelayBasePersonConnectorLdapBundle;
use Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection\Configuration;
use Dbp\Relay\CoreBundle\DbpRelayCoreBundle;
use Dbp\Relay\CoreConnectorLdapBundle\DbpRelayCoreConnectorLdapBundle;
use Dbp\Relay\CoreConnectorLdapBundle\TestUtils\TestLdapConnectionProvider;
use Nelmio\CorsBundle\NelmioCorsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ApiPlatformBundle();
        yield new MonologBundle();
        yield new DbpRelayBasePersonBundle();
        yield new DbpRelayCoreBundle();
        yield new DbpRelayCoreConnectorLdapBundle();
        yield new DbpRelayBasePersonConnectorLdapBundle();
        yield new NelmioCorsBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
    }

    protected function configureRoutes(RoutingConfigurator $routes)
    {
        $routes->import('@DbpRelayCoreBundle/Resources/config/routing.yaml');
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader)
    {
        $container->import('@DbpRelayCoreBundle/Resources/config/services_test.yaml');
        $container->extension('framework', [
            'test' => true,
            'secret' => '',
        ]);

        $container->extension('dbp_relay_base_person_connector_ldap', [
            Configuration::LDAP_ATTRIBUTE => [
                Configuration::LDAP_CONNECTION_ATTRIBUTE => TestLdapConnectionProvider::DEFAULT_CONNECTION_IDENTIFIER,
                Configuration::LDAP_ATTRIBUTES_ATTRIBUTE => [
                    Configuration::LDAP_IDENTIFIER_ATTRIBUTE_ATTRIBUTE => 'cn',
                    Configuration::LDAP_GIVEN_NAME_ATTRIBUTE_ATTRIBUTE => 'givenName',
                    Configuration::LDAP_FAMILY_NAME_ATTRIBUTE_ATTRIBUTE => 'sn',
                ],
            ],
        ]);
    }
}
