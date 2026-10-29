<?php

declare(strict_types=1);

namespace SmallPict\Models;

use SmallPict\Exceptions\ValidationException;

class OptimizeOptions
{
    private string $format;
    private ?int $quality;
    private ?int $maxWidth;
    private ?int $maxHeight;
    private ?int $maxDimension;
    private string $fit;
    private bool $lossless;
    private ?string $mode;
    private bool $stripMetadata;
    private ?string $filename;
    private ?string $mimeType;
    private ?string $idempotencyKey;
    private ?string $folder;

    /**
     * @param int|null $quality 1-100, clamped; null (default) omits it so the server picks the default for the mode and output format.
     * @param bool $lossless Deprecated: use $mode = CompressionMode::LOSSLESS. Removed not before the next major version.
     * @param string|null $mode One of the CompressionMode constants; null lets the server apply its default (lossy).
     * @param string|null $folder Upload folder, e.g. "blog/2026"; the server normalizes it to lower case.
     * @throws ValidationException when $mode is unknown or contradicts $lossless = true, or $folder is malformed
     */
    public function __construct(
        string $format = ImageFormat::AUTO,
        ?int $quality = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        string $fit = FitMode::COVER,
        bool $lossless = false,
        bool $stripMetadata = true,
        ?string $filename = null,
        ?string $mimeType = null,
        ?string $idempotencyKey = null,
        ?int $maxDimension = null,
        ?string $mode = null,
        ?string $folder = null
    ) {
        if ($folder !== null) {
            self::validateFolder($folder);
        }
        if ($mode !== null && !in_array($mode, CompressionMode::ALL, true)) {
            throw new ValidationException(
                sprintf('Invalid mode "%s"; expected one of: %s', $mode, implode(', ', CompressionMode::ALL)),
                null,
                ['mode' => $mode]
            );
        }
        if ($lossless) {
            trigger_error('OptimizeOptions lossless is deprecated; use mode CompressionMode::LOSSLESS instead.', E_USER_DEPRECATED);
            if ($mode !== null && $mode !== CompressionMode::LOSSLESS) {
                throw new ValidationException(
                    sprintf('lossless = true contradicts mode "%s"; set only mode', $mode),
                    null,
                    ['mode' => $mode]
                );
            }
            $mode = CompressionMode::LOSSLESS;
        }

        $this->format = $format;
        $this->quality = $quality === null ? null : max(1, min(100, $quality));
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
        $this->maxDimension = $maxDimension;
        $this->fit = $fit;
        $this->lossless = $lossless;
        $this->mode = $mode;
        $this->stripMetadata = $stripMetadata;
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->idempotencyKey = $idempotencyKey;
        $this->folder = $folder;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Quality sent on the wire; null when unset (the server picks the default per mode).
     */
    public function getQuality(): ?int
    {
        return $this->quality;
    }

    public function getMaxWidth(): ?int
    {
        return $this->maxWidth;
    }

    public function getMaxHeight(): ?int
    {
        return $this->maxHeight;
    }

    public function getMaxDimension(): ?int
    {
        return $this->maxDimension;
    }

    public function getFit(): string
    {
        return $this->fit;
    }

    /**
     * @deprecated Use getMode() === CompressionMode::LOSSLESS. Removed not before the next major version.
     */
    public function isLossless(): bool
    {
        return $this->lossless;
    }

    /**
     * Mode sent on the wire, after mapping the deprecated lossless flag; null when none was set.
     */
    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function shouldStripMetadata(): bool
    {
        return $this->stripMetadata;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getContentType(): ?string
    {
        return $this->mimeType;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getFolder(): ?string
    {
        return $this->folder;
    }

    /**
     * Light client-side check of an upload folder; the server applies the full rule (400 invalid_folder).
     *
     * @internal
     * @throws ValidationException on empty segments, ".." segments or more than 3 segments
     */
    public static function validateFolder(string $folder): void
    {
        $segments = explode('/', $folder);
        if (count($segments) > 3 || in_array('', $segments, true) || in_array('..', $segments, true)) {
            throw new ValidationException(
                sprintf('Invalid folder "%s"; expected 1-3 non-empty segments separated by "/", without ".."', $folder),
                null,
                ['folder' => $folder]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'format' => $this->format,
            'max_width' => $this->maxWidth,
            'max_height' => $this->maxHeight,
            'max_dimension' => $this->maxDimension,
            'fit' => $this->fit,
            'strip_metadata' => $this->stripMetadata,
        ];
        if ($this->quality !== null) {
            $data['quality'] = $this->quality;
        }
        // The deprecated lossless key never goes on the wire; it is mapped to mode in the constructor.
        if ($this->mode !== null) {
            $data['mode'] = $this->mode;
        }
        if ($this->folder !== null) {
            $data['folder'] = $this->folder;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            (string)($options['format'] ?? ImageFormat::AUTO),
            isset($options['quality']) ? (int)$options['quality'] : null,
            isset($options['max_width']) ? (int)$options['max_width'] : (isset($options['maxWidth']) ? (int)$options['maxWidth'] : null),
            isset($options['max_height']) ? (int)$options['max_height'] : (isset($options['maxHeight']) ? (int)$options['maxHeight'] : null),
            (string)($options['fit'] ?? FitMode::COVER),
            (bool)($options['lossless'] ?? false),
            (bool)($options['strip_metadata'] ?? ($options['stripMetadata'] ?? true)),
            isset($options['filename']) ? (string)$options['filename'] : null,
            isset($options['mime_type']) ? (string)$options['mime_type'] : (isset($options['mimeType']) ? (string)$options['mimeType'] : null),
            isset($options['idempotency_key']) ? (string)$options['idempotency_key'] : (isset($options['idempotencyKey']) ? (string)$options['idempotencyKey'] : null),
            isset($options['max_dimension']) ? (int)$options['max_dimension'] : (isset($options['maxDimension']) ? (int)$options['maxDimension'] : null),
            isset($options['mode']) ? (string)$options['mode'] : null,
            isset($options['folder']) ? (string)$options['folder'] : null
        );
    }
}
