# Example Customizations

## Customization

The bundle sends out [certain events](https://gitlab.tugraz.at/dbp/relay/dbp-relay-base-person-connector-ldap-bundle/-/tree/main#events)
you can hook on to inject specific information in an event subscriber.
Please take a look at the [Event Subscriber Documentation](https://gitlab.tugraz.at/dbp/relay/dbp-relay-base-person-connector-ldap-bundle/-/tree/main#events)
of the bundle for more information.

If you don't need any customization, you don't need to implement any event subscribers, but the ones needed by the software package you are using.

### Check-in

For the [Check-In project](https://handbook.digital-blueprint.org/components/api/check-in) you need to set `ROLE_SCOPE_LOCATION-CHECK-IN` and `ROLE_SCOPE_LOCATION-CHECK-IN-GUEST`
as `ldap-roles` for the person. You can do that by implementing an event subscriber for the `PersonFromUserItemPostEvent` event.

Please take a look at the [PersonFromUserItemPostEvent Documentation](./events.md#personfromuseritempostevent)
of the bundle for more information.

An event subscriber receives a `\Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent` instance
in a service for example in `src/EventSubscriber/PersonFromUserItemSubscriber.php`:

```php
<?php

declare(strict_types=1);

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
        $person = $event->getPerson();

        // TODO: Add code to decide what roles a user has.
        $roles = ['ROLE_SCOPE_LOCATION-CHECK-IN', 'ROLE_SCOPE_LOCATION-CHECK-IN-GUEST'];
        $person->setExtraData('ldap-roles', $roles);

        $event->setPerson($person);
    }
}
```

You can find the full example at [PersonFromUserItemSubscriber.php](https://gitlab.tugraz.at/dbp/relay/examples/relay-checkin-api/-/blob/main/src/EventSubscriber/PersonFromUserItemSubscriber.php).

Afterwards best do a `composer install` to make sure caches are cleared and everything is in order.

### Greenlight

[Greenlight project](https://handbook.digital-blueprint.org/components/api/greenlight) you need to set `ROLE_SCOPE_GREENLIGHT` as `ldap-roles` for the person.
You can do that by implementing an event subscriber for the `PersonFromUserItemPostEvent` event.

Please take a look at the [PersonFromUserItemPostEvent Documentation](./events.md#personfromuseritempostevent)
of the bundle for more information.

An event subscriber receives a `\Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent` instance
in a service for example in `src/EventSubscriber/PersonFromUserItemSubscriber.php`:

```php
<?php

declare(strict_types=1);

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
        $person = $event->getPerson();

        // TODO: Add code to decide what roles a user has.
        $roles = ['ROLE_SCOPE_GREENLIGHT'];
        $person->setExtraData('ldap-roles', $roles);

        $event->setPerson($person);
    }
}
```

You can find the full example at [PersonFromUserItemSubscriber.php](https://gitlab.tugraz.at/dbp/relay/examples/relay-greenlight-api/-/blob/main/src/EventSubscriber/PersonFromUserItemSubscriber.php).

Afterwards best do a `composer install` to make sure caches are cleared and everything is in order.

### ESign

[Esign project](https://handbook.digital-blueprint.org/components/api/esign) you need to set the roles you set in `config/packages/dbp_relay_esign.yaml` as
`ldap-roles` for the person if it should have permission to the signature profile.
You can do that by implementing an event subscriber for the `PersonFromUserItemPostEvent` event.

Please take a look at the [PersonFromUserItemPostEvent Documentation](./events.md#personfromuseritempostevent)
of the bundle for more information.

An event subscriber receives a `\Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent` instance
in a service for example in `src/EventSubscriber/PersonFromUserItemSubscriber.php`:

```php
<?php

declare(strict_types=1);

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
        $person = $event->getPerson();

        // TODO: Add code to decide what roles a user has.
        // These are the scopes you set in `config/packages/dbp_relay_esign.yaml`
        $roles = ['ROLE_SCOPE_YOUR_SCOPE1', 'ROLE_SCOPE_YOUR_SCOPE2'];
        $person->setExtraData('ldap-roles', $roles);

        $event->setPerson($person);
    }
}
```

You can find the full example at [PersonFromUserItemSubscriber.php](https://gitlab.tugraz.at/dbp/relay/examples/relay-esign-api/-/blob/main/src/EventSubscriber/PersonFromUserItemSubscriber.php).

Afterwards best do a `composer install` to make sure caches are cleared and everything is in order.
