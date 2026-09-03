<?php

namespace FyWolf\Arma3Manager\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mod set granted to one server by the billing service.
 *
 * @property int $id
 * @property int $server_id
 * @property int $mod_set_id
 * @property ?string $source
 * @property ?\Illuminate\Support\Carbon $granted_at
 */
class ServerModSet extends Model
{
    protected $table = 'a3_server_mod_sets';

    protected $fillable = [
        'server_id',
        'mod_set_id',
        'source',
        'granted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function modSet(): BelongsTo
    {
        return $this->belongsTo(ModSet::class, 'mod_set_id');
    }
}
