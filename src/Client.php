<?php

declare(strict_types=1);

namespace Darwin;

use Darwin\Models\Client as ClientModel;
use Darwin\Models\Country;
use Darwin\Models\MarketingSource;

/**
 * @psalm-type ClientPayload = array{
 *     title?: non-empty-string,
 *     firstname?: non-empty-string,
 *     initials?: non-empty-string,
 *     lastname?: non-empty-string,
 *     maidenname?: non-empty-string,
 *     sex?: 'M'|'F',
 *     housenamenumber?: non-empty-string,
 *     address1?: non-empty-string,
 *     address2?: non-empty-string,
 *     address3?: non-empty-string,
 *     town?: non-empty-string,
 *     county?: non-empty-string,
 *     postcode?: non-empty-string,
 *     country?: non-empty-string,
 *     homephone?: non-empty-string,
 *     workphone?: non-empty-string,
 *     mobilephone?: non-empty-string,
 *     secondaryemail?: non-empty-string,
 *     passportcountry?: non-empty-string,
 *     dateofbirth?: non-empty-string,
 *     allowmailing?: 1|0,
 *     allowemail?: 1|0,
 *     overseas?: 1|0,
 *     clientnotes?: non-empty-string,
 *     externalref?: non-empty-string,
 *     preferredcontactmethod?: 'Email'|'Telephone'|int<1, 8>,
 *     saleschannel?: non-empty-string,
 *     passportnumber?: non-empty-string,
 *     nationality?: non-empty-string,
 *     diet?: non-empty-string,
 *     medical?: non-empty-string,
 * }&array<string, mixed>
 * @psalm-type EnquiryPayload = array{
 *     description?: non-empty-string,
 *     notes?: non-empty-string,
 *     dateoftravel?: non-empty-string,
 *     countryofinterest1?: int,
 *     countryofinterest2?: int,
 *     countryofinterest3?: int,
 *     countryofinterest4?: int,
 *     countryofinterest5?: int,
 *     othercountry?: non-empty-string,
 *     nights?: int,
 *     adults?: int,
 *     children?: int,
 *     numberofsinglepax?: int,
 *     childages?: non-empty-string,
 *     rooms?: int,
 *     roomtype?: non-empty-string,
 *     channel?: non-empty-string,
 *     createdby?: int,
 *     originatingsource?: int,
 *     triptype?: non-empty-string,
 *     weblevelofinterest?: non-empty-string,
 *     region?: non-empty-string,
 *     duration?: non-empty-string,
 *     brochurecode?: non-empty-string,
 *     assigneeoverride?: int,
 *     bookingstartdate?: non-empty-string,
 *     isbooking?: 1|0,
 *     agentid?: int|null,
 *     pax?: list<array{
 *         firstname?: non-empty-string,
 *         lastname?: non-empty-string,
 *         title?: non-empty-string,
 *         passportcountry?: non-empty-string,
 *         dateofbirth?: non-empty-string,
 *         passportnumber?: non-empty-string,
 *         nationality?: non-empty-string,
 *         diet?: non-empty-string,
 *         medical?: non-empty-string,
 *         ischild?: non-empty-string,
 *     }>,
 *     emergencycontacts?: list<array{
 *         emergencycontactname?: non-empty-string,
 *         emergencycontactphone?: non-empty-string,
 *         emergencycontactemail?: non-empty-string,
 *         emergencycontactrelationship?: non-empty-string,
 *     }>,
 *     insurances?: list<array{
 *          insuranceprovidername?: non-empty-string,
 *          insurancepolicynumber?: non-empty-string,
 *          insurance24hrcontact?: non-empty-string,
 *      }>,
 * }&array<string, mixed>
 */
interface Client
{
    /**
     * This method applies the given data to the customer that matches the given email address and returns the client id
     *
     * There is currently no way of supplying an email address and using the data to actually create a new client if
     * that email address is already in use, therefore, this operation should be considered destructive.
     *
     * The email address does not have to be valid - you can literally provide any non-empty string, and the API will
     * accept it. At least this feature will allow some uniqueness to each client payload; for example, the email could
     * be replaced with a UUID that links to another system of record for actually storing the relevant information.
     *
     * Any field not present is written as a NULL in the remote server. This means that if you do an insert with
     * {firstname: fred, lastname: bloggs} followed by an update of {firstname: fred}, then the surname will be set to
     * null. Fun times.
     *
     * @param non-empty-string $emailAddress
     * @param ClientPayload    $clientData
     *
     * @throws RequestFailed If an error occurs executing the request.
     * @throws UnexpectedAPIPayload If the remote server response is erroneous.
     */
    public function createOrUpdateClientWithEmailAddress(string $emailAddress, array $clientData): int;

    /**
     * Retrieve client information by searching by email address
     *
     * This method returns the _most recently inserted_ client when there are multiple clients sharing the same email
     * address, it is therefore not possible to use this method to retrieve an "old" client.
     *
     * @param non-empty-string $emailAddress
     *
     * @throws RequestFailed If an error occurs executing the request.
     * @throws UnexpectedAPIPayload If the remote server response is erroneous.
     */
    public function findClientByEmailAddress(string $emailAddress): ClientModel|null;

    /**
     * @return list<Country>
     *
     * @throws RequestFailed If an error occurs executing the request.
     * @throws UnexpectedAPIPayload If the remote server response is erroneous.
     */
    public function listCountries(): array;

    /**
     * @param EnquiryPayload $payload
     *
     * @throws RequestFailed If an error occurs executing the request.
     * @throws UnexpectedAPIPayload If the remote server response is erroneous.
     */
    public function createEnquiry(int $clientId, array $payload): int;

    /**
     * @return list<MarketingSource>
     *
     * @throws RequestFailed If an error occurs executing the request.
     * @throws UnexpectedAPIPayload If the remote server response is erroneous.
     */
    public function getMarketingSourceCodes(): array;
}
