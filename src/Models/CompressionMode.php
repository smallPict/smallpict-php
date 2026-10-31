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
    /** The server picks the quality per image from a visual score (CMP-07). */
    public const AUTO = 'auto';

    public const ALL = [self::LOSSY, self::EXTREME, self::LOSSLESS, self::AUTO];

    private function __construct()
    {
    }
}
