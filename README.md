# DbpRelayBasePersonConnectorLdapBundle

[GitLab](https://gitlab.tugraz.at/dbp/relay/dbp-relay-base-person-connector-ldap-bundle) | [Packagist](https://packagist.org/packages/dbp/relay-base-person-connector-ldap-bundle)

This Symfony bundle contains LDAPPersonProvider services for the DBP Relay project.

## Integration into the API Server

* Add the bundle package as a dependency:

```bash
# You may want to first add the DBP Symfony recipe repository to your application to get the configuration file installed automatically
# See: https://github.com/digital-blueprint/symfony-recipes
# You can also use https://gitlab.tugraz.at/dbp/relay/dbp-relay-server-template as a template application, it has the repository included
composer require dbp/relay-base-person-connector-ldap-bundle
```

* Add the bundle to your `config/bundles.php`:

```php
...
Dbp\Relay\BasePersonConnectorLdapBundle\DbpRelayBasePersonConnectorLdapBundle::class => ['all' => true],
DBP\API\CoreBundle\DbpCoreBundle::class => ['all' => true],
];
```

* Run `composer install` to clear caches

## Configuration

The bundle has some configuration values that you can specify in your
app, either by hard-coding it, or by referencing an environment variable.

For this create `config/packages/dbp_relay_base_person_connector_ldap.yaml` in the app with the following
content:

```yaml
dbp_relay_base_person_connector_ldap:
  ldap:
    host: '%env(LDAP_PERSON_PROVIDER_LDAP_HOST)%'
    base_dn: '%env(LDAP_PERSON_PROVIDER_LDAP_BASE_DN)%'
    username: '%env(LDAP_PERSON_PROVIDER_LDAP_USERNAME)%'
    password: '%env(LDAP_PERSON_PROVIDER_LDAP_PASSWORD)%'
    attributes:
      identifier: '%env(LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_IDENTIFIER)%'
      given_name: '%env(LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_GIVEN_NAME)%'
      family_name: '%env(LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_FAMILY_NAME)%'
      email: '%env(LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_EMAIL)%'
      birthday: '%env(LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_BIRTHDAY)%'
```

For more info on bundle configuration see
https://symfony.com/doc/current/bundles/configuration.html

## Customization

You can implement the [LDAPApiProviderInterface](https://gitlab.tugraz.at/dbp/relay/dbp-relay-base-person-connector-ldap-bundle/-/blob/main/src/API/LDAPApiProviderInterface.php)
to customize how attributes are fetched from the LDAP server and assigned to the `Person` entity or what `Person` entities
are fetched from certain external services like in the [ALMA Bundle](https://gitlab.tugraz.at/dbp/library/api-alma-bundle).

You'll find an example at [DummyLDAPApiProvider.php](https://gitlab.tugraz.at/dbp/relay/dbp-relay-base-person-connector-ldap-bundle/-/blob/main/src/Service/DummyLDAPApiProvider.php).

If you don't need any customization, you don't need to implement the interface,
there is the default implementation which is used by default.

## Development & Testing

* Install dependencies: `composer install`
* Run tests: `composer test`
* Run linters: `composer run lint`
* Run cs-fixer: `composer run cs-fix`

## Bundle dependencies

Don't forget you need to pull down your dependencies in your main application if you are installing packages in a bundle.

```bash
# updates and installs dependencies from dbp/relay-base-person-connector-ldap-bundle
composer update dbp/relay-base-person-connector-ldap-bundle
```
