<?php

declare(strict_types=1);

namespace SmallPict\Tests;

use PHPUnit\Framework\TestCase;
use SmallPict\Client;
use SmallPict\Exceptions\PermissionDeniedException;
use SmallPict\Exceptions\QuotaExceededException;
use SmallPict\Exceptions\SmallPictException;
use SmallPict\Http\HttpClientInterface;

/**
 * Fixtures copied from smallpict-sdk-shared/capacity.json (not available in CI).
 */
class CapacityTest extends TestCase
{
    private const FIXTURES = <<<'JSON'
{
    "quota_responses": {
        "wordpress_with_capacity": {
            "plan": "pro",
            "bytes_used": 1048576,
            "capacity": {
                "period_end": "2026-11-30T00:00:00Z",
                "storage": {
                    "limit_bytes": 75161927680,
                    "used_bytes": 7516192768,
                    "base_bytes": 53687091200,
                    "addon_blocks": 2
                },
                "cdn": {
                    "limit_bytes": 53687091200,
                    "used_bytes": 44023414784,
                    "status": "warn_80"
                },
                "booster": {
                    "active": true,
                    "wp_quota_bytes": 2147483648,
                    "api_transforms": null,
                    "cdn_bytes": 107374182400,
                    "next_expiry": {
                        "kind": "cdn_bytes",
                        "units": 42949672960,
                        "expires_at": "2027-01-05T00:00:00Z"
                    }
                },
                "lapse": null,
                "manage_url": "https://smallpict.app/dashboard/billing"
            }
        },
        "api_blocked": {
            "plan": "velocity",
            "bytes_used": 0,
            "capacity": {
                "period_end": null,
                "storage": {
                    "limit_bytes": 107374182400,
                    "used_bytes": 1073741824,
                    "base_bytes": 107374182400,
                    "addon_blocks": 0
                },
                "cdn": null,
                "booster": {
                    "active": false,
                    "wp_quota_bytes": null,
                    "api_transforms": 5000,
                    "cdn_bytes": null,
                    "next_expiry": null
                },
                "lapse": {
                    "stage": "blocked",
                    "delete_after": "2027-01-28T00:00:00Z"
                },
                "manage_url": "https://smallpict.app/dashboard/billing"
            }
        },
        "sandbox": {
            "plan": "sandbox",
            "bytes_used": 0,
            "capacity": {
                "period_end": null,
                "storage": null,
                "cdn": {
                    "limit_bytes": 1073741824,
                    "used_bytes": 0,
                    "status": "ok"
                },
                "booster": {
                    "active": false,
                    "wp_quota_bytes": null,
                    "api_transforms": 0,
                    "cdn_bytes": 0,
                    "next_expiry": null
                },
                "lapse": null,
                "manage_url": "https://smallpict.app/dashboard/billing"
            }
        },
        "capacity_unreadable": {
            "plan": "pro",
            "bytes_used": 0,
            "capacity": null
        },
        "older_server_without_capacity": {
            "plan": "pro",
            "bytes_used": 0
        }
    },
    "error_cases": [
        {
            "http_status": 402,
            "code": "QUOTA_EXCEEDED",
            "sdk_error": "QuotaExceededError",
            "details": {
                "upgrade_url": "https://smallpict.app/dashboard/billing"
            },
            "upgrade_url": "https://smallpict.app/dashboard/billing"
        },
        {
            "http_status": 402,
            "code": "storage_quota_exceeded",
            "sdk_error": "QuotaExceededError",
            "details": {
                "limit_bytes": 10,
                "used_bytes": 10,
                "remaining_bytes": 0,
                "upgrade_url": "https://smallpict.app/dashboard/billing"
            },
            "upgrade_url": "https://smallpict.app/dashboard/billing"
        },
        {
            "http_status": 402,
            "code": "account_read_only",
            "sdk_error": "QuotaExceededError",
            "details": {
                "delete_after": "2027-01-28T00:00:00Z",
                "upgrade_url": "https://smallpict.app/dashboard/billing"
            },
            "upgrade_url": "https://smallpict.app/dashboard/billing"
        },
        {
            "http_status": 402,
            "code": "QUOTA_EXCEEDED",
            "sdk_error": "QuotaExceededError",
            "details": {
                "payload_bytes": 60000000
            },
            "upgrade_url": null
        },
        {
            "http_status": 403,
            "code": "mode_not_in_plan",
            "sdk_error": "PermissionDeniedError",
            "details": {
                "mode": "extreme",
                "allowed_modes": [
                    "lossy",
                    "lossless"
                ]
            },
            "allowed_modes": [
                "lossy",
                "lossless"
            ],
            "upgrade_url": null
        },
        {
            "http_status": 409,
            "code": "booster_period_limit_exceeded",
            "sdk_error": "SmallPictError",
            "details": {
                "remaining_steps": 0,
                "upgrade_url": "https://smallpict.app/dashboard/billing"
            },
            "upgrade_url": "https://smallpict.app/dashboard/billing"
        }
    ]
}
JSON;

    /** @return array<string, mixed> */
    private static function fixtures(): array
    {
        return json_decode(self::FIXTURES, true);
    }

