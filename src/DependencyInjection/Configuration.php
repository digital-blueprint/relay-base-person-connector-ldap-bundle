<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dbp_relay_base_person_connector_ldap');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $ldapBuilder = new TreeBuilder('ldap');
        $ldapNode = $ldapBuilder->getRootNode()
            ->children()
            ->scalarNode('host')->end()
            ->scalarNode('base_dn')->end()
            ->scalarNode('username')->end()
            ->scalarNode('password')->end()
            ->enumNode('encryption')
                ->info('simple_tls uses port 636 and is sometimes referred to as "SSL", start_tls uses port 389 and is sometimes referred to as "TLS"')
                ->values(['start_tls', 'simple_tls'])
                ->defaultValue('start_tls')
            ->end()
            ->end();

        $attributesBuilder = new TreeBuilder('attributes');
        $attributesNode = $attributesBuilder->getRootNode()
            ->children()
            ->scalarNode('identifier')->end()
            ->scalarNode('given_name')->end()
            ->scalarNode('family_name')->end()
            ->scalarNode('email')->end()
            ->scalarNode('birthday')->end()
            ->end();
        $ldapNode->append($attributesNode);

        $rootNode->append($ldapNode);

        return $treeBuilder;
    }
}
