<?php

declare(strict_types=1);

namespace Darwin;

use Darwin\Models\Client as ClientModel;
use Darwin\Models\Country;
use Psl\Type as T;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use Webmozart\Assert\Assert;

use function get_debug_type;
use function hash_hmac;
use function hash_hmac_algos;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * @psalm-type AuthPayload = array{
 *     companyid: int,
 *     timestamp: int,
 *     APIMethod: non-empty-string,
 *     hash_hmac: non-empty-string,
 * }
 */
final class HttpClient implements Client
{
    private RequestInterface|null $lastRequest   = null;
    private ResponseInterface|null $lastResponse = null;

    public function __construct(
        private readonly string $serverUrl,
        private readonly string $basePath,
        #[SensitiveParameter]
        private readonly string $sharedSecret,
        private readonly int $companyId,
        private readonly ClockInterface $clock,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /** @return list<Country> */
    public function listCountries(): array
    {
        $request = $this->createRequest(
            'POST',
            'getCountryList',
            'search',
            [],
        );

        $payload = $this->sendRequest($request);

        $list = $payload['CountryList'] ?? null;
        if (! is_array($list)) {
            throw new UnexpectedAPIPayload(
                $request,
                T\instance_of(ResponseInterface::class)->assert($this->lastResponse),
                'The country list is not an array',
            );
        }

        $type = T\shape([
            'id' => T\positive_int(),
            // Non-empty-string will be unwise here. It is very likely that there are many countries with empty names.
            'countryname' => T\string(),
        ], true);

        $data = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                throw new UnexpectedAPIPayload(
                    $request,
                    T\instance_of(ResponseInterface::class)->assert($this->lastResponse),
                    'The list of countries contained a non-array member',
                );
            }

            $value = $type->coerce($item);

            if ($value['countryname'] === '') {
                continue;
            }

            $data[] = new Country($value['id'], $value['countryname']);
        }

