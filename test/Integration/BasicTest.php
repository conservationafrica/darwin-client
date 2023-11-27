<?php

declare(strict_types=1);

namespace Darwin\Test\Integration;

use Darwin\DarwinError;
use Darwin\HttpClient;
use Darwin\Models\Client as ClientModel;
use Darwin\Models\Client as DarwinClient;
use Darwin\Models\Country;
use Darwin\RequestFailed;
use Darwin\UnexpectedAPIPayload;
use DateTimeZone;
use Http\Client\Curl\Client;
use Laminas\Diactoros\Request\Serializer as RequestSerializer;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\Response\Serializer as ResponseSerializer;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use Lcobucci\Clock\SystemClock;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function assert;
use function file_put_contents;
use function sprintf;
use function uniqid;

class BasicTest extends TestCase
{
    private HttpClient $client;

    protected function setUp(): void
    {
        try {
            ConfigValues::secret();
        } catch (Throwable) {
            self::markTestSkipped('Integration tests disabled. No environment variables set');
        }

        $streams      = new StreamFactory();
        $this->client = new HttpClient(
            ConfigValues::apiUrl(),
            ConfigValues::basePath(),
            ConfigValues::secret(),
            ConfigValues::companyId(),
            new SystemClock(new DateTimeZone('UTC')),
            new Client(new ResponseFactory(), $streams),
            new RequestFactory(),
            $streams,
        );
    }

    /**
     * @param callable(): T $test
     *
     * @return T
     *
     * @template T
     */
    private function tryClientMethod(callable $test, string $testMethodName): mixed
    {
        try {
            return $test();
        } catch (RequestFailed $e) {
            self::serialiseRequestFailure($e, $testMethodName);

            throw $e;
        }
    }

    private static function serialiseRequestFailure(DarwinError $error, string $methodName): void
    {
        $response = match ($error::class) {
            RequestFailed::class,
            UnexpectedAPIPayload::class => $error->response,
            default => null,
        };
        $request = match ($error::class) {
            RequestFailed::class,
            UnexpectedAPIPayload::class => $error->request,
            default => null,
        };

        self::serialiseRequestAndResponse($request, $response, $methodName);
    }

    private static function serialiseRequestAndResponse(
        RequestInterface|null $request,
        ResponseInterface|null $response,
        string $filename,
    ): void {
        if ($request !== null) {
            $file = sprintf('%s/request-failures/%s.request.http', __DIR__, $filename);
            file_put_contents($file, RequestSerializer::toString($request));
        }

        if ($response !== null) {
            $file = sprintf('%s/request-failures/%s.response.http', __DIR__, $filename);
            file_put_contents($file, ResponseSerializer::toString($response));
        }

        // fin.
    }

    private static function serialiseClientExchange(HttpClient $client, string $filename): void
    {
        self::serialiseRequestAndResponse($client->lastRequest(), $client->lastResponse(), $filename);
    }

    public function testCreateClient(): int
    {
        $id = $this->tryClientMethod(function (): int {
            return $this->client->createOrUpdateClientWithEmailAddress('me@example.com', [
                'firstname' => 'Fred',
                'lastname' => 'Jones',
            ]);
        }, __FUNCTION__);

        self::assertGreaterThan(0, $id);
        self::serialiseClientExchange($this->client, 'successfulBasicClientInsertion');

        return $id;
    }

    #[Depends('testCreateClient')]
    public function testCreateClientWithInvalidEmailAddress(): int
    {
        $id = $this->tryClientMethod(function (): int {
            return $this->client->createOrUpdateClientWithEmailAddress('invalid', [
                'firstname' => 'Invalid',
                'lastname' => 'Email Address',
            ]);
        }, __FUNCTION__);

        self::assertGreaterThan(0, $id);

        return $id;
    }

    #[Depends('testCreateClient')]
    public function testMultiByteCharactersInCustomerName(): int
    {
        $emailAddress = 'multibyte@example.com';
        $id = $this->tryClientMethod(function () use ($emailAddress): int {
            return $this->client->createOrUpdateClientWithEmailAddress($emailAddress, [
                'secondaryemail' => 'me@example.com',
                'sex' => 'F',
                'firstname' => 'Björk',
                'lastname' => 'Guðmundsdóttir',
                'passportcountry' => 'SE',
                'preferredcontactmethod' => 2,
                'country' => 'GBR',
            ]);
        }, __FUNCTION__);

        self::assertGreaterThan(0, $id);
        self::serialiseClientExchange($this->client, 'successfulMultiByteClientInsertion');

        $client = $this->tryClientMethod(function () use ($emailAddress): DarwinClient|null {
            return $this->client->findClientByEmailAddress($emailAddress);
        }, __FUNCTION__);

        self::assertInstanceOf(DarwinClient::class, $client);
        self::assertSame($id, $client->id);
        self::assertSame($emailAddress, $client->emailAddress);
        self::assertSame('Björk', $client->firstName);
        self::assertSame('Guðmundsdóttir', $client->lastName);

        return $id;
    }

