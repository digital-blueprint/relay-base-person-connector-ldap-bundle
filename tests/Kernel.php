<?php

declare(strict_types=1);

namespace Dbp\Relay\LdapPersonProviderBundle\Tests;

use ApiPlatform\Core\Bridge\Symfony\Bundle\ApiPlatformBundle;
use Dbp\Relay\BasePersonBundle\DbpRelayBasePersonBundle;
use Dbp\Relay\CoreBundle\DbpRelayCoreBundle;
use Dbp\Relay\LdapPersonProviderBundle\DbpRelayLdapPersonProviderBundle;
use Nelmio\CorsBundle\NelmioCorsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ApiPlatformBundle();
        yield new DbpRelayBasePersonBundle();
        yield new DbpRelayCoreBundle();
        yield new DbpRelayLdapPersonProviderBundle();
        yield new NelmioCorsBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader)
    {
        $container->import('@DbpRelayCoreBundle/Resources/config/services_test.yaml');
        $container->import('@DbpRelayLdapPersonProviderBundle/Resources/config/services_test.yaml');
        $container->extension('framework', [
            'test' => true,
            'secret' => '',
        ]);

        $container->extension('dbp_relay_ldap_person_provider', [
            'ldap' => [],
        ]);
    }
}
