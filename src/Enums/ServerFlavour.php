<?php

namespace FyWolf\Arma3Manager\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of Arma 3 process this egg runs.
 *
 * Deliberately short. Arma 3 has nothing resembling Minecraft's loader zoo —
 * there is one dedicated server binary and one engine — so the only distinction
 * that changes what a page may do is whether the process *hosts* a mission or
 * *joins* one.
 *
 * That distinction is real and gets missed: a headless client is an Arma 3 egg
 * by every tag and every app id, but it has no server.cfg, no mpmissions and no
 * server-only mods. Resolving one to the full profile offers a Missions page
 * for a container that will never host a mission, and a Configuration page
 * pointed at a file that does not exist.
 *
 * Anything else — a mission framework like Antistasi or Exile, a modded
 * community build — is still one of these two as far as *file layout* goes, and
 * differs only in directories. That is what the profile table is for, and why
 * this enum does not try to enumerate them.
 */
enum ServerFlavour: string implements HasLabel
{
    case Arma3 = 'arma3';
    case Headless = 'arma3-headless';

    public function getLabel(): string
    {
        return match ($this) {
            self::Arma3 => 'Arma 3 Dedicated Server',
            self::Headless => 'Arma 3 Headless Client',
        };
    }

    /**
     * Whether this process hosts a mission.
     *
     * The single question that separates the two: a host reads server.cfg,
     * rotates mpmissions and can load server-only addons. A headless client
     * does none of those and only has to agree with the host about `-mod=`.
     */
    public function hosts(): bool
    {
        return $this === self::Arma3;
    }

    /**
     * The default capability set for this flavour.
     *
     * Mirrors `config('arma3-manager.profiles.*.capabilities')` and exists so a
     * profile created by hand in the admin UI starts from something sensible
     * rather than from nothing.
     *
     * @return array<int, string>
     */
    public function defaultCapabilities(): array
    {
        return $this->hosts()
            ? [
                Capability::Mods->value,
                Capability::ServerMods->value,
                Capability::Missions->value,
                Capability::Configs->value,
                Capability::Presets->value,
                Capability::Parameters->value,
                Capability::ModSets->value,
            ]
            : [
                Capability::Mods->value,
                Capability::Presets->value,
                Capability::Parameters->value,
            ];
    }
}
