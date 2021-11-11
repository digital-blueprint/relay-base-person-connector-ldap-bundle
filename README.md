# DbpRelayLdapPersonProviderBundle

[GitLab](https://gitlab.tugraz.at/dbp/relay/dbp-relay-ldap-person-provider-bundle) | [Packagist](https://packagist.org/packages/dbp/relay-ldap-person-provider-bundle)

This Symfony bundle contains LDAPPersonProvider services for the DBP Relay project.

## Integration into the API Server

* Add the bundle package as a dependency:

```
composer require dbp/relay-ldap-person-provider-bundle
```

* Add the bundle to your `config/bundles.php`:

```php
...
Dbp\Relay\LdapPersonProviderBundle\DbpRelayLdapPersonProviderBundle::class => ['all' => true],
DBP\API\CoreBundle\DbpCoreBundle::class => ['all' => true],
];
```

* Run `composer install` to clear caches

## Configuration

The bundle has some configuration values that you can specify in your
app, either by hardcoding it, or by referencing an environment variable.

For this create `config/packages/dbp_relay_ldap_person_provider.yaml` in the app with the following
content:

```yaml
dbp_relay_ldap_person_provider:
  co_oauth2_ucardapi_api_url:
  co_oauth2_ucardapi_client_id:
  co_oauth2_ucardapi_client_secret:
```

The value gets read in `DbpRelayLdapPersonProviderExtension` and passed when creating the
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
# updates and installs dependencies from dbp/relay-ldap-person-provider-bundle
composer update dbp/relay-ldap-person-provider-bundle
```
