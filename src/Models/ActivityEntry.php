<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix\Models;

use Illuminate\Database\Eloquent\Model;

final class ActivityEntry extends Model
{
    protected $table = 'activity_matrix_entries';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['id'];

    protected $hidden = ['details', 'event_key', 'scope'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['details' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
