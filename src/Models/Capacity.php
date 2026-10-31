<?php

declare(strict_types=1);

namespace SmallPict\Models;

/**
 * Read-only `capacity` of GET /v1/quota (QuotaCapacity in the API contract).
 *
 * Groups keep the wire keys (snake_case). A member that does not apply to the plan is null.
 * manage_url is a plain string: the SDK never opens or fetches it.
 */
class Capacity
{
    private ?string $periodEnd;
    /** @var array{limit_bytes: int, used_bytes: int, base_bytes: int, addon_blocks: int}|null */
    private ?array $storage;
    /** @var array{limit_bytes: int, used_bytes: int, status: string}|null */
    private ?array $cdn;
    /** @var array{active: bool, wp_quota_bytes: int|null, api_transforms: int|null, cdn_bytes: int|null, next_expiry: array{kind: string, units: int, expires_at: string}|null} */
    private array $booster;
    /** @var array{stage: string, delete_after: string|null}|null */
    private ?array $lapse;
    private string $manageUrl;

    /**
     * @param array<string, mixed>|null $c The wire `capacity` object; null stays null.
     */
    public static function fromArray(?array $c): ?self
    {
        if ($c === null) {
            return null;
        }

        $self = new self();
        $self->periodEnd = isset($c['period_end']) ? (string)$c['period_end'] : null;

        $s = is_array($c['storage'] ?? null) ? $c['storage'] : null;
        $self->storage = $s === null ? null : [
            'limit_bytes' => (int)($s['limit_bytes'] ?? 0),
            'used_bytes' => (int)($s['used_bytes'] ?? 0),
            'base_bytes' => (int)($s['base_bytes'] ?? 0),
            'addon_blocks' => (int)($s['addon_blocks'] ?? 0),
        ];

        $d = is_array($c['cdn'] ?? null) ? $c['cdn'] : null;
        $self->cdn = $d === null ? null : [
            'limit_bytes' => (int)($d['limit_bytes'] ?? 0),
            'used_bytes' => (int)($d['used_bytes'] ?? 0),
            'status' => (string)($d['status'] ?? ''),
        ];

        $b = is_array($c['booster'] ?? null) ? $c['booster'] : [];
        $e = is_array($b['next_expiry'] ?? null) ? $b['next_expiry'] : null;
        $self->booster = [
            'active' => ($b['active'] ?? false) === true,
            'wp_quota_bytes' => isset($b['wp_quota_bytes']) ? (int)$b['wp_quota_bytes'] : null,
            'api_transforms' => isset($b['api_transforms']) ? (int)$b['api_transforms'] : null,
            'cdn_bytes' => isset($b['cdn_bytes']) ? (int)$b['cdn_bytes'] : null,
            'next_expiry' => $e === null ? null : [
                'kind' => (string)($e['kind'] ?? ''),
                'units' => (int)($e['units'] ?? 0),
                'expires_at' => (string)($e['expires_at'] ?? ''),
            ],
        ];

        $l = is_array($c['lapse'] ?? null) ? $c['lapse'] : null;
        $self->lapse = $l === null ? null : [
            'stage' => (string)($l['stage'] ?? ''),
            'delete_after' => isset($l['delete_after']) ? (string)$l['delete_after'] : null,
        ];

        $self->manageUrl = (string)($c['manage_url'] ?? '');

        return $self;
    }

    public function getPeriodEnd(): ?string
    {
        return $this->periodEnd;
    }

    /**
     * @return array{limit_bytes: int, used_bytes: int, base_bytes: int, addon_blocks: int}|null
     */
    public function getStorage(): ?array
    {
        return $this->storage;
    }

    /**
     * @return array{limit_bytes: int, used_bytes: int, status: string}|null
     */
    public function getCdn(): ?array
    {
        return $this->cdn;
    }

    /**
     * @return array{active: bool, wp_quota_bytes: int|null, api_transforms: int|null, cdn_bytes: int|null, next_expiry: array{kind: string, units: int, expires_at: string}|null}
     */
    public function getBooster(): array
    {
        return $this->booster;
    }

    /**
     * @return array{stage: string, delete_after: string|null}|null
     */
    public function getLapse(): ?array
    {
        return $this->lapse;
    }

    public function getManageUrl(): string
    {
        return $this->manageUrl;
    }
}
