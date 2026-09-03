<?php

namespace FyWolf\Arma3Manager\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $egg_id
 * @property int $a3_capability_profile_id
 */
class EggCapabilityProfile extends Pivot
{
    protected $table = 'egg_a3_capability_profile';

    public $timestamps = false;

    public $incrementing = false;
}
