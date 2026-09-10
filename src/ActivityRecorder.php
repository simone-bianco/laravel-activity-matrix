<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use SimoneBianco\ActivityMatrix\Events\ActivityRecordingFailed;
use SimoneBianco\ActivityMatrix\Jobs\RecordActivity;
use Throwable;

final readonly class ActivityRecorder
{
    public function __construct(private ActivitySanitizer $sanitizer, private Dispatcher $dispatcher) {}

    /** Capture now; enqueue after source commit. Monitoring failures never undo source outcomes. */
    public function record(string $scope, string $eventKey, array $metadata, array $details = []): void
    {
        foreach (['scope' => $scope, 'actor_id' => $metadata['actor_id'] ?? null, 'correlation_id' => $metadata['correlation_id'] ?? null] as $field => $reference) {
            if ($reference !== null && (! is_string($reference) || ! mb_check_encoding($reference, 'UTF-8')
                || mb_strlen($reference, 'UTF-8') > 100 || $this->sanitizer->text($reference, 100) !== $reference)) {
                throw new InvalidArgumentException('Activity '.$field.' must be a safe UTF-8 reference of at most 100 characters.');
            }
        }
        $safe = [
            'actor_id' => $metadata['actor_id'] ?? null,
            'actor_label' => $this->sanitizer->text($metadata['actor_label'] ?? 'System', 128),
            'operation' => $this->sanitizer->text($metadata['operation'], 128),
            'category' => $this->sanitizer->text($metadata['category'], 40),
            'status' => $this->sanitizer->text($metadata['status'], 80),
            'summary' => $this->sanitizer->text($metadata['summary']),
            'correlation_id' => $metadata['correlation_id'] ?? null,
            'occurred_at' => Carbon::parse($metadata['occurred_at'] ?? now())->toISOString(),
        ];
        $job = new RecordActivity($scope, hash('sha256', $scope."\0".$eventKey), $safe, $this->sanitizer->details($details));
        try {
            DB::afterCommit(function () use ($job): void {
                try {
                    $connection = config('activity-matrix.connection', config('queue.default'));
                    if (config('queue.connections.'.$connection.'.driver') !== 'database') {
                        throw new InvalidArgumentException('Activity recording requires a same-connection durable database queue.');
                    }
                    $queue = Queue::connection($connection);
                    if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== DB::connection()) {
                        throw new InvalidArgumentException('Activity queue must use the source database connection.');
                    }
                    $job->onConnection($connection)->onQueue(config('activity-matrix.queue', 'default'))->beforeCommit();
                    // The source has committed. This separate enqueue transaction writes only a jobs row.
                    // beforeCommit applies to this enqueue transaction, never the source transaction.
                    DB::transaction(fn () => $this->dispatcher->dispatch($job));
                } catch (Throwable $error) {
                    ActivityRecordingFailed::report('enqueue', $error);
                }
            });
        } catch (Throwable $error) {
            ActivityRecordingFailed::report('enqueue', $error);
        }
    }
}
