<?php

declare(strict_types=1);

namespace Dbp\Relay\LdapPersonProviderBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('dbp_relay_ldap_person_provider');

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('co_oauth2_ucardapi_client_id')->end()
            ->scalarNode('co_oauth2_ucardapi_client_secret')->end()
            ->scalarNode('co_oauth2_ucardapi_api_url')->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
