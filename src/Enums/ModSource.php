<?php

namespace FyWolf\Arma3Manager\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a mod in the load order came from, which decides who can fetch it.
 *
 * This is not cosmetic. Only `Workshop` entries can be downloaded by SteamCMD,
 * so it is the flag that decides whether "Sync" has anything to do for a given
 * row. Marking a hand-uploaded folder as Workshop would queue a download for an
 * id that does not exist; marking a workshop item as Local would leave it in
 * the load order forever without ever fetching it, and the only symptom is
 * clients being kicked for a missing addon.
 */
enum ModSource: string implements HasColor, HasIcon, HasLabel
{
    case Workshop = 'workshop';
    case Local = 'local';
    case CreatorDlc = 'cdlc';

    public function getLabel(): string
    {
        return match ($this) {
            self::Workshop => 'Steam Workshop',
            self::Local => 'Uploaded',
            self::CreatorDlc => 'Creator DLC',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Workshop => 'tabler-brand-steam',
            self::Local => 'tabler-upload',
            self::CreatorDlc => 'tabler-license',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Workshop => 'info',
            self::Local => 'gray',
            self::CreatorDlc => 'warning',
        };
    }

    /**
     * Whether SteamCMD can fetch this on the customer's behalf.
     *
     * Creator DLC is owned, not downloaded: it arrives with the game files for
     * an account that owns it and cannot be pulled from the Workshop at all, so
     * a sync must skip it rather than fail on it.
     */
    public function isDownloadable(): bool
    {
        return $this === self::Workshop;
    }
}
