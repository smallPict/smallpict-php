<?php

declare(strict_types=1);

namespace SmallPict;

use SmallPict\Exceptions\AuthenticationException;
use SmallPict\Exceptions\NotFoundException;
use SmallPict\Exceptions\PermissionDeniedException;
use SmallPict\Exceptions\QuotaExceededException;
use SmallPict\Exceptions\RateLimitException;
use SmallPict\Exceptions\ServerException;
use SmallPict\Exceptions\SmallPictException;
use SmallPict\Exceptions\ValidationException;
use SmallPict\Http\CurlHttpClient;
use SmallPict\Http\HttpClientInterface;
use SmallPict\Models\FallbackMode;
use SmallPict\Models\ImageFormat;
use SmallPict\Models\JobStatusResult;
use SmallPict\Models\OptimizeOptions;
use SmallPict\Models\OptimizeResult;
use SmallPict\Models\PurgeResponse;
use SmallPict\Models\PurgeType;
use SmallPict\Models\Capacity;
use SmallPict\Models\QuotaResponse;

class Client
{
    private Config $config;
    private HttpClientInterface $httpClient;

    /**
     * @param Config|string|null $config Config object or API Key string
     * @param string|null $secretKey Optional Secret Key for HMAC-SHA256 signing
     * @param HttpClientInterface|null $httpClient Optional custom HTTP client adapter
     */
    public function __construct(
        $config = null,
        ?string $secretKey = null,
        ?HttpClientInterface $httpClient = null
    ) {
        if ($config instanceof Config) {
            $this->config = $config;
        } elseif (is_string($config) || $config === null) {
            $this->config = new Config($config, $secretKey);
        } else {
            throw new ValidationException('First argument must be an instance of Config, an API Key string, or null');
        }

        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * @param string|resource $source Image binary string, file path, or stream resource
     * @param OptimizeOptions|array<string, mixed>|null $options Transformation options
     * @return OptimizeResult
     * @throws SmallPictException
     */
    public function optimize($source, $options = null): OptimizeResult
    {
        if (is_array($options)) {
            $opts = OptimizeOptions::fromArray($options);
        } elseif ($options instanceof OptimizeOptions) {
            $opts = $options;
        } else {
            $opts = new OptimizeOptions();
        }
        $resolved = $this->resolveSource($source, $opts);

        $payload = [
            'filename'     => $resolved['filename'],
            'mime_type'    => $resolved['mime_type'],
            'content_type' => $resolved['mime_type'],
            'filesize'     => $resolved['filesize'],
            'options'      => $opts->toArray(),
        ];

        try {
            $res = $this->request('POST', '/v1/optimize', $payload, $opts->getIdempotencyKey());

            $originalSize = (int)($res['original_size'] ?? $resolved['filesize']);
            $compressedSize = (int)($res['compressed_size'] ?? $originalSize);
            $bytesSaved = (int)($res['bytes_saved'] ?? max(0, $originalSize - $compressedSize));
            $savingsPercentage = (float)($res['savings_percentage'] ?? ($originalSize > 0 ? round(($bytesSaved / $originalSize) * 100, 2) : 0.0));

            return new OptimizeResult(
                (string)($res['job_id'] ?? 'sync'),
                (string)($res['status'] ?? 'completed'),
                (string)($res['url'] ?? ''),
                (string)($res['format'] ?? $opts->getFormat()),
                $originalSize,
                $compressedSize,
                $bytesSaved,
                $savingsPercentage,
                $res['upload_url'] ?? null,
                null,
                isset($res['folder']) ? (string)$res['folder'] : null
            );
        } catch (QuotaExceededException $e) {
            if ($this->config->getFallbackMode() === FallbackMode::PASSTHROUGH) {
                return new OptimizeResult(
                    'fallback-passthrough',
                    'completed',
                    is_string($source) && (strpos($source, 'http://') === 0 || strpos($source, 'https://') === 0) ? $source : '',
                    str_replace('image/', '', $resolved['mime_type']),
                    $resolved['filesize'],
                    $resolved['filesize'],
                    0,
                    0.0,
                    null,
                    $resolved['bytes']
                );
            }
            throw $e;
        }
    }

    /**
     * Retrieve real-time account storage quota and CDN egress bandwidth metrics.
     *
     * @return QuotaResponse
     * @throws SmallPictException
     */
    public function getQuota(): QuotaResponse
    {
        $res = $this->request('GET', '/v1/quota');

        $bytesUsed = (int)($res['bytes_used'] ?? 0);
        $quotaLimit = (int)($res['quota_limit'] ?? 0);
        $percentage = (float)($res['quota_percentage'] ?? ($quotaLimit > 0 ? round(($bytesUsed / $quotaLimit) * 100, 2) : 0.0));

        return new QuotaResponse(
            (string)($res['plan'] ?? 'free'),
            $bytesUsed,
            $quotaLimit,
            $percentage,
            isset($res['cdn_egress_used_bytes']) ? (int)$res['cdn_egress_used_bytes'] : null,
            isset($res['cdn_egress_quota_bytes']) ? (int)$res['cdn_egress_quota_bytes'] : null,
            isset($res['active_keys_count']) ? (int)$res['active_keys_count'] : null,
            isset($res['active_sites_count']) ? (int)$res['active_sites_count'] : null,
            Capacity::fromArray(is_array($res['capacity'] ?? null) ? $res['capacity'] : null)
        );
    }

    /**
     * Invalidate cached assets across global CDN edge locations.
     *
     * @param array<string>|string $urls Single URL or array of URLs to purge
     * @param string $purgeType PurgeType::URL, PurgeType::FOLDER or PurgeType::ALL
     * @param string|null $folder Upload folder to purge; required for PurgeType::FOLDER, ignored otherwise
     * @return PurgeResponse
     * @throws SmallPictException 429 purge_rate_limited (hourly folder/all limit) raises RateLimitException without retry
     */
    public function purgeCdn($urls = [], string $purgeType = PurgeType::URL, ?string $folder = null): PurgeResponse
    {
        if ($purgeType === PurgeType::FOLDER) {
            if ($folder === null) {
                throw new ValidationException('folder is required for purge type "folder"');
            }
            OptimizeOptions::validateFolder($folder);
            $payload = [
                'purge_type' => $purgeType,
                'folder' => $folder,
            ];
        } else {
            $urlList = is_string($urls) ? [$urls] : (array)$urls;
            $payload = [
                'purge_type' => $purgeType,
                'urls' => $urlList,
            ];
        }

        $res = $this->request('POST', '/v1/purge', $payload);
        return new PurgeResponse((string)($res['message'] ?? 'Purge job accepted'));
    }

    /**
     * Check if the configured API credentials are valid.
     *
     * @return bool
     */
    public function validateKey(): bool
    {
        try {
            $this->getQuota();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Poll current status of an asynchronous image conversion task.
     *
     * @param string $jobId UUID of conversion job
     * @return JobStatusResult
     * @throws SmallPictException
     */
    public function getJobStatus(string $jobId): JobStatusResult
    {
        if ($jobId === '') {
            throw new ValidationException('jobId parameter is required');
        }

        $res = $this->request('GET', '/v1/optimize/status?job_id=' . urlencode($jobId));

        return new JobStatusResult(
            (string)($res['job_id'] ?? $jobId),
            (string)($res['status'] ?? 'processing'),
            $res['url'] ?? null,
            $res['format'] ?? null,
            isset($res['bytes_saved']) ? (int)$res['bytes_saved'] : null,
            isset($res['error']) && is_array($res['error']) ? $res['error'] : null,
            $res['created_at'] ?? null,
            $res['updated_at'] ?? null,
            isset($res['mode_applied']) ? (string)$res['mode_applied'] : null,
            isset($res['quality_applied']) ? (int)$res['quality_applied'] : null,
            isset($res['folder']) ? (string)$res['folder'] : null
        );
    }

    /**
     * Internal request pipeline with HMAC signing, idempotency, and backoff retry.
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $json
     * @param string|null $idempotencyKey
     * @return array<string, mixed>
     * @throws SmallPictException
     */
    private function request(
        string $method,
        string $path,
        ?array $json = null,
        ?string $idempotencyKey = null
    ): array {
        $cleanPath = strpos($path, '/') === 0 ? $path : '/' . $path;
        if (strpos($cleanPath, '/v1/') !== 0 && strpos($cleanPath, '/v2/') !== 0) {
            $cleanPath = '/v1' . $cleanPath;
        }

        $url = $this->config->getBaseUrl() . $cleanPath;
        $bodyStr = '';
        $bodyHash = Crypto::EMPTY_SHA256;

        if ($json !== null) {
            $bodyStr = (string)json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $bodyHash = Crypto::sha256Hex($bodyStr);
        }

        $attempt = 0;
        $maxRetries = $this->config->getMaxRetries();
        $baseDelayUs = 250000; // 250ms

        while ($attempt <= $maxRetries) {
            $attempt++;
            $timestamp = (string)time();

            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $this->config->getApiKey(),
            ];

            if ($json !== null) {
                $headers['Content-Type'] = 'application/json';
            }

            if ($this->config->getSecretKey() !== null) {
                $stringToSign = Crypto::buildStringToSign($method, $cleanPath, $timestamp, $bodyHash);
                $signature = Crypto::hmacSha256Hex($this->config->getSecretKey(), $stringToSign);
                $headers['X-Timestamp'] = $timestamp;
                $headers['X-Signature'] = $signature;
            } else {
                $headers['Authorization'] = 'Bearer ' . $this->config->getApiKey();
            }

            if (in_array(strtoupper($method), ['POST', 'PATCH', 'DELETE'], true)) {
                $headers['Idempotency-Key'] = $idempotencyKey ?? self::generateUuid();
            }

            $resp = $this->httpClient->send(
                $method,
                $url,
                $headers,
                $bodyStr !== '' ? $bodyStr : null,
                $this->config->getTimeout()
            );

            $status = $resp['status'];
            $respHeaders = $resp['headers'];
            $requestId = $respHeaders['x-request-id'] ?? null;
            $retryAfterHeader = $respHeaders['retry-after'] ?? null;

            $decoded = json_decode($resp['body'], true);
            $parsedBody = is_array($decoded) ? $decoded : ['raw' => $resp['body']];

            // Retry on 429 and transient 5xx; purge_rate_limited is an hourly limit, so retrying in seconds cannot help.
            if ($status === 429 || ($status >= 500 && $status <= 504)) {
                if ($attempt <= $maxRetries && self::errorCode($parsedBody) !== 'purge_rate_limited') {
                    $delayUs = (int)($baseDelayUs * (2 ** ($attempt - 1)));
                    if ($retryAfterHeader !== null && is_numeric($retryAfterHeader)) {
                        $delayUs = (int)((float)$retryAfterHeader * 1000000);
                    }
                    $jitterUs = random_int(0, 100000);
                    usleep($delayUs + $jitterUs);
                    continue;
                }
            }

            if ($status < 200 || $status >= 300) {
                $this->throwExceptionForStatus($status, $parsedBody, $requestId, $retryAfterHeader);
            }

            return $parsedBody;
        }

        throw new SmallPictException('Request failed after maximum retry attempts');
    }

    /**
     * @param int $status
     * @param array<string, mixed> $body
     * @param string|null $requestId
     * @param string|null $retryAfter
     * @throws SmallPictException
     */
    private function throwExceptionForStatus(
        int $status,
        array $body,
        ?string $requestId,
        ?string $retryAfter
    ): void {
        $message = "API request failed with HTTP {$status}";
        $details = [];

        if (isset($body['error'])) {
            if (is_string($body['error'])) {
                $message = $body['error'];
            } elseif (is_array($body['error'])) {
                $message = (string)($body['error']['message'] ?? $message);
                $details = (array)($body['error']['details'] ?? []);
            }
        } elseif (isset($body['message'])) {
            $message = (string)$body['message'];
        }

        $retrySeconds = $retryAfter !== null && is_numeric($retryAfter) ? (int)$retryAfter : null;

        switch ($status) {
            case 400:
                throw new ValidationException($message, $requestId, $details);
            case 401:
                throw new AuthenticationException($message, $requestId, $details);
            case 402:
                throw new QuotaExceededException($message, $requestId, $details);
            case 403:
                throw new PermissionDeniedException($message, $requestId, $details);
            case 404:
                throw new NotFoundException($message, $requestId, $details);
            case 429:
                throw new RateLimitException($message, $retrySeconds, $requestId, $details);
            default:
                if ($status >= 500) {
                    throw new ServerException($message, $status, $requestId, $details);
                }
                // Unmapped statuses (e.g. 409 capacity codes) keep the server's code.
                throw new SmallPictException($message, self::errorCode($body) ?? 'INTERNAL_ERROR', $status, $requestId, $details);
        }
    }

    /**
     * Helper to resolve source input into payload metadata.
     *
     * @param string|resource $source
     * @param OptimizeOptions $options
     * @return array{filename: string, mime_type: string, filesize: int, bytes: string|null}
     */
    private function resolveSource($source, OptimizeOptions $options): array
    {
        $filename = $options->getFilename() ?? 'image.jpg';
        $mimeType = $options->getMimeType() ?? 'image/jpeg';
        $filesize = 0;
        $bytes = null;

        if (is_resource($source)) {
            $bytes = (string)stream_get_contents($source);
            $filesize = strlen($bytes);
        } elseif (is_string($source)) {
            if (strpos($source, 'http://') === 0 || strpos($source, 'https://') === 0) {
                $path = (string)parse_url($source, PHP_URL_PATH);
                $filename = basename($path) ?: $filename;
                return ['filename' => $filename, 'mime_type' => $mimeType, 'filesize' => 0, 'bytes' => null];
            } elseif (file_exists($source) && is_file($source)) {
                $filename = basename($source);
                $bytes = (string)file_get_contents($source);
                $filesize = strlen($bytes);
            } else {
                // Raw binary data
                $bytes = $source;
                $filesize = strlen($bytes);
            }
        }

        return [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'filesize' => $filesize,
            'bytes' => $bytes,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function errorCode(array $body): ?string
    {
        return isset($body['error']['code']) && is_string($body['error']['code']) ? $body['error']['code'] : null;
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
