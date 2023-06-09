# Configuration

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

Example environment variables for the above config:

```bash
# LDAP
LDAP_PERSON_PROVIDER_LDAP_HOST=directory.server.domain
LDAP_PERSON_PROVIDER_LDAP_USERNAME=cn=middleware,o=uni
LDAP_PERSON_PROVIDER_LDAP_BASE_DN=o=uni
LDAP_PERSON_PROVIDER_LDAP_PASSWORD=

LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_IDENTIFIER=cn
LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_GIVEN_NAME=givenName
LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_FAMILY_NAME=sn
LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_EMAIL=mail
LDAP_PERSON_PROVIDER_LDAP_ATTRIBUTE_BIRTHDAY=DateOfBirth
```
