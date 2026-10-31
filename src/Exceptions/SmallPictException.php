<?php

declare(strict_types=1);

namespace SmallPict\Exceptions;

class SmallPictException extends \Exception
{
    protected string $errorCode;
    protected ?int $statusCode;
    protected ?string $requestId;
    /**
     * @var array<string, mixed>
     */
    protected array $details;

    /**
     * @param string $message
     * @param string $errorCode
     * @param int|null $statusCode
     * @param string|null $requestId
     * @param array<string, mixed> $details
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'INTERNAL_ERROR',
        ?int $statusCode = null,
        ?string $requestId = null,
        array $details = [],
        ?\Throwable $previous = null
    ) {
        $safeMessage = self::sanitizeMessage($message);
        parent::__construct($safeMessage, $statusCode ?? 0, $previous);

        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->requestId = $requestId;
        $this->details = $details;
    }

    public static function sanitizeMessage(string $msg): string
    {
        if ($msg === '') {
            return $msg;
        }

        // Mask sp_sdk_..., sp_test_..., sp_wp_...
        $msg = (string)preg_replace_callback(
            '/sp_(test|sdk|wp)_[a-zA-Z0-9_-]{10,}/',
            static function ($matches) {
                $full = $matches[0];
                return substr($full, 0, 10) . '...' . substr($full, -4);
            },
            $msg
        );

        // Mask secrets
        $msg = (string)preg_replace('/(sec|secret)_[a-zA-Z0-9_-]{8,}/i', '***REDACTED***', $msg);
        $msg = (string)preg_replace('/Bearer\s+[a-zA-Z0-9._-]+/i', 'Bearer ***REDACTED***', $msg);

        return $msg;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Dashboard page where the missing capacity is added (details.upgrade_url), or null.
     * Show it to the user; the SDK never opens or fetches it.
     */
    public function getUpgradeUrl(): ?string
    {
        $url = $this->details['upgrade_url'] ?? null;
        return is_string($url) ? $url : null;
    }

    public function __toString(): string
    {
        $statusStr = $this->statusCode ? " HTTP {$this->statusCode}" : '';
        $reqStr = $this->requestId ? " (Request ID: {$this->requestId})" : '';
        return "[SmallPictException] ({$this->errorCode}{$statusStr}): {$this->getMessage()}{$reqStr}";
    }
}
