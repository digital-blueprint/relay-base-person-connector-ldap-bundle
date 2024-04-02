<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection;

use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const LDAP_ATTRIBUTE = 'ldap';
    public const LDAP_ATTRIBUTES_ATTRIBUTE = 'attributes';
    public const LDAP_CONNECTION_ATTRIBUTE = 'connection';
    public const LDAP_IDENTIFIER_ATTRIBUTE_ATTRIBUTE = 'identifier';
    public const LDAP_GIVEN_NAME_ATTRIBUTE_ATTRIBUTE = 'given_name';
    public const LDAP_FAMILY_NAME_ATTRIBUTE_ATTRIBUTE = 'family_name';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dbp_relay_base_person_connector_ldap');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $ldapBuilder = new TreeBuilder(self::LDAP_ATTRIBUTE);
        $ldapNode = $ldapBuilder->getRootNode()
            ->children()
                ->scalarNode(self::LDAP_CONNECTION_ATTRIBUTE)
                   ->isRequired()
                   ->cannotBeEmpty()
                ->end()
            ->end();

        $attributesBuilder = new TreeBuilder(self::LDAP_ATTRIBUTES_ATTRIBUTE);
        $attributesNode = $attributesBuilder->getRootNode()
            ->children()
                ->scalarNode(self::LDAP_IDENTIFIER_ATTRIBUTE_ATTRIBUTE)->end()
                ->scalarNode(self::LDAP_GIVEN_NAME_ATTRIBUTE_ATTRIBUTE)->end()
                ->scalarNode(self::LDAP_FAMILY_NAME_ATTRIBUTE_ATTRIBUTE)->end()
            ->end();

        $ldapNode->append($attributesNode);
        $rootNode->append($ldapNode);
        $rootNode->append(PersonEventSubscriber::getLocalDataMappingConfigNodeDefinition());

        return $treeBuilder;
    }
}
