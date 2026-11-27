<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Data;

use JustinHoltWeb\Appleseed\Support\LinkStatus;

/**
 * The result of checking one URL. (`models\ScanResult` in Appleseed 5.)
 */
final readonly class CheckResult
{
    /**
     * @param  list<string>|null  $redirectChain
     */
    public function __construct(
        public string $url,
        public string $status,
        public ?int $statusCode = null,
        public ?string $redirectUrl = null,
        public ?array $redirectChain = null,
        public ?string $errorMessage = null,
    ) {}

    public function isWorking(): bool
    {
        return $this->status === LinkStatus::WORKING;
    }

    public function isBroken(): bool
    {
        return LinkStatus::isBroken($this->status);
    }

    public function isRedirect(): bool
    {
        return $this->status === LinkStatus::REDIRECT;
    }

    /**
     * @return array{url: string, status: string, isBroken: bool, statusCode: int|null, redirectUrl: string|null, redirectChain: list<string>, errorMessage: string|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'status' => $this->status,
            'isBroken' => $this->isBroken(),
            'statusCode' => $this->statusCode,
            'redirectUrl' => $this->redirectUrl,
            'redirectChain' => $this->redirectChain ?? [],
            'errorMessage' => $this->errorMessage,
        ];
    }
}
