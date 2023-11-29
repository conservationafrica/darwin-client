# Darwin HTTP Client

This is an HTTP client to interact with the Darwin "REST API". Darwin is a tour operator SAAS product developed by https://eecsoftware.com

There are some rudimentary docs available at: https://darwin11.docs.apiary.io/

Things to watch out for:

- Every request will return a "200 OK" response, except for a 404 - this is probably because each API endpoint is a PHP file. Anyway, you have to inspect the response payload to see if the request was successful or not. This client takes care of inspecting the payload and converts request failures to exceptions with descriptive error messages.
- All requests must provide a body. The Auth Payload is sent as part of the request body.
- It appears as though only POST requests are accepted, so if a method is documented as a GET request, it probably isn't.
- Field names are case-sensitive. This tripped me up initially because fields were documented with incorrect casing.
- The docs are frequently incorrect or incomplete.
- Most of the response payloads are `array<string, string>` - `null` array members are generally empty strings, `int` array members and generally numeric strings etc, so a lot of type coercions are required. This is not a hard and fast rule, generally typing in response payloads is inconsistent.
- The remote app stores unknown dates as int zero, which means that an unknown date is returned in payloads as 1970-01-01. This effectively means that specific date is equal to "unknown" and you have to hope that none of your clients were actually born on that day.

## Requirements & Installation

Because this lib makes use of [azjezz/psl](https://github.com/azjezz/psl), the `bcmath` extension is required. [composer.json](./composer.json) lists all you need to know about dependency requirements.

Installation via composer is the only supported installation method:

```bash
composer require conservationafrica/darwin-client
```

## Usage

Generally speaking, you should type hint on the `\Darwin\Client` interface so that you can stub out the client in tests.

Type inference is pretty good, not 100% but near enough. You should use Psalm or PHPStan. It will help you!

All exceptions implement a common marker interface `\Darwin\DarwinError`. Wrap all calls to the client with something like:

```php
use Darwin\Client;
use Darwin\DarwinError;

assert($client instanceof Client);

try {
    $customer = $client->findClientByEmailAddress('me@example.com');
} catch (DarwinError) {
    // Handle exception appropriately
}

printf('Hi %s 👋', $customer->firstName);
```

### Concrete Client Construction

You'll need to construct the [concrete client](./src/HttpClient.php) with your DIC, there are several constructor dependencies:

```php
<?php

declare(strict_types=1);

use Darwin\HttpClient;
use Http\Client\Curl\Client as CurlClient;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use Lcobucci\Clock\SystemClock;

$client = new HttpClient(
    'https://your-subdomain.eecsoftware.com',
    '/AJAX/', // <- Whilst this is probably the same for all installations, you still need to specify it.
    'Some shared secret', // <- This is provided by the vendor
    99, // <- The company Identifier, supplied by the vendor
    new SystemClock(new DateTimeZone('UTC')), // Some Psr\Clock\ClockInterface implementation
    new CurlClient(), // <- Any Psr 18 compliant HTTP client
    new RequestFactory(), // <- Any Psr 17 compliant request factory
    new StreamFactory(), // <- Any Psr 17 compliant stream factory
);
```

## Running Tests

There are 2 test suites, `Integration` and `Unit`

To run the integration tests on a remote server, you'll need to declare the environment variables:

- `API_URL` - This is scheme and host only, i.e. "https://example.com" - the default base path of `/AJAX/` is hard-coded in the test suite.
- `API_SECRET` - This is the shared secret used to generate `hash_hmac` signatures required by Darwin.
- `COMPANY_ID` - The integer company ID provided by Darwin.

## Remote API Method Details and Caveats

### `getClient` method

When there are multiple "clients" that use the same email address recorded in Darwin, this method returns the most recent client amongst many. The definition of "most recent" here is effectively insertion date descending, or auto incrementing id descending.

Conversely, if you call `createClient`, the data will be applied to the oldest matching client, i.e. insertion date ascending.

### `createClient` method _(This is an upsert)_

As noted, this method is an upsert, however, **any omitted fields will erase existing data so do not consider this method a patch**. For example, sending `{"firstname": "foo", "lastname": "bar"}` in the first request, followed by `{"firstname": "new"}` in the second request will yield a client record with `lastname` set to an empty string.

- `dateofbirth` will thankfully interpret an ISO date such as `2001-07-04` as 4th July 2001.
- `country` Can only be an arbitrary string - the pick-list available in the UI has no corresponding api method and effectively sets the value to a fuzzy string, providing an ISO country code here just means that the UI will present that country code back to the user.

#### Preferred Contact Method

Docs state that the value must be "Email" or "Telephone", but you can provide an integer to the `preferredcontactmethod` field that matches one of the following:

- 1 = Primary Email
- 2 = Secondary Email
- 3 = Home Phone
- 4 = Work Phone
- 5 = Mobile Phone
- 6 = Skype
- 7 = Facebook
- 8 = Post

### `getCountryList` method

This endpoint is useless for identifying countries relevant to customers. It will only return a handful of countries - those that are defined by the customer.

### `createEnquiry` method

Generally speaking, you can send any old junk with this method, providing you observe the spec outlined on the `ClientInterface::EnquiryPayload` psalm type.

The 6 'Country of Interest' fields must correspond to a country identifier which is not particularly helpful, _(An ISO country code might have been a more sensible choice here)_. Not providing a valid identifier is guaranteed to resolve to a 500 error.
