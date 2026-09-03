<?php

namespace FyWolf\Arma3Manager\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The stages of a mod set install, in order.
 *
 * The ordering is load-bearing, not cosmetic: `rank()` is what lets a resumed
 * install say "skip anything at or before where we got to" with one comparison,
 * rather than each stage having to know what the stages before it did.
 *
 * `AwaitingDownload` is the stage with no equivalent in minecraft-manager and
 * is the whole shape of this plugin. The panel does not fetch Workshop files —
 * it writes a manifest and asks the server's own container to run SteamCMD with
 * the customer's Steam account. So there is a stage where the panel has done
 * everything it can and is waiting on a machine it does not control, which is
 * neither "working" nor "finished".
 */
enum InstallState: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Resolving = 'resolving';
    case Writing = 'writing';
    case AwaitingDownload = 'awaiting_download';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Resolving => 'Resolving dependencies',
            self::Writing => 'Writing the load order',
            self::AwaitingDownload => 'Waiting for SteamCMD',
            self::Verifying => 'Checking what arrived',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            default => 'warning',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Resolving => 1,
            self::Writing => 2,
            self::AwaitingDownload => 3,
            self::Verifying => 4,
            default => 5,
        };
    }
}
