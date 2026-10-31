<?php

declare(strict_types=1);

namespace SmallPict\Exceptions;

class PermissionDeniedException extends SmallPictException
{
    /**
     * @param string $message
     * @param string|null $requestId
     * @param array<string, mixed> $details
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = 'Permission denied for this endpoint scope',
        ?string $requestId = null,
        array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 'FORBIDDEN', 403, $requestId, $details, $previous);
    }

    /**
     * Modes the plan allows, from details.allowed_modes of mode_not_in_plan; otherwise null.
     *
     * @return string[]|null
     */
    public function getAllowedModes(): ?array
    {
        $modes = $this->details['allowed_modes'] ?? null;
        return is_array($modes) ? array_values(array_filter($modes, 'is_string')) : null;
    }
}
