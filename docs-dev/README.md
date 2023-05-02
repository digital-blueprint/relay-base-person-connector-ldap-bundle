# Developer Overview

## Development & Testing

* Install dependencies: `composer install`
* Run tests: `composer test`
* Run linters: `composer run lint`
* Run cs-fixer: `composer run cs-fix`

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
