<?php

/**
 * Arma 3 Manager.
 *
 * Loaded by the panel as `config('arma3-manager.*')` — the filename must stay
 * equal to the plugin id (PluginService reads
 * `plugin_path($id, 'config', $id . '.php')`).
 *
 * Secrets come from the environment and are written there by the plugin's
 * settings slide-over, never edited here.
 */
return [
    /*
    |---------------------------------------------------------------------------
    | Steam Workshop
    |---------------------------------------------------------------------------
    |
    | Arma 3 is app 107410; the dedicated server is 233780. Workshop items are
    | published against the *client* app id, so every lookup here uses 107410.
    |
    | Two endpoints, and the difference between them is the whole reason mods
    | work at all without credentials:
    |
    |  - GetPublishedFileDetails (ISteamRemoteStorage) is an unauthenticated
    |    POST. It returns title, size, preview image, timestamps and — the part
    |    that matters — `children`, which is how an item declares its required
    |    items. That is the dependency graph, free.
    |  - QueryFiles (IPublishedFileService) is what powers *searching* the
    |    workshop, and it needs a Steam Web API key. Without one the browser
    |    falls back to "paste an id or a workshop URL", which still resolves
    |    fully through the unauthenticated endpoint. Nothing is broken without a
    |    key; there is simply no search box.
    |
    | Neither endpoint downloads anything. Files are fetched by SteamCMD inside
    | the customer's own container, using the Steam credentials already on their
    | egg — see `steamcmd` below.
    |
    */

    'workshop' => [
        'app_id' => (int) env('A3M_APP_ID', 107410),
        'server_app_id' => (int) env('A3M_SERVER_APP_ID', 233780),

        'remote_storage_url' => env(
            'A3M_WORKSHOP_URL',
            'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/',
        ),

        'query_url' => env(
            'A3M_WORKSHOP_QUERY_URL',
            'https://api.steampowered.com/IPublishedFileService/QueryFiles/v1/',
        ),

        // No key => SteamWorkshopClient::canSearch() is false => the search box
        // is not rendered and only id/URL resolution is offered. Requested at
        // steamcommunity.com/dev/apikey.
        'api_key' => env('STEAM_WEB_API_KEY', ''),

        // How many `children` levels to walk when resolving requirements. Mod
        // dependency chains in Arma are shallow in practice (CBA_A3 sits under
        // almost everything and depends on nothing), but a cycle would other-
        // wise walk forever — the resolver also tracks visited ids.
        'max_dependency_depth' => (int) env('A3M_MAX_DEPENDENCY_DEPTH', 4),

        // Refuse to queue a download larger than this. A handful of Arma mods
        // are genuinely enormous (RHS is ~10 GB the set) and a customer on a
        // small disk should be told before SteamCMD fills the volume, not
        // after. Zero disables the check.
        'max_item_bytes' => (int) env('A3M_MAX_ITEM_BYTES', 0),
    ],

    /*
    |---------------------------------------------------------------------------
    | SteamCMD
    |---------------------------------------------------------------------------
    |
    | The panel never holds Steam credentials. Workshop items are downloaded by
    | the *server's own* container, which already has STEAM_USER / STEAM_PASS on
    | its egg because that is how the game server installs itself.
    |
    | So a "download" here is: write the wanted mod list into a server variable
    | and a file the install script reads, then ask the panel to reinstall. The
    | egg's install script does the fetching, with the customer's own account
    | and the customer's own rate limit.
    |
    | This is why `mod_list_variables` is a list of candidate names rather than
    | one name: eggs disagree, and guessing wrong writes a variable nothing
    | reads — which fails completely silently, the mods simply never appear.
    |
    */

    'steamcmd' => [
        // Ordered candidates. The first that exists on the server wins.
        'mod_list_variables' => ['MODS', 'MODIFICATIONS', 'WORKSHOP_MODS', 'STEAM_WORKSHOP_MODS'],
        'servermod_list_variables' => ['SERVERMODS', 'SERVER_MODS', 'SERVERMODIFICATIONS'],

        // Written alongside the variable so an egg that reads a file rather
        // than an env var still works. Cheap insurance: writing both costs one
        // file write and removes an entire class of "it did nothing".
        'manifest_path' => env('A3M_MANIFEST_PATH', 'arma3-manager.mods'),

        // Whether changing the mod list may trigger a panel reinstall. Off by
        // default: a reinstall is destructive on some eggs, and an operator
        // should opt in per install rather than discover it.
        'reinstall_on_sync' => (bool) env('A3M_REINSTALL_ON_SYNC', false),

        /*
        | Where SteamCMD leaves Workshop items, relative to the server root.
        |
        | Ordered candidates, first one that exists wins — and the order is not
        | cosmetic. `Steam/steamapps/workshop` is the real answer for the stock
        | Arma 3 image and the one that was missing: the entrypoint declares
        |
        |     WORKSHOP_DIR="./Steam/steamapps/workshop"
        |
        | because `workshop_download_item` runs *without* `+force_install_dir`,
        | so SteamCMD falls back to its own default of `$HOME/Steam` — and HOME
        | is the server root. Only the *game* is installed to the root, by the
        | install script's `+force_install_dir /mnt/server`.
        |
        | `steamapps/workshop` (no `Steam/`) is kept second for eggs that do
        | force the install dir for mods too. It is what this plugin looked in
        | exclusively, and it does not exist on the stock image — which is why
        | every mod read as "Waiting" forever and the page reported the whole
        | load order missing. `listDirectories()` swallows the 404, so there was
        | no error anywhere: exactly the silent failure this codebase keeps
        | finding.
        */
        /*
        | The two files exchanged with the arma3-manager egg.
        |
        | Both are optional in both directions, and that is the point: a server
        | on the stock Arma 3 egg has neither, and every feature that reads them
        | falls back to probing directories. Nothing here may become required, or
        | the plugin stops working on the egg most people actually run.
        |
        |  - `status_path` is written by the egg and read here: per-mod state,
        |    percentage, resolved name and — the one no directory listing can
        |    ever provide — the reason a download failed.
        |  - `wanted_path` is written here and read by the egg: the names and
        |    expected sizes it needs to turn "bytes on disk" into a percentage.
        |    The panel has the Steam Web API; the container deliberately has no
        |    credentials beyond the server's own, so this is the only way those
        |    sizes reach it.
        */
        'status_path' => env('A3M_STATUS_PATH', '.arma3-manager/status.json'),
        'wanted_path' => env('A3M_WANTED_PATH', '.arma3-manager/wanted.json'),

        // Written here and watched by the egg's sync daemon: "download these,
        // now, without restarting". The panel still transfers nothing and still
        // holds no Steam credentials — this is a request, and the container's
        // own account does the work.
        'request_path' => env('A3M_REQUEST_PATH', '.arma3-manager/request.json'),

        'workshop_roots' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'A3M_WORKSHOP_ROOTS',
                'Steam/steamapps/workshop,steamapps/workshop',
            )),
        ))),
    ],

    'http' => [
        'timeout' => (int) env('A3M_HTTP_TIMEOUT', 8),
        'connect_timeout' => (int) env('A3M_HTTP_CONNECT_TIMEOUT', 4),
        'retries' => (int) env('A3M_HTTP_RETRIES', 2),
    ],

    'cache' => [
        'search' => 900,       // 15 min
        'item' => 3600,        // 1 h — workshop metadata changes on publish
        'immutable' => 86400,  // 24 h — anything addressed by an immutable id
        'unavailable' => 60,   // negative cache so a dead API isn't re-probed per request
        'directory' => 30,     // daemon directory listings
    ],

    /*
    |---------------------------------------------------------------------------
    | Capability resolution
    |---------------------------------------------------------------------------
    |
    | Resolution order is: explicit egg mapping -> the parent egg's mapping
    | (config_from) -> heuristic -> null. A null result hides every page; an
    | unknown custom egg is invisible rather than half-broken.
    |
    */

    'heuristics' => [
        'enabled' => (bool) env('A3M_HEURISTICS', true),

        // An egg qualifies as Arma 3 if it carries one of these tags, names
        // Arma in its own name, or declares the Arma 3 dedicated-server app id
        // in a variable. Unlike Minecraft there is no useful *feature* marker:
        // the panel registers `steam_disk_space`, which every SteamCMD game
        // carries, so it identifies Steam rather than Arma and is deliberately
        // not used on its own.
        'tags' => ['arma', 'arma3', 'arma 3', 'bohemia'],

        // The strong signal. 233780 is the Arma 3 dedicated server on Steam;
        // an egg carrying it is Arma 3 whatever it calls itself.
        'app_id_variables' => ['SRCDS_APPID', 'STEAM_APPID', 'APPID', 'GAME_ID'],
        'app_ids' => [233780, 107410],

        // Ordered most-specific first, as in minecraft-manager. `headless`
        // must precede the generic arma tokens, because a headless-client egg
        // is also an Arma egg and would otherwise resolve to the full profile
        // and offer a missions page for a container that hosts no mission.
        'flavour_tokens' => [
            'arma3-headless' => ['headless', 'headlessclient', 'hc'],
            'arma3' => ['arma3', 'arma', 'a3'],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Built-in capability profiles
    |---------------------------------------------------------------------------
    |
    | Seeded into `a3_capability_profiles` on install, and used directly
    | (without a DB row) when the heuristic resolves an egg that has no explicit
    | mapping. Heuristic results are deliberately never persisted — writing rows
    | behind the admin's back would fight them the next time they edit one.
    |
    | capabilities: mods, servermods, missions, configs, presets, parameters,
    |               modsets
    | mods_dir:     where @Mod folders live; null = no mod management at all
    | missions_dir: where .pbo missions live; null = no missions page
    | config_files: the files the Configuration page offers, in order
    |
    */

    'profiles' => [
        'arma3' => [
            'name' => 'Arma 3 Dedicated Server',
            'flavour' => 'arma3',
            'capabilities' => ['mods', 'servermods', 'missions', 'configs', 'presets', 'parameters', 'modsets'],
            'mods_dir' => 'mods',
            'servermods_dir' => 'servermods',
            'missions_dir' => 'mpmissions',
            'profiles_dir' => 'profiles',
            'server_binary' => 'arma3server_x64',
            'config_files' => ['server.cfg', 'basic.cfg'],
            'mod_list_variables' => ['MODS', 'MODIFICATIONS'],
            'servermod_list_variables' => ['SERVERMODS', 'SERVER_MODS'],
            'parameter_variables' => ['STARTUP_PARAMS', 'EXTRA_FLAGS', 'ARMA_PARAMS'],
            'headless_variables' => ['HC_NUM', 'HEADLESS_CLIENTS'],
        ],

        'arma3-headless' => [
            'name' => 'Arma 3 Headless Client',
            'flavour' => 'arma3-headless',
            // No missions and no server.cfg: a headless client connects to a
            // server, it does not host one. It still needs the identical mod
            // list, which is the whole reason it gets a page at all — a
            // headless client whose mods differ from the server's is refused
            // at connect with a signature mismatch.
            'capabilities' => ['mods', 'presets', 'parameters'],
            'mods_dir' => 'mods',
            'servermods_dir' => null,
            'missions_dir' => null,
            'profiles_dir' => 'profiles',
            'server_binary' => 'arma3server_x64',
            'config_files' => [],
            'mod_list_variables' => ['MODS', 'MODIFICATIONS'],
            'servermod_list_variables' => [],
            'parameter_variables' => ['STARTUP_PARAMS', 'EXTRA_FLAGS'],
            'headless_variables' => [],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Configuration editing
    |---------------------------------------------------------------------------
    |
    | The typed schema below is what the Configuration page renders as a form.
    | Anything present in the file and absent from here is NOT dropped — it
    | appears in a collapsed "Other settings" section as plain text and round
    | trips byte-for-byte if untouched.
    |
    | type:             string | int | float | bool01 | enum | array
    | group:            which section it renders under
    | sensitive:        never echoed back to the browser; blank means unchanged
    | managed_by_panel: the panel owns it; locked for everyone, always
    |
    | `bool01` rather than `bool`: Arma writes 0/1 integers, not true/false, and
    | writing `true` into server.cfg is not a value Arma understands — it parses
    | as 0, silently disabling whatever was meant to be on.
    |
    */

    'configs' => [
        // Properties customers may see but not change. maxPlayers is the usual
        // case: on a host that sells slots the player limit belongs to the
        // order, not to the customer's config file.
        'locked_properties' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('A3M_LOCKED_PROPERTIES', 'maxPlayers')),
        ))),

        'locked_reason' => env('A3M_LOCKED_REASON', 'Set by your plan — contact support to change it.'),

        'server_cfg_schema' => [
            // --- Identity ---------------------------------------------------
            'hostname' => ['type' => 'string', 'group' => 'identity', 'helper' => 'The name shown in the server browser.'],
            'password' => ['type' => 'string', 'group' => 'identity', 'sensitive' => true, 'helper' => 'Required to join. Blank means a public server.'],
            'passwordAdmin' => ['type' => 'string', 'group' => 'identity', 'sensitive' => true, 'helper' => 'Used with #login in chat to become admin. Never leave this blank on a public server.'],
            'serverCommandPassword' => ['type' => 'string', 'group' => 'identity', 'sensitive' => true, 'helper' => 'Lets a client run server commands without full admin. Rarely needed; blank disables it.'],
            'maxPlayers' => ['type' => 'int', 'group' => 'identity', 'min' => 1, 'max' => 256, 'helper' => 'Slots, counting headless clients. Every slot costs memory whether or not it is filled.'],
            'motd' => ['type' => 'array', 'group' => 'identity', 'helper' => 'One line per entry, shown to players after they join.'],
            'motdInterval' => ['type' => 'int', 'group' => 'identity', 'min' => 0, 'helper' => 'Seconds between MOTD lines. 0 prints them all at once.'],
            'admins' => ['type' => 'array', 'group' => 'identity', 'helper' => 'Steam64 IDs given admin without needing #login. One per entry.'],

            // --- Security ---------------------------------------------------
            'verifySignatures' => ['type' => 'enum', 'group' => 'security', 'options' => ['0', '2'], 'helper' => '2 checks every loaded addon against its .bikey. Anything else lets modified addons connect — use 2 on any public server.'],
            'BattlEye' => ['type' => 'bool01', 'group' => 'security', 'helper' => 'Enables BattlEye anti-cheat. Off means no anti-cheat at all.'],
            'requiredSecureId' => ['type' => 'int', 'group' => 'security', 'min' => 0, 'helper' => 'Carried over from Arma 2 and ignored by Arma 3. Left here only so an existing value survives a save.'],
            'allowedFilePatching' => ['type' => 'enum', 'group' => 'security', 'options' => ['0', '1', '2'], 'helper' => '0 refuses clients using -filePatching, 1 allows headless clients only, 2 allows everyone. 0 or 1 on a public server.'],
            'kickDuplicate' => ['type' => 'bool01', 'group' => 'security', 'helper' => 'Kick a player whose Steam ID is already connected. Normally on.'],
            'filePatchingExceptions' => ['type' => 'array', 'group' => 'security', 'helper' => 'Steam64 IDs allowed to use -filePatching even when it is otherwise refused. Usually your headless clients.'],
            'allowedLoadFileExtensions' => ['type' => 'array', 'group' => 'security', 'helper' => 'Extensions a mission script may pass to loadFile. Defaults are hpp, sqs, sqf, fsm, cpp, paa, txt, xml, inc, ext, sqm, ods, fxy, lip, csv, kb, bik, bikb, html, htm, biedi.'],
            'allowedPreprocessFileExtensions' => ['type' => 'array', 'group' => 'security', 'helper' => 'Extensions allowed with preprocessFile. Same default list as loadFile.'],
            'allowedHTMLLoadExtensions' => ['type' => 'array', 'group' => 'security', 'helper' => 'Extensions an HTML control may load. Defaults to htm, html, xml, txt.'],

            // --- Mission ----------------------------------------------------
            'persistent' => ['type' => 'bool01', 'group' => 'mission', 'helper' => 'Keeps the mission running after the last player leaves, instead of resetting it.'],
            'autoSelectMission' => ['type' => 'bool01', 'group' => 'mission', 'helper' => 'Start the next mission in the rotation automatically rather than waiting at the lobby.'],
            'randomMissionOrder' => ['type' => 'bool01', 'group' => 'mission', 'helper' => 'Pick the next mission at random instead of following the rotation order.'],
            'missionsToServerRestart' => ['type' => 'int', 'group' => 'mission', 'min' => 0, 'helper' => 'Restart the server process after this many missions. 0 never restarts. A low value is a cheap way to shed a memory leak.'],
            'missionWhitelist' => ['type' => 'array', 'group' => 'mission', 'helper' => 'Mission filenames a voting player may select. Empty allows any mission on the server.'],
            'forcedDifficulty' => ['type' => 'string', 'group' => 'mission', 'helper' => 'A difficulty class name from the server profile — recruit, regular, veteran or custom. Overrides whatever the mission asks for.'],
            'enableDebugConsole' => ['type' => 'enum', 'group' => 'mission', 'options' => ['0', '1', '2'], 'helper' => '0 nobody, 1 logged-in admins, 2 everyone. Anything above 0 lets that group run arbitrary script on your server.'],
            'zeusCompositionScriptLevel' => ['type' => 'int', 'group' => 'mission', 'min' => 0, 'max' => 2, 'helper' => 'How much scripting a Zeus-placed composition may carry. 0 none, 1 official only, 2 any.'],

            // --- Voting -----------------------------------------------------
            'voteThreshold' => ['type' => 'float', 'group' => 'voting', 'helper' => 'Fraction of connected players needed to pass a vote. 0.33 is a third; anything above 1.0 disables voting entirely.'],
            'voteMissionPlayers' => ['type' => 'int', 'group' => 'voting', 'min' => 1, 'helper' => 'How many players must be connected before mission voting is offered.'],
            'allowedVoteCmds' => ['type' => 'array', 'group' => 'voting', 'helper' => 'Which commands may be voted on — admin, kick, mission, missions, restart, reassign. Empty allows all of them.'],

            // --- Network ----------------------------------------------------
            'disableVoN' => ['type' => 'bool01', 'group' => 'network', 'helper' => 'Turns off in-game voice entirely. On when your community uses TeamSpeak or Discord instead.'],
            'vonCodecQuality' => ['type' => 'int', 'group' => 'network', 'min' => 0, 'max' => 30, 'helper' => 'Voice quality, 0 to 30. Higher is clearer and costs more bandwidth; 8 to 12 is the usual compromise.'],
            'vonCodec' => ['type' => 'enum', 'group' => 'network', 'options' => ['0', '1'], 'helper' => '0 is the legacy Speex codec, 1 is Opus. Use 1 unless something old needs otherwise.'],
            'disconnectTimeout' => ['type' => 'int', 'group' => 'network', 'min' => 5, 'max' => 90, 'helper' => 'Seconds a frozen client is held before being dropped, so a brief stall is not a disconnect. 5 to 90.'],
            'maxdesync' => ['type' => 'int', 'group' => 'network', 'min' => 0, 'helper' => 'Desync above this kicks the client, if kickClientsOnSlowNetwork is on.'],
            'maxping' => ['type' => 'int', 'group' => 'network', 'min' => 0, 'helper' => 'Ping above this kicks the client, if kickClientsOnSlowNetwork is on.'],
            'maxpacketloss' => ['type' => 'int', 'group' => 'network', 'min' => 0, 'helper' => 'Packet loss above this kicks the client, if kickClientsOnSlowNetwork is on.'],
            'kickClientsOnSlowNetwork' => ['type' => 'bool01', 'group' => 'network', 'helper' => 'Whether the three limits above actually kick. Off makes them advisory only.'],
            'lobbyIdleTimeout' => ['type' => 'int', 'group' => 'network', 'min' => 0, 'helper' => 'Seconds a player may sit in the lobby before being dropped. 0 never drops them.'],
            'steamProtocolMaxDataSize' => ['type' => 'int', 'group' => 'network', 'min' => 0, 'helper' => 'Size limit for the server browser reply. Raise it (1024 upward) when a long mod list stops the server appearing in the browser.'],
            'upnp' => ['type' => 'bool01', 'group' => 'network', 'helper' => 'Asks the router to forward the ports. Leave off on a hosted server — the ports are already forwarded and this only delays startup.'],
            'loopback' => ['type' => 'bool01', 'group' => 'network', 'helper' => 'Forces the server into LAN mode. Off unless you are deliberately testing offline.'],

            // --- Logging ----------------------------------------------------
            'logFile' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Filename for the server log, written next to the server profile.'],
            'timeStampFormat' => ['type' => 'enum', 'group' => 'logging', 'options' => ['none', 'short', 'full'], 'helper' => 'Timestamp on each log line. `full` is worth the noise when reading a crash after the fact.'],
            'onUserConnected' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Server command run when a player connects. Left blank on most servers.'],
            'onUserDisconnected' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Server command run when a player disconnects.'],
            'doubleIdDetected' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Server command run when two clients present the same ID.'],
            'onUnsignedData' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Run when a client carries unsigned data. `kick (_this select 0)` is the usual value.'],
            'onHackedData' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Run when a client carries data with a bad signature. Usually a kick or ban.'],
            'onDifferentData' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Run when a client carries a signed file the server does not have.'],
            'regularCheck' => ['type' => 'string', 'group' => 'logging', 'helper' => 'Periodic file re-check. Deprecated and ignored by current builds; kept so an existing value is not dropped.'],
        ],

        'basic_cfg_schema' => [
            'MaxMsgSend' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 1, 'helper' => 'Messages sent per simulation cycle. 128 is the usual dedicated value; raising it costs CPU, lowering it raises desync.'],
            'MaxSizeGuaranteed' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 1, 'helper' => 'Bytes per guaranteed message — the ones that must arrive, such as player commands. 512 is standard.'],
            'MaxSizeNonguaranteed' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 1, 'helper' => 'Bytes per non-guaranteed message, such as object positions. 256 is standard; too high makes a lagging client worse.'],
            'MinBandwidth' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 0, 'helper' => 'Bits per second the server always assumes is available. 131072 is the common floor.'],
            'MaxBandwidth' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 0, 'helper' => 'Ceiling in bits per second. Leave unset unless the host caps you.'],
            'MinErrorToSend' => ['type' => 'float', 'group' => 'bandwidth', 'helper' => 'How wrong a distant object must look before an update is sent. Lower is smoother and more traffic; 0.001 is typical.'],
            'MinErrorToSendNear' => ['type' => 'float', 'group' => 'bandwidth', 'helper' => 'The same, for objects close to the player. 0.01 is typical.'],
            'MaxCustomFileSize' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 0, 'helper' => 'Largest custom face or sound a player may upload, in bytes. 0 means no limit.'],
            'terrainGrid' => ['type' => 'float', 'group' => 'simulation', 'helper' => 'Server-side terrain detail in metres. Lower is more detailed and much more CPU; 25 or 50 is normal for a server.'],
            'viewDistance' => ['type' => 'int', 'group' => 'simulation', 'min' => 0, 'helper' => 'Server-side view distance in metres. Mostly governs AI awareness; higher costs CPU.'],
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | Missions
    |---------------------------------------------------------------------------
    */

    'missions' => [
        // Arma loads missions from .pbo archives. A folder is only loaded by a
        // *client* running -filePatching, never by a dedicated server, so an
        // upload that is not a .pbo is refused with an explanation rather than
        // accepted and silently ignored.
        'extensions' => ['pbo'],

        // Bytes. Wings streams the upload, but a mission larger than this is
        // almost always someone uploading the wrong file.
        'max_upload_bytes' => (int) env('A3M_MAX_MISSION_BYTES', 512 * 1024 * 1024),
    ],

    /*
    |---------------------------------------------------------------------------
    | Mod sets
    |---------------------------------------------------------------------------
    |
    | The admin-curated catalogue: a named collection of workshop ids a customer
    | can install in one action, optionally granted per server by the billing
    | service through this plugin's API.
    |
    */

    'modsets' => [
        // A mod set install resolves dependencies and rewrites the load order,
        // which on a large set is minutes of work. On the default queue that
        // blocks every other panel job — backups, webhooks, SFTP revocation.
        'queue' => env('A3M_MODSETS_QUEUE', 'default'),

        // An install that has not moved in this long is presumed dead and is
        // reaped, so a worker restarted mid-install cannot lock a server out of
        // further installs forever.
        'stale_after_minutes' => (int) env('A3M_STALE_INSTALL_MINUTES', 60),
    ],

    /*
    |---------------------------------------------------------------------------
    | Startup parameters
    |---------------------------------------------------------------------------
    |
    | The Parameters page writes these into the server's startup variable. Flags
    | the panel owns are listed as managed so they can be shown and never
    | edited — `-port` in particular, which belongs to the allocation.
    |
    */

    'parameters' => [
        'managed' => ['port', 'config', 'cfg', 'profiles', 'name', 'mod', 'serverMod'],

        'known_flags' => [
            'autoInit' => ['type' => 'bool', 'helper' => 'Start the first mission automatically when persistence is on.'],
            'filePatching' => ['type' => 'bool', 'helper' => 'Allow loading unpacked data. Refuse this on a public server.'],
            'netlog' => ['type' => 'bool', 'helper' => 'Log network traffic.'],
            'hugepages' => ['type' => 'bool'],
            'enableHT' => ['type' => 'bool', 'helper' => 'Use logical (hyper-threaded) cores.'],
            'loadMissionToMemory' => ['type' => 'bool'],
            'cpuCount' => ['type' => 'int', 'min' => 1, 'max' => 32],
            'maxMem' => ['type' => 'int', 'min' => 256, 'max' => 32768, 'helper' => 'Megabytes. Arma ignores values above the engine limit.'],
            'malloc' => ['type' => 'string'],
            'world' => ['type' => 'string', 'helper' => 'Set to `empty` to skip loading a terrain at boot.'],
            'checkSignatures' => ['type' => 'bool'],
            'limitFPS' => ['type' => 'int', 'min' => 5, 'max' => 1000],
        ],

        // Creator DLC. Loaded through -mod= like any other addon, but they are
        // owned rather than downloaded, so they are offered as toggles instead
        // of workshop items.
        'creator_dlc' => [
            'gm' => 'Global Mobilization — Cold War Germany',
            'vn' => 'S.O.G. Prairie Fire',
            'csla' => 'CSLA Iron Curtain',
            'ws' => 'Western Sahara',
            'spe' => 'Spearhead 1944',
            'rf' => 'Reaction Forces',
            'ef' => 'Expeditionary Forces',
        ],
    ],
];
