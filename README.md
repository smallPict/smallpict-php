# SmallPict PHP SDK

Official PHP SDK for the [SmallPict Image Optimization API](https://smallpict.app) — high-performance next-gen image transcoding (AVIF, WebP), smart compression, Edge CDN delivery, and cache purging.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/smallpict/smallpict-php.svg)](https://packagist.org/packages/smallpict/smallpict-php)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue.svg)](https://php.net)

---

## ⚡ Features

- **🐘 Broad PHP Compatibility:** Supports **PHP 7.4 through PHP 8.4** seamlessly.
- **🔌 Zero-Dependency Default:** Built-in high-performance native cURL client; PSR-18 compatible for modern stacks.
- **📦 Optional Laravel Auto-Discovery:** Instant integration via `SmallPictServiceProvider` and `SmallPict` Facade.
- **🛡️ Secure HMAC-SHA256 & Bearer Auth:** Tamper-proof payload verification.
- **✨ 4 Core Unified Methods:** `optimize()`, `getQuota()`, `purgeCdn()`, and `validateKey()`.
- **🔄 Resilience & Fault Tolerance:** Automatic `Idempotency-Key` UUID injection, 30s timeouts, and exponential backoff with jitter on HTTP 429/5xx.
- **🔒 Zero-Leak Privacy:** API keys and credentials are automatically redacted from error traces and exception logs.

---

## 📥 Installation

```bash
composer require smallpict/smallpict-php
```

---

## 🚀 Quick Start

### 1. Standalone PHP Example

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SmallPict\Client;
use SmallPict\Models\OptimizeOptions;
use SmallPict\Models\ImageFormat;

$client = new Client(
    getenv('SMALLPICT_API_KEY'),
    getenv('SMALLPICT_SECRET_KEY') // Optional HMAC Secret Key
);

$imageContent = file_get_contents('hero-banner.png');

$result = $client->optimize(
    $imageContent,
    new OptimizeOptions(
        ImageFormat::AVIF, // Target format: 'avif', 'webp', 'auto', etc.
        80,                // Quality: 1-100
        1920               // Max width in pixels
    )
);

echo "Optimized CDN URL: " . $result->getUrl() . "\n";
echo "Saved: " . $result->getSavingsPercentage() . "% (" . $result->getBytesSaved() . " bytes)\n";

// Compression mode: CompressionMode::LOSSY (default), EXTREME or LOSSLESS.
// The server checks it against your plan and answers 403 mode_not_in_plan
// (PermissionDeniedException, allowed modes in getDetails()['allowed_modes']).
// The old 'lossless' => true option is deprecated; use 'mode' instead.
$result = $client->optimize($imageContent, [
    'format' => ImageFormat::WEBP,
    'mode'   => \SmallPict\Models\CompressionMode::LOSSLESS,
]);

// Optional upload folder (1-3 segments, e.g. "blog/2026"), sent as options.folder.
// The server lower-cases it; read it back with $result->getFolder() or getJobStatus()->getFolder().
// A malformed folder throws ValidationException (server code 400 invalid_folder).
$result = $client->optimize($imageContent, ['folder' => 'blog/2026']);
```

### 2. Laravel Integration (Laravel 10 / 11)

In `config/services.php`:
```php
'smallpict' => [
    'api_key' => env('SMALLPICT_API_KEY'),
    'secret_key' => env('SMALLPICT_SECRET_KEY'),
    'fallback_mode' => 'passthrough', // 'throw' | 'passthrough'
],
```

In your Controller:
```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SmallPict\Laravel\Facades\SmallPict;
use SmallPict\Models\OptimizeOptions;
use SmallPict\Models\ImageFormat;

class MediaController extends Controller
{
    public function store(Request $request)
    {
        $file = $request->file('avatar');
        
        $result = SmallPict::optimize(
            $file->getRealPath(),
            new OptimizeOptions(ImageFormat::AUTO, 85)
        );

        return response()->json([
            'cdn_url' => $result->getUrl(),
            'format' => $result->getFormat(),
            'savings' => "{$result->getSavingsPercentage()}%",
        ]);
    }
}
```

### 3. WordPress Plugin / Theme Integration

```php
use SmallPict\Client;
use SmallPict\Models\OptimizeOptions;

function my_custom_image_upload_handler($file_path) {
    $client = new Client(get_option('smallpict_api_key'));
    
    try {
        $result = $client->optimize($file_path, new OptimizeOptions());
        return $result->getUrl();
    } catch (\SmallPict\Exceptions\SmallPictException $e) {
        // Fallback safely to original image on error
        error_log("SmallPict optimization error: " . $e->getMessage());
        return $file_path;
    }
}
```

---

## 📊 Account Quota & Edge CDN Purge

```php
use SmallPict\Client;

$client = new Client(getenv('SMALLPICT_API_KEY'));

// 1. Check real-time quota
$quota = $client->getQuota();
echo "Plan: {$quota->getPlan()}, Quota used: {$quota->getQuotaPercentage()}%\n";

// 2. Invalidate CDN cache
$client->purgeCdn(['https://cdn.smallpict.app/opt/hero-banner.avif']);
echo "CDN cache invalidated successfully.\n";

// 3. Purge every file uploaded into one folder (or PurgeType::ALL for all your files).
// Folder and all purges are limited per hour (10 and 2); one more throws
// RateLimitException (purge_rate_limited), which the SDK does not retry.
$client->purgeCdn([], \SmallPict\Models\PurgeType::FOLDER, 'blog/2026');
```

---

## 🧪 Testing

```bash
composer test
```

---

## 🔒 Security & Schema Compatibility (2026)

- **`mimeType` & `contentType` Dual Support**: `OptimizeOptions` supports both `getMimeType()` and `getContentType()` methods. Outgoing optimization requests automatically include both `mime_type` and `content_type` in the payload for zero-disruption compatibility across all SmallPict API versions.

---

## 📄 License

MIT © [SmallPict Engineering](https://smallpict.app)
