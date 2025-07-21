# Changelog

## Unreleased

## v0.5.13

- Update base-person-bundle: implement extended PersonProviderInterface, implementing getCurrentPersonIdentifier
- Allow for the configuration of the current user's person identifier using an authorization attribute expression 
`current_person_identifier_expression`
- Increase test coverage

## v0.5.12

- Update core and adapt

## v0.5.11

- Remove default sorting by family name
- Throw an error (507 insufficient storage) if sorting is requested and the number of results exceeds the limit
for the connection defined the ldap connector config

## v0.5.10

- Add support for api-platform 4.1
- Drop support for Symfony 5
- Drop support for PHP 8.1

## v0.5.9

- Apply filters for the get item request in the same way as the collection request (for them to be in line
with each other). This is relevant especially force-used filters (see dbp/relay-core-bundle release v0.1.207)
- Also trigger a `PersonPreEvent` for the get item request

## v0.5.8

- Test with mock LDAP

## v0.5.7

- Add check if user is authenticated before requesting user identifier, to prevent internal exception on
request of current person

## v0.5.6

- Fixes in case the provider is called in an unauthenticated context like health checks
- Make sure to ignore service accounts when caching the Person for the current user

## v0.5.5

- Update core and core-connector-ldap
- Add support for sort by multiple attributes

## v0.5.3

- Add support for api-platform 3.2

## v0.5.0

- Remove direct LDAP (abandoned Adldap2) dependency and use the LDAP API from dbp/relay-core-connector-ldap-bundle (requires config update)
- Add support for sorting to the person collection request

## v0.4.12

- Add support for Symfony 6

## v0.4.11

- Drop support for PHP 7.4/8.0

## v0.4.10

- Drop support for PHP 7.3

## v0.4.6

- Use the global "cache.app" adapter for caching instead of always using the filesystem adapter

## v0.4.2

- Update to api-platform 2.7

## v0.3.3

- config: ldap.encryption gained an option "plain" for disabling encryption