        return $data;
    }

    public function findClientByEmailAddress(string $emailAddress): ClientModel|null
    {
        $request = $this->createRequest(
            'POST',
            'getClient',
            'search',
            ['email' => $emailAddress],
        );
        try {
            $payload = $this->sendRequest($request);
        } catch (RequestFailed $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        $clientArray = $payload['Client'] ?? null;
        if (! is_array($clientArray)) {
            throw new UnexpectedAPIPayload(
                $request,
                T\instance_of(ResponseInterface::class)->assert($this->lastResponse),
                sprintf(
                    'Expected an array representing the client information but received "%s"',
                    get_debug_type($clientArray),
                ),
            );
        }

        return $this->hydrateClient($clientArray);
    }

    /** @inheritDoc */
    public function createOrUpdateClientWithEmailAddress(string $emailAddress, array $clientData): int
    {
        $clientData['email'] = $emailAddress;

        $request = $this->createRequest(
            'POST',
            'createClient',
            'client',
            $clientData,
        );

        $payload = $this->sendRequest($request);
        if (! isset($payload['clientid']) || ! is_numeric($payload['clientid'])) {
            throw new UnexpectedAPIPayload(
                $request,
                T\instance_of(ResponseInterface::class)->assert($this->lastResponse),
                'The `createClient` response payload should contain a client identifier in the field '
                . '`clientid` but none was found',
            );
        }

        return (int) $payload['clientid'];
    }

    /** @inheritDoc */
    public function createEnquiry(int $clientId, array $payload): int
    {
        $payload['clientid'] = $clientId;
        $request = $this->createRequest(
            'POST',
            'createTripEnquiry',
            'tripenquiry',
            $payload,
        );

        $payload = $this->sendRequest($request);
        if (! isset($payload['tripid']) || ! is_numeric($payload['tripid'])) {
            throw new UnexpectedAPIPayload(
                $request,
                T\instance_of(ResponseInterface::class)->assert($this->lastResponse),
                'The `createEnquiry` response payload should contain a trip identifier in the field '
                . '`tripid` but none was found',
            );
        }

        return (int) $payload['tripid'];
    }

    /** @return array<string, mixed> */
    private function sendRequest(RequestInterface $request): array
    {
        $this->lastRequest = $request;
        try {
            $response           = $this->httpClient->sendRequest($request);
            $this->lastResponse = $response;
        } catch (Throwable $e) {
            throw RequestFailed::becauseOfAnIoError($request, $e);
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            throw new UnexpectedAPIPayload($request, $response, 'The response body was empty');
        }

        if ($response->getStatusCode() === 404) {
            throw RequestFailed::becauseItWasA404($request, $response);
        }

        // This "API" returns JSON strings exclusively
        try {
            /** @psalm-suppress MixedAssignment */
            $body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            Assert::isArray($body);
        } catch (Throwable $e) {
            throw RequestFailed::becauseTheResponseBodyWasNotValidJson($request, $response, $e);
        }

        /** @psalm-var mixed $result */
        $result = $body['Result'] ?? null;
        /** @psalm-var mixed $code */
        $code = $body['Code'] ?? null;
        /** @psalm-var mixed $message */
        $message = $body['Msg'] ?? null;

        if ((int) $code !== 200 || $result !== 'Success') {
            throw RequestFailed::withErrorResult($request, $response, (string) $message, (int) $code);
        }

        Assert::isMap($body);

        return $body;
    }

    /**
     * @param non-empty-string          $httpMethod
     * @param non-empty-string          $methodName
     * @param non-empty-string|null     $payloadKey
     * @param array<string, mixed>|null $payload
     */
    private function createRequest(
        string $httpMethod,
        string $methodName,
        string|null $payloadKey,
        array|null $payload,
    ): RequestInterface {
        $uri = sprintf(
            '%s/%s/%s.php',
            trim($this->serverUrl, '/'),
            trim($this->basePath, '/'),
            $methodName,
        );

        $body = json_encode($this->prepareBodyPayload(
            $methodName,
            $payloadKey,
            $payload,
        ), JSON_THROW_ON_ERROR);

        return $this->requestFactory->createRequest($httpMethod, $uri)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream(
                $body,
            ));
    }

    /**
     * @param non-empty-string          $methodName
     * @param non-empty-string|null     $payloadKey
     * @param array<string, mixed>|null $payload
     *
     * @return array{auth: AuthPayload}&array<string, array>
     */
    private function prepareBodyPayload(string $methodName, string|null $payloadKey, array|null $payload): array
    {
        $value = [
            'auth' => $this->authPayload($methodName),
        ];
        if (is_string($payloadKey) && is_array($payload)) {
            $value[$payloadKey] = $payload;
        }

        return $value;
    }

    /**
     * @param non-empty-string $methodName
     *
     * @return AuthPayload
     */
    private function authPayload(string $methodName): array
    {
        if (! in_array('sha256', hash_hmac_algos())) {
            throw new RuntimeException('SHA256 is not a supported hash algo on this machine');
        }

        // Timestamp is Unix time with ms
        $timestamp = $this->clock->now()->getTimestamp() * 1000;

        return [
            'companyid' => $this->companyId,
            'timestamp' => $timestamp,
            'APIMethod' => $methodName,
            'hash_hmac' => hash_hmac('sha256', sprintf(
                '%d!%s!%d',
                $this->companyId,
                $methodName,
                $timestamp,
            ), $this->sharedSecret, false),
        ];
    }

    /** @param array<array-key, mixed> $data */
    private function hydrateClient(array $data): ClientModel
    {
        $id = $data['clientid'] ?? null;
        $email = $data['email'] ?? null;
        $first = $data['firstname'] ?? null;
        $last = $data['lastname'] ?? null;
        Assert::numeric($id);
        Assert::nullOrStringNotEmpty($email);
        Assert::nullOrString($first);
        Assert::nullOrString($last);

        return new ClientModel(
            (int) $id,
            (string) $email,
            (string) $first,
            (string) $last,
        );
    }

    public function lastRequest(): RequestInterface|null
    {
        return $this->lastRequest;
    }

    public function lastResponse(): ResponseInterface|null
    {
        return $this->lastResponse;
    }
}