    public function testCountryList(): void
    {
        $list = $this->client->listCountries();
        self::serialiseClientExchange($this->client, 'retrieveCountryList');
        self::assertNotSame([], $list);
        self::assertContainsOnlyInstancesOf(Country::class, $list);
    }

    #[Depends('testCreateClient')]
    public function testThatDietAndMedicalWillBeSetWhenGiven(): int
    {
        $emailAddress = 'no-diet@example.com';
        $id = $this->tryClientMethod(function () use ($emailAddress): int {
            return $this->client->createOrUpdateClientWithEmailAddress($emailAddress, [
                'firstname' => 'Diet & Medical',
                'lastname' => 'Test',
                'diet' => 'Potatoes only, please.',
                'medical' => 'Gammy leg.',
            ]);
        }, __FUNCTION__);

        self::assertGreaterThan(1, $id);

        $client = $this->tryClientMethod(function () use ($emailAddress): DarwinClient|null {
            return $this->client->findClientByEmailAddress($emailAddress);
        }, __FUNCTION__);

        self::assertInstanceOf(DarwinClient::class, $client);
        self::assertSame($id, $client->id);
        self::assertSame($emailAddress, $client->emailAddress);
        self::assertSame('Diet & Medical', $client->firstName);
        self::assertSame('Test', $client->lastName);

        // We cannot verify the Diet and Medical data as it is not present in the payload.

        return $id;
    }

    public function testThatAClientMightNotBeFound(): void
    {
        $client = $this->tryClientMethod(function (): DarwinClient|null {
            $emailAddress = sprintf('%s@example.com', uniqid('email-'));

            return $this->client->findClientByEmailAddress($emailAddress);
        }, __FUNCTION__);
        self::serialiseClientExchange($this->client, 'clientNotFound');

        self::assertNull($client);
    }

    #[Depends('testCreateClient')]
    public function testThatAClientCanBeCreatedAndImmediatelyRetrieved(): void
    {
        $emailAddress = 'use-this-email-once@example.com';
        $id = $this->tryClientMethod(function () use ($emailAddress): int {
            return $this->client->createOrUpdateClientWithEmailAddress($emailAddress, [
                'firstname' => 'Unique',
                'lastname' => 'Test',
            ]);
        }, __FUNCTION__);

        self::assertGreaterThan(1, $id);

        $client = $this->tryClientMethod(function () use ($emailAddress): DarwinClient|null {
            return $this->client->findClientByEmailAddress($emailAddress);
        }, __FUNCTION__);

        self::assertInstanceOf(DarwinClient::class, $client);
        self::assertSame($id, $client->id);
        self::assertSame($emailAddress, $client->emailAddress);
        self::assertSame('Unique', $client->firstName);
        self::assertSame('Test', $client->lastName);
    }

    public function testExhaustiveClientPayload(): int
    {
        $id = $this->client->createOrUpdateClientWithEmailAddress('edward@example.com', [
            'title' => 'Mr',
            'alttitle' => 'Mrs', // Not working
            'firstname' => 'Edward',
            'initials' => 'J',
            'lastname' => 'Scissor Hands',
            'maidenname' => 'Woodward',
            'knownas' => 'Muppet Face', // Not working
            'sex' => 'M',
            'housenamenumber' => '1',
            'address1' => 'Scissor Lane',
            'address2' => 'Shaftesbury Avenue',
            'address3' => 'Muppet Corner',
            'town' => 'Goat Town',
            'county' => 'The Shire',
            'postcode' => 'GT3 GTFO',
            'country' => 'Imaginary Country',
            'homephone' => '+4401234567890',
            'officephone' => '+4401234567890', // Not working
            'workphone' => '+4477890456789',
            'mobilephone' => '+4477890456789',
            'secondaryemail' => 'goat-face@example.com',
            'skype' => 'myskypename',
            'facebook' => 'https://www.facebook.com/goat-face',
            'passportcountry' => 'GB',
            'dateofbirth' => '2006-07-04', // Appears to accept an ISO date
            'allowmailing' => 1,
            'allowemail' => 1,
            'overseas' => 0,
            'clientnotes' => <<<'HTML'
                <h2>Client Notes</h2>
                <p>We can <em>probably</em> put arbitrary <code>HTML</code> here.</p>
                <p>We don't know whether the input is sanitised or not, or how Darwin deals with invalid HTML</p>
                HTML,
            'externalref' => 'ER123',
            'preferredcontactmethod' => 2,
            'saleschannel' => 'Some Channel',
            'passportnumber' => '1234567890',
            'nationality' => 'French',
            'diet' => 'Potatoes only please. Maybe some gravel too.',
            'medical' => 'Slightly short on brains.',
            'clientcompanyname' => 'Stainless Scissors Inc.', // Not Working
            'clientposition' => 'CEO', // Not Working
        ]);

        self::assertGreaterThan(1, $id);
        self::serialiseClientExchange($this->client, 'largeClientPayload');

        return $id;
    }

