<?php

declare(strict_types=1);

namespace Darwin\Test\Unit;

use Darwin\HttpClient;
use Darwin\Models\Client;
use Darwin\Models\Country;
use Darwin\RequestFailed;
use Darwin\UnexpectedAPIPayload;
use DateTimeZone;
use Http\Mock\Client as MockClient;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\Response\Serializer;
use Laminas\Diactoros\StreamFactory;
use Lcobucci\Clock\SystemClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function count;
use function file_get_contents;
use function json_decode;

class HttpClientTest extends TestCase
{
    private MockClient $http;
    private HttpClient $client;

    protected function setUp(): void
    {
        $this->http = new MockClient();
        $streams      = new StreamFactory();
        $this->client = new HttpClient(
            'https://example.com',
            '/api',
            'secret',
            99,
            new SystemClock(new DateTimeZone('UTC')),
            $this->http,
            new RequestFactory(),
            $streams,
        );
    }

    private function fixResponse(string $fileName): ResponseInterface
    {
        self::assertFileExists($fileName);
        $response = Serializer::fromString(file_get_contents($fileName));
        $this->http->addResponse(
            $response,
        );

        return $response;
    }

    public function testThatTheAuthPayloadHasTheExpectedKeys(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/validCountryListResponse.http');
        $this->client->listCountries();
        $request = $this->http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('auth', $body);
        self::assertIsArray($body['auth']);
        self::assertSame(99, $body['auth']['companyid'] ?? null);
        self::assertIsInt($body['auth']['timestamp'] ?? null);
        self::assertIsString($body['auth']['APIMethod'] ?? null);
        self::assertIsString($body['auth']['hash_hmac'] ?? null);
    }

    public function testThatTheLastRequestAndResponseThoseReceivedByTheClient(): void
    {
        $expectResponse = $this->fixResponse(__DIR__ . '/fixtures/validCountryListResponse.http');
        $this->client->listCountries();
        $request = $this->http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($expectResponse, $this->client->lastResponse());
        self::assertSame($request, $this->client->lastRequest());
    }

    public function testListCountriesWillFailWhenTheResponseDoesNotHaveAListOfCountries(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/genericSuccessResponse.http');
        $this->expectException(UnexpectedAPIPayload::class);
        $this->expectExceptionMessage('The country list is not an array');

        $this->client->listCountries();
    }

    public function testListCountriesWillFailWhenTheListContainsInvalidData(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/countryListContainsNonArrayMembers.http');
        $this->expectException(UnexpectedAPIPayload::class);
        $this->expectExceptionMessage('The list of countries contained a non-array member');

        $this->client->listCountries();
    }

    public function testValidCountryList(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/validCountryListResponse.http');
        $countries = $this->client->listCountries();
        self::assertContainsOnlyInstancesOf(Country::class, $countries);
        self::assertGreaterThan(1, count($countries));
        foreach ($countries as $country) {
            self::assertNotSame('', $country->name);
            self::assertNotSame(0, $country->identifier);
        }
    }

    public function testClientNotFound(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/clientNotFound.http');
        $result = $this->client->findClientByEmailAddress('me@example.com');
        self::assertNull($result);
    }

    public function testInvalidPayloadForFindClientByEmailAddress(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/validCountryListResponse.http');
        $this->expectException(UnexpectedAPIPayload::class);
        $this->expectExceptionMessage('Expected an array representing the client information');
        $this->client->findClientByEmailAddress('me@example.com');
    }

    public function testNonJsonResponseIsExceptional(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/nonJsonResponse.http');
        $this->expectException(RequestFailed::class);
        $this->expectExceptionMessage('returned an invalid response body that could not be decoded');
        $this->client->findClientByEmailAddress('me@example.com');
    }

    public function testThatFindClientByEmailAddressWillYieldAClientObject(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/findClientByEmailAddressResponse.http');
        $client = $this->client->findClientByEmailAddress('me@example.com');
        self::assertInstanceOf(Client::class, $client);
        self::assertSame(478564, $client->id);
        self::assertSame('no-diet@example.com', $client->emailAddress);
        self::assertSame('Diet & Medical', $client->firstName);
        self::assertSame('Test', $client->lastName);
    }

    public function testThatAnEmptyResponseIsExceptional(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/emptyResponse.http');
        $this->expectException(UnexpectedAPIPayload::class);
        $this->expectExceptionMessage('The response body was empty');
        $this->client->findClientByEmailAddress('me@example.com');
    }

    public function testThat404ResponsesAreConvertedToAnException(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/remote404Response.http');
        $this->expectException(RequestFailed::class);
        $this->expectExceptionMessage('resulted in a 404 error');
        $this->expectExceptionCode(404);
        $this->client->listCountries();
    }

    public function testThatCreateClientValidatesRemotePayload(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/genericSuccessResponse.http');
        $this->expectException(UnexpectedAPIPayload::class);
        $this->expectExceptionMessage('The `createClient` response payload should contain a client identifier');
        $this->client->createOrUpdateClientWithEmailAddress('foo@example.com', []);
    }

    public function testCreateClientWithExistingClient(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/clientUpsertWithExistingClient.http');
        $id = $this->client->createOrUpdateClientWithEmailAddress('foo@example.com', []);
        self::assertSame(478567, $id);
    }

    public function test500ResponseWithCreateEnquiry(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/remoteErrorWithCreateEnquiry.http');
        $this->expectException(RequestFailed::class);
        $this->expectExceptionMessage('failed with message "Error encountered saving tripenquiry record"');
        $this->client->createEnquiry(123456, []);
    }

    public function testCreateEnquiryIsExceptionalForAnUnexpectedResponseShape(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/genericSuccessResponse.http');
        $this->expectException(UnexpectedAPIPayload::class);
        $this->expectExceptionMessage('The `createEnquiry` response payload should contain a trip identifier');
        $this->client->createEnquiry(123456, []);
    }

    public function testCreateEnquiryCanBeSuccessful(): void
    {
        $this->fixResponse(__DIR__ . '/fixtures/createEnquirySuccess.http');
        $enquiryId = $this->client->createEnquiry(123456, []);

        self::assertSame(298819, $enquiryId);
    }
}
