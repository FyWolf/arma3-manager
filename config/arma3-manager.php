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
            'passwordAdmin' => ['type' => 'string', 'group' => 'identity', 'sensitive' => true, 'helper' => 'Used with #login. Never leave this blank on a public server.'],
            'serverCommandPassword' => ['type' => 'string', 'group' => 'identity', 'sensitive' => true],
            'maxPlayers' => ['type' => 'int', 'group' => 'identity', 'min' => 1, 'max' => 256],
            'motd' => ['type' => 'array', 'group' => 'identity', 'helper' => 'One line per entry, shown on join.'],
            'motdInterval' => ['type' => 'int', 'group' => 'identity', 'min' => 0],
            'admins' => ['type' => 'array', 'group' => 'identity', 'helper' => 'Steam64 IDs granted permanent admin.'],

            // --- Security ---------------------------------------------------
            'verifySignatures' => ['type' => 'enum', 'group' => 'security', 'options' => ['0', '2'], 'helper' => '2 checks every loaded addon against its .bikey. Anything other than 2 lets modified addons connect.'],
            'BattlEye' => ['type' => 'bool01', 'group' => 'security'],
            'requiredSecureId' => ['type' => 'int', 'group' => 'security', 'min' => 0],
            'allowedFilePatching' => ['type' => 'enum', 'group' => 'security', 'options' => ['0', '1', '2'], 'helper' => '0 refuses clients using -filePatching. 2 allows everyone.'],
            'kickDuplicate' => ['type' => 'bool01', 'group' => 'security'],
            'filePatchingExceptions' => ['type' => 'array', 'group' => 'security'],
            'allowedLoadFileExtensions' => ['type' => 'array', 'group' => 'security'],
            'allowedPreprocessFileExtensions' => ['type' => 'array', 'group' => 'security'],
            'allowedHTMLLoadExtensions' => ['type' => 'array', 'group' => 'security'],

            // --- Mission ----------------------------------------------------
            'persistent' => ['type' => 'bool01', 'group' => 'mission'],
            'autoSelectMission' => ['type' => 'bool01', 'group' => 'mission'],
            'randomMissionOrder' => ['type' => 'bool01', 'group' => 'mission'],
            'missionsToServerRestart' => ['type' => 'int', 'group' => 'mission', 'min' => 0],
            'missionWhitelist' => ['type' => 'array', 'group' => 'mission'],
            'forcedDifficulty' => ['type' => 'string', 'group' => 'mission', 'helper' => 'A difficulty class name from the server profile — recruit, regular, veteran, custom.'],
            'enableDebugConsole' => ['type' => 'enum', 'group' => 'mission', 'options' => ['0', '1', '2']],
            'zeusCompositionScriptLevel' => ['type' => 'int', 'group' => 'mission', 'min' => 0, 'max' => 2],

            // --- Voting -----------------------------------------------------
            'voteThreshold' => ['type' => 'float', 'group' => 'voting', 'helper' => 'Fraction of players needed. Above 1.0 disables voting.'],
            'voteMissionPlayers' => ['type' => 'int', 'group' => 'voting', 'min' => 1],
            'allowedVoteCmds' => ['type' => 'array', 'group' => 'voting'],

            // --- Network ----------------------------------------------------
            'disableVoN' => ['type' => 'bool01', 'group' => 'network'],
            'vonCodecQuality' => ['type' => 'int', 'group' => 'network', 'min' => 0, 'max' => 30],
            'vonCodec' => ['type' => 'enum', 'group' => 'network', 'options' => ['0', '1']],
            'disconnectTimeout' => ['type' => 'int', 'group' => 'network', 'min' => 5, 'max' => 90],
            'maxdesync' => ['type' => 'int', 'group' => 'network', 'min' => 0],
            'maxping' => ['type' => 'int', 'group' => 'network', 'min' => 0],
            'maxpacketloss' => ['type' => 'int', 'group' => 'network', 'min' => 0],
            'kickClientsOnSlowNetwork' => ['type' => 'bool01', 'group' => 'network'],
            'lobbyIdleTimeout' => ['type' => 'int', 'group' => 'network', 'min' => 0],
            'steamProtocolMaxDataSize' => ['type' => 'int', 'group' => 'network', 'min' => 0],
            'upnp' => ['type' => 'bool01', 'group' => 'network'],
            'loopback' => ['type' => 'bool01', 'group' => 'network'],

            // --- Logging ----------------------------------------------------
            'logFile' => ['type' => 'string', 'group' => 'logging'],
            'timeStampFormat' => ['type' => 'enum', 'group' => 'logging', 'options' => ['none', 'short', 'full']],
            'onUserConnected' => ['type' => 'string', 'group' => 'logging'],
            'onUserDisconnected' => ['type' => 'string', 'group' => 'logging'],
            'doubleIdDetected' => ['type' => 'string', 'group' => 'logging'],
            'onUnsignedData' => ['type' => 'string', 'group' => 'logging'],
            'onHackedData' => ['type' => 'string', 'group' => 'logging'],
            'onDifferentData' => ['type' => 'string', 'group' => 'logging'],
            'regularCheck' => ['type' => 'string', 'group' => 'logging'],
        ],

        'basic_cfg_schema' => [
            'MaxMsgSend' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 1, 'helper' => 'Messages sent per simulation cycle. 128 is the usual dedicated value.'],
            'MaxSizeGuaranteed' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 1],
            'MaxSizeNonguaranteed' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 1],
            'MinBandwidth' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 0],
            'MaxBandwidth' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 0],
            'MinErrorToSend' => ['type' => 'float', 'group' => 'bandwidth'],
            'MinErrorToSendNear' => ['type' => 'float', 'group' => 'bandwidth'],
            'MaxCustomFileSize' => ['type' => 'int', 'group' => 'bandwidth', 'min' => 0],
            'terrainGrid' => ['type' => 'float', 'group' => 'simulation'],
            'viewDistance' => ['type' => 'int', 'group' => 'simulation', 'min' => 0],
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
