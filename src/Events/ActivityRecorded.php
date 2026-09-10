<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix\Events;

final readonly class ActivityRecorded
{
    public function __construct(public string $scope, public string $id) {}
}
