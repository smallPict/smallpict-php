<?php

declare(strict_types=1);

namespace SmallPict\Models;

class OptimizeResult
{
    private string $jobId;
    private string $status;
    private string $url;
    private string $format;
    private int $originalSize;
    private int $compressedSize;
    private int $bytesSaved;
    private float $savingsPercentage;
    private ?string $uploadUrl;
    private ?string $data;
    private ?string $folder;

    public function __construct(
        string $jobId,
        string $status,
        string $url,
        string $format,
        int $originalSize,
        int $compressedSize,
        int $bytesSaved,
        float $savingsPercentage,
        ?string $uploadUrl = null,
        ?string $data = null,
        ?string $folder = null
    ) {
        $this->jobId = $jobId;
        $this->status = $status;
        $this->url = $url;
        $this->format = $format;
        $this->originalSize = $originalSize;
        $this->compressedSize = $compressedSize;
        $this->bytesSaved = $bytesSaved;
        $this->savingsPercentage = $savingsPercentage;
        $this->uploadUrl = $uploadUrl;
        $this->data = $data;
        $this->folder = $folder;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getOriginalSize(): int
    {
        return $this->originalSize;
    }

    public function getCompressedSize(): int
    {
        return $this->compressedSize;
    }

    public function getBytesSaved(): int
    {
        return $this->bytesSaved;
    }

    public function getSavingsPercentage(): float
    {
        return $this->savingsPercentage;
    }

    public function getUploadUrl(): ?string
    {
        return $this->uploadUrl;
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    /**
     * Folder stored with the job as the server returned it (lower case); null without one.
     */
    public function getFolder(): ?string
    {
        return $this->folder;
    }
}
