<?php

declare(strict_types=1);

namespace SmallPict\Tests;

use PHPUnit\Framework\TestCase;
use SmallPict\Client;
use SmallPict\Config;
use SmallPict\Exceptions\PermissionDeniedException;
use SmallPict\Exceptions\RateLimitException;
use SmallPict\Exceptions\ValidationException;
use SmallPict\Http\HttpClientInterface;
use SmallPict\Models\CompressionMode;
use SmallPict\Models\FallbackMode;
use SmallPict\Models\ImageFormat;
use SmallPict\Models\OptimizeOptions;
use SmallPict\Models\PurgeType;

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

    /**
     * Mock HTTP client that records every request and answers with a fixed response.
     *
     * @param array<string, mixed> $responseBody
     */
    private function recordingHttp(int $status, array $responseBody): HttpClientInterface
    {
        return new class ($status, $responseBody) implements HttpClientInterface {
            /** @var array<int, array{method: string, url: string, body: array<string, mixed>|null}> */
            public array $requests = [];
            private int $status;
            /** @var array<string, mixed> */
            private array $responseBody;

            /** @param array<string, mixed> $responseBody */
            public function __construct(int $status, array $responseBody)
            {
                $this->status = $status;
                $this->responseBody = $responseBody;
            }

            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body !== null ? json_decode($body, true) : null];
                return ['status' => $this->status, 'headers' => [], 'body' => (string)json_encode($this->responseBody)];
            }
        };
    }

    public function testOptimizeSendsFolderAndSurfacesIt(): void
    {
        $http = $this->recordingHttp(200, ['job_id' => 'job_f', 'status' => 'completed', 'url' => 'https://cdn.smallpict.app/c/f/blog/2026/job_f.avif', 'folder' => 'blog/2026']);
        $client = new Client('sp_sdk_test', null, $http);

        $result = $client->optimize('raw binary test data', ['folder' => 'Blog/2026']);

        $this->assertSame('Blog/2026', $http->requests[0]['body']['options']['folder']);
        $this->assertSame('blog/2026', $result->getFolder());
    }

    public function testOptimizeOmitsFolderWhenUnset(): void
    {
        $http = $this->recordingHttp(200, ['job_id' => 'job_n', 'status' => 'completed']);
        $client = new Client('sp_sdk_test', null, $http);

        $result = $client->optimize('raw binary test data', new OptimizeOptions());

        $this->assertArrayNotHasKey('folder', $http->requests[0]['body']['options']);
        $this->assertNull($result->getFolder());
    }

    public function testJobStatusParsesFolder(): void
    {
        $client = new Client('sp_sdk_test', null, $this->recordingHttp(200, ['job_id' => 'job_f', 'status' => 'completed', 'folder' => 'products/shoes']));
        $this->assertSame('products/shoes', $client->getJobStatus('job_f')->getFolder());

        $client = new Client('sp_sdk_test', null, $this->recordingHttp(200, ['job_id' => 'job_n', 'status' => 'completed']));
        $this->assertNull($client->getJobStatus('job_n')->getFolder());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFolderProvider(): array
    {
        return [
            'empty segment' => ['a//b'],
            'leading slash' => ['/a'],
            'trailing slash' => ['a/'],
            'leading dotdot' => ['../x'],
            'inner dotdot' => ['a/../b'],
            'four segments' => ['a/b/c/d'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider invalidFolderProvider
     */
    public function testInvalidFolderRejectedBeforeHttp(string $folder): void
    {
        $http = $this->recordingHttp(200, []);
        $client = new Client('sp_sdk_test', null, $http);

        foreach ([
            function () use ($client, $folder) { $client->optimize('raw binary test data', ['folder' => $folder]); },
            function () use ($client, $folder) { $client->purgeCdn([], PurgeType::FOLDER, $folder); },
        ] as $call) {
            try {
                $call();
                $this->fail('expected ValidationException for folder "' . $folder . '"');
            } catch (ValidationException $e) {
                $this->assertSame($folder, $e->getDetails()['folder']);
            }
        }
        $this->assertCount(0, $http->requests);
    }

    public function testPurgeFolderBodyShape(): void
    {
        $http = $this->recordingHttp(202, ['message' => 'Purge job enqueued successfully']);
        $client = new Client('sp_sdk_test', null, $http);

        $res = $client->purgeCdn([], PurgeType::FOLDER, 'products/shoes');

        $this->assertSame('Purge job enqueued successfully', $res->getMessage());
        $this->assertSame(['purge_type' => 'folder', 'folder' => 'products/shoes'], $http->requests[0]['body']);
    }

    public function testPurgeUrlAndAllBodiesUnchanged(): void
    {
        $http = $this->recordingHttp(202, ['message' => 'ok']);
        $client = new Client('sp_sdk_test', null, $http);

        $client->purgeCdn('https://cdn.smallpict.app/c/x.avif', PurgeType::URL, 'ignored');
        $client->purgeCdn([], PurgeType::ALL);

        $this->assertSame(['purge_type' => 'url', 'urls' => ['https://cdn.smallpict.app/c/x.avif']], $http->requests[0]['body']);
        $this->assertSame(['purge_type' => 'all', 'urls' => []], $http->requests[1]['body']);
    }

    public function testPurgeFolderWithoutFolderRejectedBeforeHttp(): void
    {
        $http = $this->recordingHttp(202, []);
        $client = new Client('sp_sdk_test', null, $http);

        try {
            $client->purgeCdn([], PurgeType::FOLDER);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('folder', $e->getMessage());
        }
        $this->assertCount(0, $http->requests);
    }

    public function testServerInvalidFolderRaisesValidationWithServerMessage(): void
    {
        $http = $this->recordingHttp(400, ['error' => ['code' => 'invalid_folder', 'message' => 'folder segment "a b" contains invalid characters']]);
        $client = new Client('sp_sdk_test', null, $http);

        try {
            $client->optimize('raw binary test data', ['folder' => 'a b']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('folder segment "a b" contains invalid characters', $e->getMessage());
        }
    }

    public function testPurgeRateLimitedIsNotRetried(): void
    {
        $http = $this->recordingHttp(429, ['error' => ['code' => 'purge_rate_limited', 'message' => 'Folder purge limit reached (10 per hour)']]);
        $client = new Client(new Config('sp_sdk_test', null, null, 30.0, 3), null, $http);

        try {
            $client->purgeCdn([], PurgeType::FOLDER, 'blog');
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame('Folder purge limit reached (10 per hour)', $e->getMessage());
        }
        $this->assertCount(1, $http->requests);
    }

    public function testOtherRateLimitIsStillRetried(): void
    {
        $http = $this->recordingHttp(429, ['error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests']]);
        $client = new Client(new Config('sp_sdk_test', null, null, 30.0, 1), null, $http);

        try {
            $client->purgeCdn([], PurgeType::FOLDER, 'blog');
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame('Too many requests', $e->getMessage());
        }
        $this->assertCount(2, $http->requests);
    }
}
