<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix\Data;

final readonly class ActivityWidgetData implements \JsonSerializable
{
    public function __construct(
        public string $feedUrl,
        public string $detailUrlTemplate,
        public string $channel,
        public string $topic = 'activity-matrix',
        public int $pageSize = 240,
        public int $maxPageSize = 500,
    ) {}

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
