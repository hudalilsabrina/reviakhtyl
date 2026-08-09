<?php

namespace App\Services\Security;

enum ScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';
    case Error = 'error';
}

class FileScanResult
{
    public function __construct(
        public ScanVerdict $verdict,
        public ?string $signature = null,
        public ?string $message = null,
    ) {}

    public function isClean(): bool
    {
        return $this->verdict === ScanVerdict::Clean;
    }

    public function isInfected(): bool
    {
        return $this->verdict === ScanVerdict::Infected;
    }

    public function isSkipped(): bool
    {
        return $this->verdict === ScanVerdict::Skipped;
    }

    public function isError(): bool
    {
        return $this->verdict === ScanVerdict::Error;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function verdict(): string
    {
        return $this->verdict->value;
    }
}
