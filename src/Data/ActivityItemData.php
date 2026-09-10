<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix\Data;

use SimoneBianco\ActivityMatrix\Models\ActivityEntry;

final readonly class ActivityItemData implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public ?string $actor_id,
        public string $actor_label,
        public string $operation,
        public string $category,
        public string $status,
        public string $summary,
        public string $occurred_at,
        public ?string $correlation_id,
    ) {}

    public static function fromEntry(ActivityEntry $entry): self
    {
        return new self((string) $entry->id, $entry->actor_id, $entry->actor_label, $entry->operation,
            $entry->category, $entry->status, $entry->summary, $entry->occurred_at->toISOString(), $entry->correlation_id);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