    #[Depends('testCreateClient')]
    public function testThatAnEnquiryCanBeCreatedWithMinimumInformation(): void
    {
        $client = $this->client->findClientByEmailAddress('me@example.com');
        assert($client instanceof ClientModel);

        $enquiryId = $this->client->createEnquiry($client->id, []);

        self::assertGreaterThan(0, $enquiryId);
        self::serialiseClientExchange($this->client, 'minimalEnquiry');
    }

    public function testThatAnErrorDoesNotOccurWhenProvidingInvalidValuesToCreateEnquiry(): void
    {
        $client = $this->client->findClientByEmailAddress('me@example.com');

        $enquiryId = $this->tryClientMethod(function () use ($client): int {
            assert($client instanceof ClientModel);

            return $this->client->createEnquiry($client->id, [
                'unknown' => 'whatever',
                'invalid-key' => 'baz',
            ]);
        }, __FUNCTION__);

        self::assertGreaterThan(0, $enquiryId);
        self::serialiseClientExchange($this->client, 'invalidEnquiry');
    }

    public function testDetailedCreateEnquiryPayloadForManualInspection(): void
    {
        $client = $this->client->findClientByEmailAddress('me@example.com');

        $enquiryId = $this->tryClientMethod(function () use ($client): int {
            assert($client instanceof ClientModel);

            return $this->client->createEnquiry($client->id, [
                'description' => 'An example description',
                'notes' => '<h1>Note can be arbitrary Markup</h1><p><em>Ooohh!</em></p>',
                'dateoftravel' => 'Next week',
                //'countryofinterest1' => 'We have no hope of knowing what this value could be',
                //'countryofinterest2' => 'Country 2',
                //'countryofinterest3' => 'Country 3',
                //'countryofinterest4' => 'Country 4',
                //'countryofinterest5' => 'Country 5',
                //'othercountry' => 'And yet another country',
                'nights' => 4265923,
                'adults' => 496,
                'children' => 99,
                'numberofsinglepax' => 123,
                'childages' => 'Too many to list',
                'rooms' => 465,
                'roomtype' => 'Room type? Who knows?',
                'channel' => 'Some Channel',
                'createdby' => 348,
                'originatingsource' => 0,
                'triptype' => 'Some type of trip',
                'weblevelofinterest' => 'Interest much',
                'region' => 'Region Name',
                'duration' => '12 months',
                'brochurecode' => 'Some brochure code?',
                'assigneeoverride' => 333,
                'bookingstartdate' => '2023-12-25',
                'isbooking' => 0,
                'agentid' => null,
                'pax' => [
                    [
                        'firstname' => 'Theresa',
                        'lastname' => 'May',
                        'passportcountry' => 'Rwanda',
                        'dateofbirth' => '1945-01-01',
                        'passportnumber' => '01234567890',
                        'nationality' => 'Scum',
                    ],
                    [
                        'firstname' => 'Boris',
                        'lastname' => 'Jhonson',
                        'passportcountry' => 'Rwanda',
                        'dateofbirth' => '1969-01-01',
                        'passportnumber' => '01234567890',
                        'nationality' => 'Scum',
                    ],
                ],
                'emergencycontacts' => [
                    [
                        'emergencycontactname' => 'Mupet Face',
                        'emergencycontactphone' => 'Mupet Face',
                        'emergencycontactemail' => 'Mupet Face',
                        'emergencycontactrelationship' => 'Mupet Face',
                    ],
                ],
            ]);
        }, __FUNCTION__);

        self::assertGreaterThan(0, $enquiryId);
        self::serialiseClientExchange($this->client, 'invalidEnquiry');
    }
}
