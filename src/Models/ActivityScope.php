<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix\Models;

use Illuminate\Database\Eloquent\Model;

final class ActivityScope extends Model
{
    protected $table = 'activity_matrix_scopes';

    protected $primaryKey = 'scope';

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;
}
