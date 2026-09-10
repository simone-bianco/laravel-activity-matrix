<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix\Events;

use Throwable;

final readonly class ActivityRecordingFailed
{
    public function __construct(public string $stage, public string $exceptionType) {}

    public static function report(string $stage, Throwable $error): void
    {
        try {
            event(new self($stage, str_contains($error::class, '@anonymous') ? 'anonymous Throwable' : substr($error::class, 0, 160)));
        } catch (Throwable) {
            // Monitoring diagnostics cannot change an authoritative source result.
        }
    }
}
