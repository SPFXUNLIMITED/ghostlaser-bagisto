<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncGhostLaserCustomers extends Command
{
    protected $signature = 'ghostlaser:sync-customers
        {--backfill : After the sync loop, create a default address for already existing customers that have none}
        {--backfill-phones : After the sync loop, normalize the stored phone of already existing customers}';

    protected $description = 'Sync customers from the external Ghost Laser booking database into Bagisto via the Admin REST API.';

    public function handle(): int
    {
        $apiKey = trim((string) config('ghostlaser.api_key'));
        if ($apiKey === '') {
            $this->error('BAGISTO_API_KEY is not configured.');

            return self::FAILURE;
        }

        $adminToken = trim((string) config('ghostlaser.admin_token'));
        if ($adminToken === '') {
            $this->error('BAGISTO_ADMIN_TOKEN is not configured.');

            return self::FAILURE;
        }

        $baseUrl = rtrim(trim((string) config('ghostlaser.bagisto_url')), '/');
        if ($baseUrl === '') {
            $this->error('BAGISTO_URL (or APP_URL) is not configured.');

            return self::FAILURE;
        }

        $sourceUrl = trim((string) config('ghostlaser.source_url'));
        if ($sourceUrl === '') {
            $this->error('GHOSTLASER_CUSTOMERS_URL is not configured.');

            return self::FAILURE;
        }

        $customerGroupId = (int) config('ghostlaser.customer_group_id', 1);
        if ($customerGroupId < 1) {
            $customerGroupId = 1;
        }

        $backfill = (bool) $this->option('backfill');
        $backfillPhones = (bool) $this->option('backfill-phones');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $addressesCreated = 0;
        $addressesSkipped = 0;
        $addressErrors = 0;
        $phonesUpdated = 0;
        $phonesSkipped = 0;
        $phoneErrors = 0;

        /** @var array<int, array{id: int, email: string, customer: array<string, mixed>}> $backfillQueue */
        $backfillQueue = [];

        /** @var array<int, array{id: int, email: string}> $phoneBackfillQueue */
        $phoneBackfillQueue = [];

        $page = 1;
        $perPage = 100;
        $total = PHP_INT_MAX;

        while ($page * $perPage <= $total || $page === 1) {
            try {
                $url = $sourceUrl . '?'
                    . http_build_query([
                        'page' => $page,
                        'per_page' => 100,
                    ]);

                [$responseBody, $statusCode] = $this->request($url, 'GET', [
                    'X-Bagisto-Key' => $apiKey,
                    'Accept' => 'application/json',
                ]);

                if ($statusCode < 200 || $statusCode >= 300) {
                    throw new RuntimeException("Customer source returned HTTP {$statusCode}.");
                }

                $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($response) || !isset($response['data']) || !is_array($response['data'])) {
                    throw new RuntimeException('Customer source returned an invalid data response.');
                }

                $perPage = max(1, (int) ($response['per_page'] ?? 100));
                $total = max(0, (int) ($response['total'] ?? count($response['data'])));

                foreach ($response['data'] as $customer) {
                    $email = is_array($customer) ? (string) ($customer['email'] ?? '') : '';

                    try {
                        if (!is_array($customer)) {
                            throw new RuntimeException('Customer record is not a valid object.');
                        }

                        $firstName = trim((string) ($customer['first_name'] ?? ''));
                        $lastName = trim((string) ($customer['last_name'] ?? ''));
                        $phone = $this->normalizePhone((string) ($customer['phone'] ?? ''));

                        if ($firstName === '' || $lastName === '' || $phone === '') {
                            $skipped++;
                            Log::warning('Skipped broken record', [
                                'email' => trim((string) ($customer['email'] ?? '')),
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'phone' => $phone,
                            ]);
                            $this->warn('Skipped broken record: ' . trim((string) ($customer['email'] ?? '')));

                            continue;
                        }

                        $payload = [
                            'first_name' => $customer['first_name'] ?? '',
                            'last_name' => $customer['last_name'] ?? '',
                            'email' => $customer['email'] ?? '',
                            'gender' => 'Other',
                            'customer_group_id' => $customerGroupId,
                        ];

                        if ($phone !== '') {
                            $payload['phone'] = $phone;
                        }

                        $email = trim((string) $payload['email']);
                        if ($email === '') {
                            throw new RuntimeException('Customer record has no email address.');
                        }

                        $payload['email'] = $email;

                        $headers = [
                            'Authorization' => 'Bearer ' . $adminToken,
                            'Accept' => 'application/json',
                        ];

                        if (($existingId = $this->findCustomerId($baseUrl, $headers, $email)) !== null) {
                            $skipped++;

                            if ($backfill) {
                                if ($existingId > 0) {
                                    $backfillQueue[] = [
                                        'id' => $existingId,
                                        'email' => $email,
                                        'customer' => $customer,
                                    ];
                                } else {
                                    $addressesSkipped++;
                                    Log::warning('Bagisto customer lookup returned no id; address backfill skipped.', [
                                        'email' => $email,
                                    ]);
                                }
                            }

                            if ($backfillPhones) {
                                if ($existingId > 0) {
                                    $phoneBackfillQueue[] = [
                                        'id' => $existingId,
                                        'email' => $email,
                                    ];
                                } else {
                                    $phonesSkipped++;
                                    Log::warning('Bagisto customer lookup returned no id; phone backfill skipped.', [
                                        'email' => $email,
                                    ]);
                                }
                            }

                            continue;
                        }

                        [$createdBody, $createdStatus] = $this->request(
                            $baseUrl . '/api/admin/customers',
                            'POST',
                            $headers + ['Content-Type' => 'application/json'],
                            json_encode($payload, JSON_THROW_ON_ERROR)
                        );

                        if (
                            isset($payload['phone'])
                            && $this->isDuplicatePhoneResponse($createdStatus, $createdBody)
                        ) {
                            Log::warning('Bagisto customer phone already in use; retrying without phone.', [
                                'email' => $email,
                                'phone' => $payload['phone'],
                            ]);
                            $this->warn($email . ': phone already used by another customer, creating without phone.');

                            unset($payload['phone']);

                            [$createdBody, $createdStatus] = $this->request(
                                $baseUrl . '/api/admin/customers',
                                'POST',
                                $headers + ['Content-Type' => 'application/json'],
                                json_encode($payload, JSON_THROW_ON_ERROR)
                            );
                        }

                        if ($createdStatus < 200 || $createdStatus >= 300) {
                            if ($this->isDuplicateEmailResponse($createdStatus, $createdBody)) {
                                $skipped++;

                                continue;
                            }

                            Log::error('Bagisto customer create failed', [
                                'email' => $email,
                                'status' => $createdStatus,
                                'body' => $createdBody,
                            ]);

                            throw new RuntimeException(
                                "Customer creation returned HTTP {$createdStatus}: " . $this->truncate($createdBody)
                            );
                        }

                        $created++;
                        Log::info('Ghost Laser customer created in Bagisto.', ['email' => $email]);

                        $customerId = $this->extractCustomerId($createdBody);
                        if ($customerId === null) {
                            $addressesSkipped++;
                            Log::warning('Bagisto customer create response had no customer id; address skipped.', [
                                'email' => $email,
                                'body' => $this->truncate($createdBody),
                            ]);

                            continue;
                        }

                        $result = $this->createDefaultAddress($baseUrl, $headers, $customerId, $email, $customer);
                        if ($result === true) {
                            $addressesCreated++;
                        } elseif ($result === false) {
                            $addressErrors++;
                        } else {
                            $addressesSkipped++;
                        }
                    } catch (Throwable $exception) {
                        $errors++;
                        $this->warn($email . ': ' . $exception->getMessage());
                    }
                }

                $responsePage = max(1, (int) ($response['page'] ?? $page));
                if ($responsePage * $perPage >= $total) {
                    break;
                }

                $page++;
            } catch (Throwable $exception) {
                $this->error('Failed to fetch Ghost Laser customers: ' . $exception->getMessage());

                return self::FAILURE;
            }
        }

        if ($backfill && $backfillQueue !== []) {
            $adminHeaders = [
                'Authorization' => 'Bearer ' . $adminToken,
                'Accept' => 'application/json',
            ];

            foreach ($backfillQueue as $entry) {
                try {
                    if ($this->hasAddresses($baseUrl, $adminHeaders, $entry['id'], $entry['email'])) {
                        $addressesSkipped++;

                        continue;
                    }

                    $result = $this->createDefaultAddress(
                        $baseUrl,
                        $adminHeaders,
                        $entry['id'],
                        $entry['email'],
                        $entry['customer']
                    );

                    if ($result === true) {
                        $addressesCreated++;
                    } elseif ($result === false) {
                        $addressErrors++;
                    } else {
                        $addressesSkipped++;
                    }
                } catch (Throwable $exception) {
                    $addressErrors++;
                    Log::error('Bagisto address backfill failed', [
                        'email' => $entry['email'],
                        'customer_id' => $entry['id'],
                        'message' => $exception->getMessage(),
                    ]);
                    $this->warn($entry['email'] . ': address backfill failed: ' . $exception->getMessage());
                }
            }
        }

        if ($backfillPhones && $phoneBackfillQueue !== []) {
            $adminHeaders = [
                'Authorization' => 'Bearer ' . $adminToken,
                'Accept' => 'application/json',
            ];

            foreach ($phoneBackfillQueue as $entry) {
                try {
                    $result = $this->backfillCustomerPhone(
                        $baseUrl,
                        $adminHeaders,
                        $entry['id'],
                        $entry['email']
                    );

                    if ($result === true) {
                        $phonesUpdated++;
                    } elseif ($result === false) {
                        $phoneErrors++;
                    } else {
                        $phonesSkipped++;
                    }
                } catch (Throwable $exception) {
                    $phoneErrors++;
                    Log::error('Bagisto phone backfill failed', [
                        'email' => $entry['email'],
                        'customer_id' => $entry['id'],
                        'message' => $exception->getMessage(),
                    ]);
                    $this->warn($entry['email'] . ': phone backfill failed: ' . $exception->getMessage());
                }
            }
        }

        $this->line(sprintf(
            'Sync complete: created %d, updated %d, skipped %d, errors %d.',
            $created,
            $updated,
            $skipped,
            $errors
        ));

        $this->line(sprintf(
            'Addresses: created %d, skipped %d, errors %d.',
            $addressesCreated,
            $addressesSkipped,
            $addressErrors
        ));

        if ($backfillPhones) {
            $this->line(sprintf(
                'Phones: updated %d, skipped %d, errors %d.',
                $phonesUpdated,
                $phonesSkipped,
                $phoneErrors
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Strip formatting characters from a phone number.
     */
    private function normalizePhone(string $phone): string
    {
        return trim(preg_replace('/[-\s()]/', '', $phone) ?? '');
    }

    /**
     * Normalize the phone already stored on a Bagisto customer.
     *
     * Returns true when the customer was updated, false when the API call
     * failed, and null when there is nothing to change.
     */
    private function backfillCustomerPhone(
        string $baseUrl,
        array $headers,
        int $customerId,
        string $email
    ): ?bool {
        [$body, $status] = $this->request(
            $baseUrl . '/api/admin/customers/' . $customerId,
            'GET',
            $headers
        );

        if ($status < 200 || $status >= 300) {
            Log::warning('Bagisto customer fetch failed during phone backfill', [
                'email' => $email,
                'customer_id' => $customerId,
                'status' => $status,
                'body' => $this->truncate($body),
            ]);

            throw new RuntimeException("Customer fetch returned HTTP {$status}.");
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('Customer fetch returned an invalid data response.');
        }

        $customer = $response['data'] ?? $response;
        if (!is_array($customer)) {
            throw new RuntimeException('Customer fetch returned an invalid data response.');
        }

        $currentPhone = (string) data_get($customer, 'phone', '');
        $normalized = $this->normalizePhone($currentPhone);

        if ($normalized === '' || $normalized === $currentPhone) {
            return null;
        }

        $payload = [
            'first_name' => (string) data_get($customer, 'first_name', ''),
            'last_name' => (string) data_get($customer, 'last_name', ''),
            'email' => (string) data_get($customer, 'email', $email),
            'phone' => $normalized,
        ];

        $gender = (string) data_get($customer, 'gender', '');
        if ($gender !== '') {
            $payload['gender'] = $gender;
        }

        $customerGroupId = data_get($customer, 'customer_group_id', data_get($customer, 'group.id'));
        if (is_numeric($customerGroupId)) {
            $payload['customer_group_id'] = (int) $customerGroupId;
        }

        [$updateBody, $updateStatus] = $this->request(
            $baseUrl . '/api/admin/customers/' . $customerId,
            'PUT',
            $headers + ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        if ($updateStatus < 200 || $updateStatus >= 300) {
            Log::error('Bagisto customer phone update failed', [
                'email' => $email,
                'customer_id' => $customerId,
                'status' => $updateStatus,
                'body' => $this->truncate($updateBody),
            ]);
            $this->warn(sprintf(
                '%s: phone update returned HTTP %d (%s).',
                $email,
                $updateStatus,
                $this->truncate($updateBody)
            ));

            return false;
        }

        Log::info('Ghost Laser customer phone normalized in Bagisto.', [
            'email' => $email,
            'customer_id' => $customerId,
            'phone' => $normalized,
        ]);

        return true;
    }

    /**
     * Create the default address for a Bagisto customer from the source record.
     *
     * Returns true when the address was created, false when the API call
     * failed, and null when the source record has no usable address.
     */
    private function createDefaultAddress(
        string $baseUrl,
        array $headers,
        int $customerId,
        string $email,
        array $customer
    ): ?bool {
        $address = trim((string) ($customer['address'] ?? ''));

        if ($address === '') {
            Log::info('Skipped Bagisto address: source record has no address.', [
                'email' => $email,
                'customer_id' => $customerId,
            ]);
            $this->line($email . ': no source address, address call skipped.');

            return null;
        }

        $country = trim((string) ($customer['country'] ?? ''));
        if ($country === '') {
            $country = trim((string) config('ghostlaser.default_country', 'US'));
        }

        $fields = [
            'first_name' => trim((string) ($customer['first_name'] ?? '')),
            'last_name' => trim((string) ($customer['last_name'] ?? '')),
            'city' => trim((string) ($customer['city'] ?? '')),
            'state' => trim((string) ($customer['state'] ?? '')),
            'postcode' => trim((string) ($customer['zip'] ?? '')),
            'country' => $country,
            'phone' => $this->normalizePhone((string) ($customer['phone'] ?? '')),
        ];

        // Bagisto validates all of these as required, so an incomplete source
        // record can only produce an HTTP 422. Skip it instead of calling.
        $missing = array_keys(array_filter($fields, static fn ($value): bool => $value === ''));
        if ($missing !== []) {
            Log::info('Skipped Bagisto address: source record is incomplete.', [
                'email' => $email,
                'customer_id' => $customerId,
                'missing' => $missing,
            ]);
            $this->line(sprintf(
                '%s: incomplete source address (missing %s), address call skipped.',
                $email,
                implode(', ', $missing)
            ));

            return null;
        }

        $payload = $fields + [
            'customer_id' => $customerId,
            'address_type' => 'customer',
            'address' => $address,
            'default_address' => true,
            'use_for_shipping' => true,
            'email' => trim((string) ($customer['email'] ?? '')) ?: $email,
        ];

        $company = trim((string) ($customer['company'] ?? ''));
        if ($company !== '') {
            $payload['company_name'] = $company;
        }

        [$body, $status] = $this->request(
            $baseUrl . '/api/admin/customers/' . $customerId . '/addresses',
            'POST',
            $headers + ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        if ($status < 200 || $status >= 300) {
            Log::error('Bagisto customer address create failed', [
                'email' => $email,
                'customer_id' => $customerId,
                'status' => $status,
                'body' => $this->truncate($body),
            ]);
            $this->warn(sprintf(
                '%s: address create returned HTTP %d (%s).',
                $email,
                $status,
                $this->truncate($body)
            ));

            return false;
        }

        Log::info('Ghost Laser customer address created in Bagisto.', [
            'email' => $email,
            'customer_id' => $customerId,
        ]);

        return true;
    }

    /**
     * Check whether a Bagisto customer already has at least one address.
     */
    private function hasAddresses(string $baseUrl, array $headers, int $customerId, string $email): bool
    {
        [$body, $status] = $this->request(
            $baseUrl . '/api/admin/customers/' . $customerId . '/addresses',
            'GET',
            $headers
        );

        if ($status === 404) {
            return false;
        }

        if ($status < 200 || $status >= 300) {
            Log::warning('Bagisto customer address lookup failed', [
                'email' => $email,
                'customer_id' => $customerId,
                'status' => $status,
                'body' => $this->truncate($body),
            ]);

            throw new RuntimeException("Address lookup returned HTTP {$status}.");
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('Address lookup returned an invalid data response.');
        }

        $addresses = $response['data'] ?? $response;

        return is_array($addresses) && $addresses !== [];
    }

    /**
     * Pull the customer id out of a Bagisto customer create response.
     */
    private function extractCustomerId(string $body): ?int
    {
        try {
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }

        if (!is_array($response)) {
            return null;
        }

        $id = data_get($response, 'data.id', data_get($response, 'id'));

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Look up the id of the customer with the given email in Bagisto.
     *
     * The admin API filters customers with `?email=` as a partial (LIKE) match,
     * so results are compared exactly here. Array-shaped parameters such as
     * `email[eq]=` must not be used: Bagisto concatenates the raw value into the
     * LIKE pattern, and the resulting "Array to string conversion" is escalated
     * to an exception by Laravel, which is returned as HTTP 500.
     *
     * When the lookup itself fails the sync falls through to the create call,
     * which is still protected by the unique email validation (HTTP 422).
     */
    private function findCustomerId(string $baseUrl, array $headers, string $email): ?int
    {
        $url = $baseUrl . '/api/admin/customers?' . http_build_query([
            'email' => $email,
            'page' => 1,
            'per_page' => 50,
        ]);

        [$body, $status] = $this->request($url, 'GET', $headers);

        if ($status === 404) {
            return null;
        }

        if ($status < 200 || $status >= 300) {
            Log::warning('Bagisto customer lookup failed', [
                'email' => $email,
                'status' => $status,
                'body' => $this->truncate($body),
            ]);

            $this->warn(sprintf(
                '%s: customer lookup returned HTTP %d (%s); falling back to create.',
                $email,
                $status,
                $this->truncate($body)
            ));

            return null;
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('Customer lookup returned an invalid data response.');
        }

        $customers = $response['data'] ?? $response;
        if (!is_array($customers)) {
            throw new RuntimeException('Customer lookup returned an invalid data response.');
        }

        foreach ($customers as $customer) {
            if (
                is_array($customer)
                && strcasecmp((string) data_get($customer, 'email', ''), $email) === 0
            ) {
                $id = data_get($customer, 'id');

                return is_numeric($id) ? (int) $id : 0;
            }
        }

        return null;
    }

    private function truncate(string $body, int $length = 500): string
    {
        $body = trim($body);

        return strlen($body) > $length ? substr($body, 0, $length) . '…' : $body;
    }

    private function isDuplicateEmailResponse(int $statusCode, string $body): bool
    {
        if ($statusCode !== 422) {
            return false;
        }

        return stripos($body, 'has already been taken') !== false;
    }

    /**
     * Detect a create failure caused by the unique phone constraint.
     *
     * Bagisto does not validate the phone for uniqueness, so a reused phone
     * surfaces as an HTTP 500 from the `customers_phone_unique` index.
     */
    private function isDuplicatePhoneResponse(int $statusCode, string $body): bool
    {
        if ($statusCode < 400) {
            return false;
        }

        if (stripos($body, 'customers_phone_unique') !== false) {
            return true;
        }

        return $statusCode === 422
            && stripos($body, 'phone') !== false
            && stripos($body, 'has already been taken') !== false;
    }

    private function request(string $url, string $method, array $headers, ?string $content = null): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $content ?? '',
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);

        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("HTTP request failed for {$url}.");
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        return [$body, $statusCode];
    }
}