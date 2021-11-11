<?php

declare(strict_types=1);

namespace Dbp\Relay\LdapPersonProviderBundle\Tests;

use Dbp\Relay\LdapPersonProviderBundle\DbpRelayLdapPersonProviderBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DbpRelayLdapPersonProviderBundle();
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader)
    {
        $container->import('@DbpRelayCoreBundle/Resources/config/services_test.yaml');
        $container->extension('framework', [
            'test' => true,
            'secret' => '',
        ]);

        $container->extension('dbp_relay_ldap_person_provider', [
            'secret_token' => 'secret-test',
        ]);
    }
}
