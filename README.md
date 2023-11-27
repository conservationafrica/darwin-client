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

## Running Tests

There are 2 test suites, `Integration` and `Unit`

To run the integration tests on a remote server, you'll need to declare the environment variables:

- `API_URL` - This is scheme and host only, i.e. "https://example.com" - the default base path of `/AJAX/` is hard-coded in the test suite.
- `API_SECRET` - This is the shared secret used to generate `hash_hmac` signatures required by Darwin.
- `COMPANY_ID` - The integer company ID provided by Darwin.

## Method Details and Caveats

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
