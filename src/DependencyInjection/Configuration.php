<?php

declare(strict_types=1);

namespace Dbp\Relay\LdapPersonProviderBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('dbp_relay_ldap_person_provider');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $ldapBuilder = new TreeBuilder('ldap');
        $ldapNode = $ldapBuilder->getRootNode()
            ->children()
            ->scalarNode('host')->end()
            ->scalarNode('base_dn')->end()
            ->scalarNode('username')->end()
            ->scalarNode('password')->end()
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
