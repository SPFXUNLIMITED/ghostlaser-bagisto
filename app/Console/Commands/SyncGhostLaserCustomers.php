<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncGhostLaserCustomers extends Command
{
    protected $signature = 'ghostlaser:sync-customers';

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

        $customerGroupId = (int) config('ghostlaser.customer_group_id');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
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

                        $payload = [
                            'first_name' => $customer['first_name'] ?? '',
                            'last_name' => $customer['last_name'] ?? '',
                            'email' => $customer['email'] ?? '',
                            'phone' => $customer['phone'] ?? '',
                            'gender' => 'Other',
                            'customer_group_id' => $customerGroupId,
                        ];

                        $email = trim((string) $payload['email']);
                        if ($email === '') {
                            throw new RuntimeException('Customer record has no email address.');
                        }

                        $payload['email'] = $email;

                        $headers = [
                            'Authorization' => 'Bearer ' . $adminToken,
                            'Accept' => 'application/json',
                        ];

                        if ($this->customerExists($baseUrl, $headers, $email)) {
                            $skipped++;

                            continue;
                        }

                        [$createdBody, $createdStatus] = $this->request(
                            $baseUrl . '/api/admin/customers',
                            'POST',
                            $headers + ['Content-Type' => 'application/json'],
                            json_encode($payload, JSON_THROW_ON_ERROR)
                        );

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

        $this->line(sprintf(
            'Sync complete: created %d, updated %d, skipped %d, errors %d.',
            $created,
            $updated,
            $skipped,
            $errors
        ));

        return self::SUCCESS;
    }

    /**
     * Check whether a customer with the given email already exists in Bagisto.
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
    private function customerExists(string $baseUrl, array $headers, string $email): bool
    {
        $url = $baseUrl . '/api/admin/customers?' . http_build_query([
            'email' => $email,
            'page' => 1,
            'per_page' => 50,
        ]);

        [$body, $status] = $this->request($url, 'GET', $headers);

        if ($status === 404) {
            return false;
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

            return false;
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
                return true;
            }
        }

        return false;
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