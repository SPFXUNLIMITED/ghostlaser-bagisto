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
        $apiKey = trim((string) env('BAGISTO_API_KEY'));
        if ($apiKey === '') {
            $this->error('BAGISTO_API_KEY is not configured.');

            return self::FAILURE;
        }

        $adminToken = trim((string) env('BAGISTO_ADMIN_TOKEN'));
        if ($adminToken === '') {
            $this->error('BAGISTO_ADMIN_TOKEN is not configured.');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $page = 1;
        $perPage = 100;
        $total = PHP_INT_MAX;

        while ($page * $perPage < $total || $page === 1) {
            try {
                $url = 'https://ghostlaser.com/spfx_ghostlaser/routes/api/bagisto-customers.php?'
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
                        ];

                        foreach ([
                            'city' => 'city',
                            'state' => 'state',
                            'zip' => 'postcode',
                            'country' => 'country',
                        ] as $sourceField => $targetField) {
                            $value = $customer[$sourceField] ?? null;
                            if ($value !== null && trim((string) $value) !== '') {
                                $payload[$targetField] = $value;
                            }
                        }

                        $email = trim((string) $payload['email']);
                        $headers = [
                            'Authorization' => 'Bearer ' . $adminToken,
                            'Accept' => 'application/json',
                        ];

                        [$existingBody, $existingStatus] = $this->request(
                            'https://ghostlaser.com/api/admin/customers?email=' . urlencode($email),
                            'GET',
                            $headers
                        );

						if ($existingStatus === 404) {
							$exists = false;
						} else if ($existingStatus < 200 || $existingStatus >= 300) {
							throw new RuntimeException("Customer lookup returned HTTP {$existingStatus}.");
						} else {
							$existingResponse = json_decode($existingBody, true, 512, JSON_THROW_ON_ERROR);
							if (!is_array($existingResponse) || !isset($existingResponse ) || !is_array($existingResponse )) {
								throw new RuntimeException('Customer lookup returned an invalid data response.');
							}

							$exists = false;
							foreach ($existingResponse as $existingCustomer) {
								if (
									is_array($existingCustomer)
									&&isset($existingCustomer )
									&& stripos((string) data_get($existingCustomer, 'email', ''), $email) === 0
								) {
									$exists = true;
									break;
								}
							}
						}

                        $existingResponse = json_decode($existingBody, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($existingResponse) || !isset($existingResponse['data']) || !is_array($existingResponse['data'])) {
                            throw new RuntimeException('Customer lookup returned an invalid data response.');
                        }

                        $exists = false;
                        foreach ($existingResponse['data'] as $existingCustomer) {
                            if (
                                is_array($existingCustomer)
                                && isset($existingCustomer['email'])
                                && strcasecmp((string) $existingCustomer['email'], $email) === 0
                            ) {
                                $exists = true;
                                break;
                            }
                        }

                        if ($exists) {
                            $skipped++;

                            continue;
                        }

                        [$createdBody, $createdStatus] = $this->request(
                            'https://ghostlaser.com/api/admin/customers',
                            'POST',
                            $headers + ['Content-Type' => 'application/json'],
                            json_encode($payload, JSON_THROW_ON_ERROR)
                        );

                        if ($createdStatus < 200 || $createdStatus >= 300) {
							Log::error('Bagisto 422 body', );
                            throw new RuntimeException("Customer creation returned HTTP {$createdStatus}.");
                        }

                        $created++;
                        Log::info('Ghost Laser customer created in Bagisto.', ['email' => $email]);
                    } catch (Throwable $exception) {
                        $errors++;
                        Log::error('Failed to sync Ghost Laser customer.', [
                            'email' => $email,
                            'exception' => $exception,
                        ]);
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