    /** @param array<string, mixed> $body */
    private function client(int $status, array $body): Client
    {
        $http = new class ($status, $body) implements HttpClientInterface {
            private int $status;
            /** @var array<string, mixed> */
            private array $body;

            /** @param array<string, mixed> $body */
            public function __construct(int $status, array $body)
            {
                $this->status = $status;
                $this->body = $body;
            }

            public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): array
            {
                return ['status' => $this->status, 'headers' => [], 'body' => (string)json_encode($this->body)];
            }
        };
        return new Client('sp_sdk_test', null, $http);
    }

    private function quota(string $name): \SmallPict\Models\QuotaResponse
    {
        return $this->client(200, self::fixtures()['quota_responses'][$name])->getQuota();
    }

    public function testWordpressWithCapacityMapsEveryMember(): void
    {
        $q = $this->quota('wordpress_with_capacity');
        $this->assertSame('pro', $q->getPlan());
        $this->assertSame(1048576, $q->getBytesUsed());

        $c = $q->getCapacity();
        $this->assertNotNull($c);
        $this->assertSame('2026-11-30T00:00:00Z', $c->getPeriodEnd());
        $this->assertSame(
            ['limit_bytes' => 75161927680, 'used_bytes' => 7516192768, 'base_bytes' => 53687091200, 'addon_blocks' => 2],
            $c->getStorage()
        );
        $this->assertSame(['limit_bytes' => 53687091200, 'used_bytes' => 44023414784, 'status' => 'warn_80'], $c->getCdn());
        $this->assertSame([
            'active' => true,
            'wp_quota_bytes' => 2147483648,
            'api_transforms' => null,
            'cdn_bytes' => 107374182400,
            'next_expiry' => ['kind' => 'cdn_bytes', 'units' => 42949672960, 'expires_at' => '2027-01-05T00:00:00Z'],
        ], $c->getBooster());
        $this->assertNull($c->getLapse());
        $this->assertSame('https://smallpict.app/dashboard/billing', $c->getManageUrl());
    }

    public function testApiBlockedKeepsNullMembersNull(): void
    {
        $c = $this->quota('api_blocked')->getCapacity();
        $this->assertNotNull($c);
        $this->assertNull($c->getPeriodEnd());
        $this->assertNull($c->getCdn());
        $this->assertSame(107374182400, $c->getStorage()['limit_bytes'] ?? null);
        $this->assertSame(0, $c->getStorage()['addon_blocks'] ?? null);
        $this->assertSame(
            ['active' => false, 'wp_quota_bytes' => null, 'api_transforms' => 5000, 'cdn_bytes' => null, 'next_expiry' => null],
            $c->getBooster()
        );
        $this->assertSame(['stage' => 'blocked', 'delete_after' => '2027-01-28T00:00:00Z'], $c->getLapse());
    }

    public function testSandboxZerosStayZero(): void
    {
        $c = $this->quota('sandbox')->getCapacity();
        $this->assertNotNull($c);
        $this->assertNull($c->getStorage());
        $this->assertSame(['limit_bytes' => 1073741824, 'used_bytes' => 0, 'status' => 'ok'], $c->getCdn());
        $this->assertSame(
            ['active' => false, 'wp_quota_bytes' => null, 'api_transforms' => 0, 'cdn_bytes' => 0, 'next_expiry' => null],
            $c->getBooster()
        );
        $this->assertNull($c->getLapse());
    }

    public function testCapacityNullAndAbsentBothParse(): void
    {
        foreach (['capacity_unreadable', 'older_server_without_capacity'] as $name) {
            $q = $this->quota($name);
            $this->assertSame('pro', $q->getPlan(), $name);
            $this->assertNull($q->getCapacity(), $name);
        }
    }

    /** @return array<int, array{0: array<string, mixed>}> */
    public static function errorCases(): array
    {
        return array_map(static function (array $c): array {
            return [$c];
        }, self::fixtures()['error_cases']);
    }

    /**
     * @dataProvider errorCases
     * @param array<string, mixed> $case
     */
    public function testErrorCases(array $case): void
    {
        $client = $this->client($case['http_status'], ['error' => [
            'code' => $case['code'],
            'message' => 'capacity error',
            'details' => $case['details'],
        ]]);

        try {
            $client->getQuota();
            $this->fail('expected ' . $case['sdk_error']);
        } catch (SmallPictException $e) {
            $expected = [
                'QuotaExceededError' => QuotaExceededException::class,
                'PermissionDeniedError' => PermissionDeniedException::class,
                'SmallPictError' => SmallPictException::class,
            ][$case['sdk_error']];
            $this->assertSame($expected, get_class($e));
            $this->assertSame($case['http_status'], $e->getStatusCode());
            $this->assertSame($case['upgrade_url'], $e->getUpgradeUrl());
            if ($e instanceof PermissionDeniedException) {
                $this->assertSame($case['allowed_modes'], $e->getAllowedModes());
            }
            if ($case['sdk_error'] === 'SmallPictError') {
                $this->assertSame($case['code'], $e->getErrorCode());
            }
        }
    }

    public function testNonStringUpgradeUrlIsNotExposed(): void
    {
        $client = $this->client(402, ['error' => ['code' => 'QUOTA_EXCEEDED', 'message' => 'x', 'details' => ['upgrade_url' => ['nested' => 'x']]]]);
        try {
            $client->getQuota();
            $this->fail('expected QuotaExceededException');
        } catch (QuotaExceededException $e) {
            $this->assertNull($e->getUpgradeUrl());
        }
    }

    public function testAllowedModesNullWithoutDetails(): void
    {
        $client = $this->client(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'x']]);
        try {
            $client->getQuota();
            $this->fail('expected PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            $this->assertNull($e->getAllowedModes());
            $this->assertNull($e->getUpgradeUrl());
        }
    }
}
