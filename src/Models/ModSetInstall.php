<?php

namespace FyWolf\Arma3Manager\Models;

use App\Models\Server;
use App\Models\User;
use FyWolf\Arma3Manager\Enums\InstallState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to install a mod set onto one server.
 *
 * @property int $id
 * @property int $server_id
 * @property ?int $mod_set_id
 * @property string $mod_set_name
 * @property InstallState $state
 * @property ?string $error
 * @property ?int $resolved
 * @property ?int $total
 * @property ?array<int, string> $applied_mods
 * @property ?int $user_id
 * @property ?\Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $finished_at
 * @property ?\Illuminate\Support\Carbon $heartbeat_at
 */
class ModSetInstall extends Model
{
    protected $table = 'a3_mod_set_installs';

    protected $fillable = [
        'server_id',
        'mod_set_id',
        'mod_set_name',
        'state',
        'error',
        'resolved',
        'total',
        'applied_mods',
        'user_id',
        'started_at',
        'finished_at',
        'heartbeat_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => InstallState::class,
            'applied_mods' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('state', array_map(
            static fn (InstallState $state): string => $state->value,
            array_filter(InstallState::cases(), static fn (InstallState $state): bool => $state->isTerminal()),
        ));
    }

    /**
     * Record a state change and touch the heartbeat in one write.
     *
     * Always together, deliberately. A state advanced without a heartbeat looks
     * stalled to the reaper, which would then cancel a perfectly healthy
     * install — and the symptom is a set that stops installing halfway with no
     * error anybody wrote.
     */
    public function advance(InstallState $state, array $attributes = []): self
    {
        $this->forceFill($attributes + [
            'state' => $state,
            'heartbeat_at' => now(),
        ])->save();

        return $this;
    }

    public function progressPercent(): ?int
    {
        if (! $this->total) {
            return null;
        }

        return (int) round(min(100, max(0, ($this->resolved ?? 0) / $this->total * 100)));
    }
}
