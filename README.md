# DbpRelayBasePersonConnectorLdapBundle

[GitLab](https://gitlab.tugraz.at/dbp/relay/dbp-relay-base-person-connector-ldap-bundle) | [Packagist](https://packagist.org/packages/dbp/relay-base-person-connector-ldap-bundle)

This Symfony bundle contains BasePersonConnectorLdap services for the DBP Relay project.

## Integration into the API Server

* Add the bundle package as a dependency:

```
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
app, either by hardcoding it, or by referencing an environment variable.

For this create `config/packages/dbp_relay_base_person_connector_ldap.yaml` in the app with the following
content:

```yaml
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

The value gets read in `DbpRelayBasePersonConnectorLdapExtension` and passed when creating the
`UCardService` service.

For more info on bundle configuration see
https://symfony.com/doc/current/bundles/configuration.html

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
