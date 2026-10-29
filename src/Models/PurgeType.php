<?php

declare(strict_types=1);

namespace SmallPict\Models;

final class PurgeType
{
    public const URL = 'url';
    public const FOLDER = 'folder';
    public const ALL = 'all';

    private function __construct()
    {
    }
}
