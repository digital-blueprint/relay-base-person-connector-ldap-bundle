# Unreleased

# v0.5.4

* Update core and core-connector-ldap
* Add support for sort by multiple attributes

# v0.5.3

* Add support for api-platform 3.2

# v0.5.0

* Remove direct LDAP (abandoned Adldap2) dependency and use the LDAP API from dbp/relay-core-connector-ldap-bundle (requires config update)
* Add support for sorting to the person collection request

# v0.4.12

* Add support for Symfony 6

# v0.4.11

* Drop support for PHP 7.4/8.0

# v0.4.10

* Drop support for PHP 7.3

# v0.4.6

* Use the global "cache.app" adapter for caching instead of always using the filesystem adapter

# v0.4.2

* Update to api-platform 2.7

# v0.3.3

* config: ldap.encryption gained an option "plain" for disabling encryption
