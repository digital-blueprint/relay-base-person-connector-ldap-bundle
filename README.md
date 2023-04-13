 # DbpRelayBasePersonConnectorLdapBundle

[GitHub](https://github.com/digital-blueprint/relay-base-person-connector-ldap-bundle) | [Packagist](https://packagist.org/packages/dbp/relay-base-person-connector-ldap-bundle)

[![Test](https://github.com/digital-blueprint/relay-base-person-connector-ldap-bundle/actions/workflows/test.yml/badge.svg)](https://github.com/digital-blueprint/relay-base-person-connector-ldap-bundle/actions/workflows/test.yml)

This Symfony bundle contains LDAPPersonProvider services for the DBP Relay project.

## Integration into the API Server

* Add the bundle package as a dependency:

```bash
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

## Events

To modify the behavior of the connector bundle the following events are registered:

### PersonUserItemPreEvent

This event allows to modify the identifier before a user is loaded from LDAP.

An event subscriber receives a `\Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonUserItemPreEvent` instance
in a service for example in `src/EventSubscriber/PersonUserItemSubscriber.php`:

```php
<?php

namespace App\EventSubscriber;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonUserItemPreEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonUserItemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonUserItemPreEvent::NAME => 'onPre',
        ];
    }

    public function onPre(PersonUserItemPreEvent $event)
    {
        $identifier = $event->getIdentifier();

        // Example:
        // Replace once or double encoded $ character at the start like "%2524F1234" or "%24F1234"
        $identifier = preg_replace('/^%(25)?24/', '$', $identifier);

        $event->setIdentifier($identifier);
    }
}
```

### PersonFromUserItemPostEvent

This event allows to modify a `Person` entity after it is created based on the data from the corresponding LDAP user.
You can use it to populate the `Person` entity with additional data.

For example, you can add additional "local data" attributes, which you want to include in responses to `Person` GET requests.

Event subscribers receive a `\Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent` instance containing the `Person` entity and all user attributes returned by the LDAP server.

For example, create an event subscriber `src/EventSubscriber/PersonFromUserItemSubscriber.php`:

```php
<?php

namespace App\EventSubscriber;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonFromUserItemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonFromUserItemPostEvent::NAME => 'onPost',
        ];
    }

    public function onPost(PersonFromUserItemPostEvent $event)
    {
        $attributes = $event->getAttributes();
        $person = $event->getPerson();

        $birthDateString = trim($attributes['dateofbirth'][0] ?? '');

        if ($birthDateString !== '') {
            $matches = [];

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthDateString, $matches)) {
                $person->setBirthDate("{$matches[1]}-{$matches[2]}-{$matches[3]}");
            // get birthday from LDAP DateOfBirth (e.g. 19810718)
            } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $birthDateString, $matches)) {
                $person->setBirthDate("{$matches[1]}-{$matches[2]}-{$matches[3]}");
            // sometimes also "1994-06-14 00:00:00"
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2}) .*$/', $birthDateString, $matches)) {
                $person->setBirthDate("{$matches[1]}-{$matches[2]}-{$matches[3]}");
            }
        }

        $person->setExtraData('special_data', $attributes['some_special_attribute'] ?? '');
        
        $person->trySetLocalDataAttribute('foo', $attributes['foo']);
    }
}
```

And add it to your `src/Resources/config/services.yaml`:

```yaml
App\EventSubscriber\PersonFromUserItemSubscriber:
  autowire: true
  autoconfigure: true
```

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
