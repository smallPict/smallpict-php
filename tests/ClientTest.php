<?php

declare(strict_types=1);

namespace SmallPict\Tests;

use PHPUnit\Framework\TestCase;
use SmallPict\Client;
use SmallPict\Config;
use SmallPict\Exceptions\PermissionDeniedException;
use SmallPict\Exceptions\ValidationException;
use SmallPict\Http\HttpClientInterface;
use SmallPict\Models\CompressionMode;
use SmallPict\Models\FallbackMode;
use SmallPict\Models\ImageFormat;
use SmallPict\Models\OptimizeOptions;

class ClientTest extends TestCase
{
    public function testMissingApiKeyThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        new Client('');
    }

    public function testOptimizeSuccess(): void
    {
        $mockHttp = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                TestCase::assertSame('POST', $method);
                TestCase::assertSame('https://api.smallpict.app/v1/optimize', $url);
                TestCase::assertSame('sp_sdk_test_1234567890', $headers['X-API-Key']);
                TestCase::assertArrayHasKey('X-Signature', $headers);
                TestCase::assertArrayHasKey('X-Timestamp', $headers);
                TestCase::assertArrayHasKey('Idempotency-Key', $headers);

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => (string)json_encode([
                        'job_id' => 'job_php_123',
                        'status' => 'completed',
                        'url' => 'https://cdn.smallpict.app/opt/photo.avif',
                        'format' => 'avif',
                        'original_size' => 100000,
                        'compressed_size' => 15000,
                        'bytes_saved' => 85000,
                        'savings_percentage' => 85.0,
                    ]),
                ];
            }
        };

        $client = new Client(
            new Config('sp_sdk_test_1234567890', 'sec_test_secret_123'),
            null,
            $mockHttp
        );

        $result = $client->optimize('fake image raw binary string', new OptimizeOptions(ImageFormat::AVIF, 80));

        $this->assertSame('job_php_123', $result->getJobId());
        $this->assertSame('completed', $result->getStatus());
        $this->assertSame('https://cdn.smallpict.app/opt/photo.avif', $result->getUrl());
        $this->assertSame(85.0, $result->getSavingsPercentage());
        $this->assertSame(85000, $result->getBytesSaved());
    }

    public function testGetQuota(): void
    {
        $mockHttp = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                TestCase::assertSame('GET', $method);
                TestCase::assertSame('https://api.smallpict.app/v1/quota', $url);

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => (string)json_encode([
                        'plan' => 'api_velocity',
                        'bytes_used' => 2500000,
                        'quota_limit' => 10000000,
                        'quota_percentage' => 25.0,
                        'active_keys_count' => 2,
                    ]),
                ];
            }
        };

        $client = new Client('sp_sdk_test', null, $mockHttp);
        $quota = $client->getQuota();

        $this->assertSame('api_velocity', $quota->getPlan());
        $this->assertSame(2500000, $quota->getBytesUsed());
        $this->assertSame(25.0, $quota->getQuotaPercentage());
        $this->assertSame(2, $quota->getActiveKeysCount());
    }

    public function testPurgeCdn(): void
    {
        $mockHttp = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                TestCase::assertSame('POST', $method);
                TestCase::assertSame('https://api.smallpict.app/v1/purge', $url);

                return [
                    'status' => 202,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => (string)json_encode(['message' => 'Purge enqueued']),
                ];
            }
        };

        $client = new Client('sp_sdk_test', null, $mockHttp);
        $res = $client->purgeCdn(['https://cdn.smallpict.app/opt/photo.avif']);

        $this->assertSame('Purge enqueued', $res->getMessage());
    }

    public function testValidateKey(): void
    {
        $successMock = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => (string)json_encode(['plan' => 'free', 'bytes_used' => 0, 'quota_limit' => 100, 'quota_percentage' => 0.0]),
                ];
            }
        };

        $validClient = new Client('sp_sdk_valid', null, $successMock);
        $this->assertTrue($validClient->validateKey());

        $failMock = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                return [
                    'status' => 401,
                    'headers' => [],
                    'body' => (string)json_encode(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid key']]),
                ];
            }
        };

        $invalidClient = new Client('sp_sdk_invalid', null, $failMock);
        $this->assertFalse($invalidClient->validateKey());
    }

    public function testFallbackPassthroughOnQuotaExceeded(): void
    {
        $mockHttp = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                return [
                    'status' => 402,
                    'headers' => [],
                    'body' => (string)json_encode(['error' => ['code' => 'QUOTA_EXCEEDED', 'message' => 'Quota full']]),
                ];
            }
        };

        $config = new Config('sp_sdk_test', null, null, 30.0, 3, FallbackMode::PASSTHROUGH);
        $client = new Client($config, null, $mockHttp);

        $result = $client->optimize('raw binary test data', new OptimizeOptions());

        $this->assertSame('fallback-passthrough', $result->getJobId());
        $this->assertSame(0.0, $result->getSavingsPercentage());
        $this->assertSame(0, $result->getBytesSaved());
    }

    public function testOptimizeWithMaxDimensionAndArrayOptions(): void
    {
        $mockHttp = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                $data = json_decode((string)$body, true);
                TestCase::assertSame(1600, $data['options']['max_dimension']);
                TestCase::assertSame('webp', $data['options']['format']);
                TestCase::assertSame('lossless', $data['options']['mode']);
                TestCase::assertArrayNotHasKey('lossless', $data['options']);

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => (string)json_encode([
                        'job_id' => 'job_php_dim_1',
                        'status' => 'completed',
                        'url' => 'https://cdn.smallpict.app/opt/photo.webp',
                        'format' => 'webp',
                        'original_size' => 100000,
                        'compressed_size' => 40000,
                        'bytes_saved' => 60000,
                        'savings_percentage' => 60.0,
                    ]),
                ];
            }
        };

        $client = new Client(new Config('sp_sdk_test_1234567890'), null, $mockHttp);
        $result = self::withoutDeprecations(function () use ($client) {
            return $client->optimize('raw binary test data', [
                'format' => 'webp',
                'max_dimension' => 1600,
                'lossless' => true,
            ]);
        });

        $this->assertSame('job_php_dim_1', $result->getJobId());
        $this->assertSame(60.0, $result->getSavingsPercentage());
    }

    /**
     * Runs $fn while recording E_USER_DEPRECATED notices instead of letting them reach PHPUnit.
     *
     * @return mixed
     */
    private static function withoutDeprecations(callable $fn, ?int &$count = null)
    {
        $count = 0;
        set_error_handler(function (int $errno) use (&$count): bool {
            $count++;
            return true;
        }, E_USER_DEPRECATED);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Mapping cases from smallpict-sdk-shared/compression-modes.json.
     *
     * @return array<string, array{0: array<string, mixed>, 1: ?string, 2: bool}>
     */
    public static function modeMappingCases(): array
    {
        return [
            'no mode, no flag' => [[], null, false],
            'mode only' => [['mode' => 'extreme'], 'extreme', false],
            'deprecated flag only' => [['lossless' => true], 'lossless', false],
            'flag false is ignored' => [['lossless' => false], null, false],
            'flag agrees with mode' => [['mode' => 'lossless', 'lossless' => true], 'lossless', false],
            'flag contradicts mode' => [['mode' => 'lossy', 'lossless' => true], null, true],
        ];
    }

    /**
     * @dataProvider modeMappingCases
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modeMappingCases')]
    public function testModeMapping(array $input, ?string $wireMode, bool $expectError): void
    {
        $mockHttp = new class implements HttpClientInterface {
            /** @var array<string, mixed>|null */
            public ?array $sent = null;
            public int $calls = 0;

            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                $this->calls++;
                $this->sent = json_decode((string)$body, true);

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => (string)json_encode(['job_id' => 'job_mode', 'status' => 'completed']),
                ];
            }
        };
        $client = new Client(new Config('sp_sdk_test_1234567890'), null, $mockHttp);

        $deprecations = 0;
        try {
            self::withoutDeprecations(function () use ($client, $input) {
                return $client->optimize('raw binary test data', $input);
            }, $deprecations);
            $this->assertFalse($expectError, 'expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue($expectError, 'unexpected ValidationException: ' . $e->getMessage());
            $this->assertSame(0, $mockHttp->calls, 'no HTTP call on client-side validation error');
            return;
        }

        $options = $mockHttp->sent['options'];
        $this->assertArrayNotHasKey('lossless', $options);
        if ($wireMode === null) {
            $this->assertArrayNotHasKey('mode', $options);
        } else {
            $this->assertSame($wireMode, $options['mode']);
        }
        $this->assertSame(!empty($input['lossless']) ? 1 : 0, $deprecations);
    }

    public function testModeViaConstructorAndUnknownModeRejected(): void
    {
        $opts = new OptimizeOptions(ImageFormat::AUTO, 80, null, null, 'cover', false, true, null, null, null, null, CompressionMode::EXTREME);
        $this->assertSame('extreme', $opts->toArray()['mode']);

        $this->expectException(ValidationException::class);
        OptimizeOptions::fromArray(['mode' => 'ultra']);
    }

    public function testJobStatusParsesModeAndQualityApplied(): void
    {
        $mockHttp = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                $job = strpos($url, 'job_old') !== false
                    ? ['job_id' => 'job_old', 'status' => 'completed']
                    : ['job_id' => 'job_new', 'status' => 'completed', 'mode_applied' => 'extreme', 'quality_applied' => 55];

                return ['status' => 200, 'headers' => [], 'body' => (string)json_encode($job)];
            }
        };
        $client = new Client('sp_sdk_test', null, $mockHttp);

        $new = $client->getJobStatus('job_new');
        $this->assertSame('extreme', $new->getModeApplied());
        $this->assertSame(55, $new->getQualityApplied());

        $old = $client->getJobStatus('job_old');
        $this->assertNull($old->getModeApplied());
        $this->assertNull($old->getQualityApplied());
    }

    public function testModeNotInPlanRaisesPermissionDenied(): void
    {
        $mockHttp = new class implements HttpClientInterface {
            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                return [
                    'status' => 403,
                    'headers' => [],
                    'body' => (string)json_encode(['error' => [
                        'code' => 'mode_not_in_plan',
                        'message' => 'Mode extreme is not included in your plan',
                        'details' => ['mode' => 'extreme', 'allowed_modes' => ['lossy']],
                    ]]),
                ];
            }
        };
        $client = new Client('sp_sdk_test', null, $mockHttp);

        try {
            $client->optimize('raw binary test data', ['mode' => CompressionMode::EXTREME]);
            $this->fail('expected PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('not included in your plan', $e->getMessage());
            $this->assertSame('extreme', $e->getDetails()['mode']);
            $this->assertSame(['lossy'], $e->getDetails()['allowed_modes']);
        }
    }
}
