<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\CoreBundle\API\UserSessionInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Response;

class LDAPPersonProvider implements PersonProviderInterface
{
    /** @var LDAPApi */
    private $ldapApi;

    /** @var UserSessionInterface */
    private $userSession;

    public function __construct(LDAPApi $ldapApi, UserSessionInterface $userSession)
    {
        $this->ldapApi = $ldapApi;
        $this->userSession = $userSession;
    }

    /**
     * @param array $options Available options:
     *                       * Person::SEARCH_PARAMETER_NAME (whitespace separated list of search terms to perform a partial case-insensitive text search on person's full name)
     *                       * LocalData::INCLUDE_PARAMETER_NAME
     *                       * LocalData::QUERY_PARAMETER_NAME
     *
     * @return Person[]
     *
     * @throws ApiError
     */
    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        return $this->ldapApi->getItemCollection($currentPageNumber, $maxNumItemsPerPage, $options);
    }

    /**
     * Throws an HTTP_NOT_FOUND exception if no person with the given ID can be found.
     *
     * @param array $options Available options:
     *                       * LocalData::INCLUDE_PARAMETER_NAME
     *
     * @throws ApiError
     */
    public function getPerson(string $id, array $options = []): Person
    {
        /* @var Person */
        $person = $this->ldapApi->getItemById($id, $options);
        if ($person === null) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND);
        }

        return $person;
    }

    /**
     * Returns the Person matching the current user. Or null if there is no associated person
     * like when the client is another server. Throws an HTTP_NOT_FOUND exception if no person is found for the current user.
     *
     * @throws ApiError
     */
    public function getCurrentPerson(array $options = []): ?Person
    {
        dump('HELLO WORLD!');
        $currentUserIdentifier = $this->userSession->getUserIdentifier();

        return $currentUserIdentifier !== null ? $this->ldapApi->getItemById($currentUserIdentifier, $options) : null;
    }
}
