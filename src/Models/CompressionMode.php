<?php

declare(strict_types=1);

namespace SmallPict\Models;

/**
 * Compression mode sent as `options.mode` on POST /v1/optimize.
 * The server checks the mode against the plan (403 mode_not_in_plan).
 */
final class CompressionMode
{
    public const LOSSY = 'lossy';
    public const EXTREME = 'extreme';
    public const LOSSLESS = 'lossless';

    public const ALL = [self::LOSSY, self::EXTREME, self::LOSSLESS];

    private function __construct()
    {
    }
}
