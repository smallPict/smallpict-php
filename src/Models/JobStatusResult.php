<?php

declare(strict_types=1);

namespace SmallPict\Models;

class JobStatusResult
{
    private string $jobId;
    private string $status;
    private ?string $url;
    private ?string $format;
    private ?int $bytesSaved;
    /**
     * @var array<string, mixed>|null
     */
    private ?array $error;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $modeApplied;
    private ?int $qualityApplied;

    /**
     * @param string $jobId
     * @param string $status
     * @param string|null $url
     * @param string|null $format
     * @param int|null $bytesSaved
     * @param array<string, mixed>|null $error
     * @param string|null $createdAt
     * @param string|null $updatedAt
     * @param string|null $modeApplied
     * @param int|null $qualityApplied
     */
    public function __construct(
        string $jobId,
        string $status,
        ?string $url = null,
        ?string $format = null,
        ?int $bytesSaved = null,
        ?array $error = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $modeApplied = null,
        ?int $qualityApplied = null
    ) {
        $this->jobId = $jobId;
        $this->status = $status;
        $this->url = $url;
        $this->format = $format;
        $this->bytesSaved = $bytesSaved;
        $this->error = $error;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->modeApplied = $modeApplied;
        $this->qualityApplied = $qualityApplied;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function getBytesSaved(): ?int
    {
        return $this->bytesSaved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getError(): ?array
    {
        return $this->error;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Mode the server applied; null for jobs created before modes existed.
     */
    public function getModeApplied(): ?string
    {
        return $this->modeApplied;
    }

    /**
     * Quality the server applied; null for jobs created before modes existed.
     */
    public function getQualityApplied(): ?int
    {
        return $this->qualityApplied;
    }
}
