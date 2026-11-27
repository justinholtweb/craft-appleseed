<?php

namespace justinholtweb\appleseed\models;

/**
 * DTO for the result of checking a single URL.
 */
class ScanResult
{
    public function __construct(
        public readonly string $url,
        public readonly string $status,
        public readonly ?int $statusCode = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?array $redirectChain = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function isWorking(): bool
    {
        return $this->status === 'working';
    }

    public function isBroken(): bool
    {
        return \justinholtweb\appleseed\helpers\LinkStatus::isBroken($this->status);
    }

    public function isRedirect(): bool
    {
        return $this->status === 'redirect';
    }
}
