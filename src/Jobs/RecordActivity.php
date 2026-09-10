<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use SimoneBianco\ActivityMatrix\Events\ActivityRecorded;
use SimoneBianco\ActivityMatrix\Events\ActivityRecordingFailed;
use SimoneBianco\ActivityMatrix\Models\ActivityEntry;
use SimoneBianco\ActivityMatrix\Models\ActivityScope;
use Throwable;

final class RecordActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly string $scope, public readonly string $eventKey,
        public readonly array $metadata, public readonly array $details) {}

    public function backoff(): array
    {
        return [1, 5, 15, 60];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            ActivityScope::query()->firstOrCreate(['scope' => $this->scope]);
            // Only the worker owns this lock. No source-domain locks are acquired here.
            ActivityScope::query()->whereKey($this->scope)->lockForUpdate()->firstOrFail();
            $entry = ActivityEntry::query()->firstOrCreate(['event_key' => $this->eventKey], [
                'scope' => $this->scope, ...$this->metadata, 'details' => $this->details,
            ]);
            if ($entry->wasRecentlyCreated) {
                DB::afterCommit(function () use ($entry): void {
                    try {
                        event(new ActivityRecorded($this->scope, (string) $entry->id));
                    } catch (Throwable $error) {
                        ActivityRecordingFailed::report('invalidation_enqueue', $error);
                    }
                });
            }
        }, 3);
    }

    public function failed(?Throwable $error): void
    {
        if ($error !== null) {
            ActivityRecordingFailed::report('worker', $error);
        }
    }
}
