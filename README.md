# Arma 3 Manager

Workshop mods, load order, missions, launcher presets and server configuration for Arma 3
servers on Pelican Panel — with every feature gated by the server's egg.

A dedicated server gets mods, the Workshop browser, missions, configuration, presets and
parameters. A headless client gets mods, presets and parameters, and **no**
Missions or Configuration page — it joins a mission, it does not host one. An egg nobody has
mapped shows nothing rather than a broken page.

**Author:** FyWolf · **Requires:** Pelican Panel · Filament v4 · PHP 8.2+

## Status

Feature-complete and **not yet tested against a live panel.** Every page is written, every
`use` resolves against a real panel's autoloader, and the parsers have 402 passing
assertions — but nothing here has been exercised against a running Wings daemon or a real
Arma 3 egg. Treat the first install as a shakedown, on a server you do not mind breaking.

The download reporting has a companion egg,
[`arma3-manager-egg`](https://github.com/FyWolf/arma3-manager-egg), which makes per-mod
percentages, failure reasons and **downloading mods while the server runs** possible. It is
optional; everything works on the stock egg with less to show and no background download.

| Feature | State |
|---|---|
| Per-egg capability profiles, admin UI, egg auto-detection | built |
| Egg coverage screen — what every egg resolves to, and why | built |
| Mods — load order, position, add/remove, client vs server-only, reinstall, background download, live progress | built |
| Workshop — search, paste-a-link, dependency resolution | built |
| Missions — list, delete, `class Missions` rotation | built |
| Configuration — typed `server.cfg` / `basic.cfg` editor, locked keys | built |
| Presets — saved per server, switchable, HTML upload and export | built |
| Parameters — startup flags, headless clients, Creator DLC | built |

## The thing to understand first: this panel holds no Steam credentials

Arma 3 Workshop items **cannot be downloaded by an anonymous SteamCMD login.** The account
has to own Arma 3. That single fact shapes the whole plugin.

Rather than store one Steam account in the panel and share it across every customer — a
credential worth stealing, a shared rate limit, and a Steam Guard prompt nobody can answer —
downloads are performed by each **server's own container**, using the `STEAM_USER` /
`STEAM_PASS` already on its egg.

So the division of labour is:

| Who | Does what | Needs what |
|---|---|---|
| This plugin | Reads Workshop metadata, resolves dependencies, writes the load order and a manifest | Nothing. A Steam Web API key is optional and only adds search. |
| The server's container | Downloads the files | The Steam account already on its egg |

The practical consequence, and the one every page says out loud: **adding a mod does not
download it.** It goes into the load order; the customer reinstalls, and the egg's install
script fetches what is now listed. A confirmation that said "Added" and stopped there would
read as "the files are here", and they are not.

### The load order is `@workshopID` entries

The egg field this writes documents itself:

> A semicolon-separated list of additional mod folders to load. […] Any mods in this list that
> are in `"@workshopID"` form will also be included in Automatic Updates. NO capital letters,
> spaces, or folders starting with a number! (ex. `myMod;vn;@123456789;@987654321;etc;`)

So **one field is both the load order and the download trigger**, and a Workshop item is
written as `@` followed by its id: `@450814997;@463939057;`, trailing separator included,
matching the documented example.

The `@` is not decoration. It is what marks an entry as a Workshop item, so it is what makes
the mod download at all — and a bare id would start with a digit, which the field explicitly
forbids. Digits carry no case, so an `@`-prefixed id satisfies the no-capitals rule for free.

**The list is deliberately mixed.** Alongside `@id` entries it carries CDLC short codes (`vn`,
`gm`) and hand-uploaded folder names, neither of which is downloadable and neither of which has
a Steam id. Anything reading it matches with `WorkshopId::fromModEntry()` — strict `@` plus
digits — rather than assuming. The forgiving `extract()` is wrong here: it would read `2024`
out of a folder called `mymod2024` and turn a local mod into a Workshop id that does not exist.

This has been wrong twice, in opposite directions. First it held `@Folder` names guessed from
each mod's Steam title — which downloads nothing, because the egg matches `@<digits>` and not a
name, and did not match the real folder either, since that comes from the mod's own `mod.cpp`.
Then it held bare ids, which the egg reads as a folder starting with a number. `PageHooksTest.php`
fails the build if anything starts synthesising a folder name from a title again.

Two things follow. Mod names on screen are resolved from the Steam API and cached, so the
tables show "ACE3" rather than a column of numbers, degrading to the id if Steam is
unreachable. And "is it downloaded?" is now exact rather than a name match: SteamCMD writes
into `Steam/steamapps/workshop/content/107410/<id>`, a path derivable from the id alone.

### Client mods and server-only mods

There are two load orders, not one, and the Mods page shows both in a single table with a
**Loaded by** column saying which is which:

| List | Egg variable | Arma flag | Who loads it |
|---|---|---|---|
| Client + server | `MODIFICATIONS` | `-mod=` | The server **and** every connecting client |
| Server only | `SERVERMODS` | `-serverMod=` | The server alone |

**Make server-only** and **Load on clients too** move a mod between them, and the confirmation
spells out the consequence, because the two directions are not mirror images of each other.

`-serverMod=` mods are deliberately *not required of clients* — that is the entire point of the
flag, and it is correct for admin tools, logging, anti-cheat and server-side scripts. It is
wrong for anything that adds **content**. Move a map or a weapons pack there and the server
loads it while nobody else does; with `verifySignatures` on, every player is kicked for a
missing addon. That failure names an addon class rather than a mod, so it is genuinely hard to
trace back to a switch someone flipped on this page.

The switch is two writes to two separate variables with no transaction across them, so it
**adds to the destination first and removes from the source second**. A failure then leaves the
mod in both lists — legal, visible here as two rows, and fixed by pressing the button again —
rather than in neither, which is a mod that silently vanished from a load order the customer
thought they were editing.

The action is hidden entirely on a profile with no `-serverMod=` variable. A headless client
joins a mission and hosts nothing, so the concept does not apply and the button could only ever
produce an error naming a variable the customer cannot add.

A mod may legally sit in **both** lists, which is why the table keys its rows by list and every
row action is told which list it came from. Searching for the entry by name instead is how
Remove on a server-only row used to delete the client entry, and how the reorder arrows on a
server-only row used to do nothing at all — both silently. `PageHooksTest` fails the build if a
row action stops passing its scope.

### Reinstalling a mod

**Reinstall** on a row deletes a mod's files and makes the next start fetch it again. It is for
the case where a mod is corrupt, half-downloaded, or stuck on a version SteamCMD believes is
current when it is not.

**Deleting the files is not enough, and that is the whole point of this button.** SteamCMD keeps
its own record of what it has in `<workshop root>/appworkshop_107410.acf`, and it trusts that
record over the disk. An item listed there with a current manifest is "installed", so
`workshop_download_item` reports success and transfers nothing. Delete the files by hand, leave
the ACF, and the mod stays missing through download after download — which is exactly the state
that gets described as SteamCMD losing track of a mod's version.

So three things go:

1. `@<id>` and `@<id>_optional` — the folders the server loads
2. `<root>/content/107410/<id>`, and any half-finished `downloads/` copy
3. **the item's entry in the ACF**

The mod stays in the load order. Nothing is downloaded here; the egg fetches what is missing on
the next start, which is when Arma would pick it up regardless.

#### Why the ACF is edited rather than deleted

Deleting `appworkshop_107410.acf` outright is the folk remedy and it does work, but it discards
the record for **every** mod on the server. The next start has no idea what it already has, so a
customer reinstalling one broken 200 MB mod gets their whole 40 GB set re-fetched — a bandwidth
bill and hours of downtime, caused by a button labelled "reinstall this mod".

`SteamAcf` therefore removes just that item's two blocks — it appears in both
`WorkshopItemsInstalled` and `WorkshopItemDetails`, and leaving either half behind lets SteamCMD
go on believing it.

**It refuses rather than guesses.** The result is brace-checked before and after the edit, an id
is only matched where it introduces a block (so `"manifest" "450814997"` is never mistaken for
an entry), and braces inside quoted values — a mod title with a `{` in it — do not derail the
cut. Anything unexpected returns null and nothing is written: a corrupt ACF is not one mod
failing to update, it is SteamCMD unable to read its own state for every mod on the server.

A refusal is **reported, not swallowed.** The notification says the files went but the record did
not, and points at the file to delete by hand. Staying quiet there would leave a customer
pressing Reinstall repeatedly against a mod SteamCMD had already decided it owned.

`SteamAcfTest` has 36 assertions and most of them are refusals.

### Watching the download

The Mods page polls every five seconds and shows each mod as **Downloaded**, **Downloading**
or **Waiting**, with a running count above the table.

Those three states are real rather than inferred. SteamCMD stages an item in
`<workshop root>/downloads/<app>/<id>` while it transfers and **moves** it into
`content/<app>/<id>` when it completes, so two directory listings answer the question exactly.
Both are memoised per request, so a ninety-mod list costs two listings a tick, not two per row.

**The root is `Steam/steamapps/workshop`, not `steamapps/workshop`.** `workshop_download_item`
runs without `+force_install_dir`, so SteamCMD falls back to `$HOME/Steam`; only the game is
installed to the server root. This plugin assumed the shorter path for its whole life, the
listing 404'd, `listDirectories()` swallowed it, and so **every mod read "Waiting" forever**
while the downloads worked perfectly. `ModService::workshopRoot()` now probes for it and
`PageHooksTest` fails the build on a bare literal.

### What the two eggs can tell you

On the **stock Arma 3 egg** there is no percentage within one mod: SteamCMD's transfer output
never reaches the panel. A 10 GB mod sits on "Downloading" for a long time and then completes,
which the tooltip says rather than papering over it with a bar that moves smoothly and means
nothing. Worse, a **failed** mod is invisible — SteamCMD reports the failure to the console,
which scrolls away, and on disk a mod that gave up after three attempts is identical to one not
yet reached. Both read as "Waiting".

On the [**arma3-manager egg**](https://github.com/FyWolf/arma3-manager-egg) the container
writes `.arma3-manager/status.json` and the page reads it: a real percentage per mod, the name
the egg resolved, and — the one a directory listing can never give — the reason a download
failed, on the row that failed. The percentage is the size on disk against the size Steam
reported, so it steps rather than glides.

`DownloadStatus` handles the file, and every accessor answers "I don't know" rather than
throwing, because the stock egg writes nothing. A `mods` phase that has stopped being rewritten
is treated as stale and the listings win again — otherwise a container killed mid-download would
claim a mod was downloading forever, which looks exactly like a slow mod.

The panel supplies the other half: `writeWanted()` publishes each mod's name and expected size
to `.arma3-manager/wanted.json` on every save, because the sizes live behind the Steam Web API
and the container has no credentials but the customer's own. Without that file the egg still
reports state; there is simply no percentage.

### Downloading in the background

On the [arma3-manager egg](https://github.com/FyWolf/arma3-manager-egg), **Download now** fetches
everything missing from the load order **while the server keeps running**. Nobody is
disconnected and nothing restarts.

It works by writing `.arma3-manager/request.json`, which a daemon inside the container picks up
within seconds. The panel still transfers nothing and still holds no Steam credentials — it asks,
and the container's own Steam account does the work.

**The ids are always named in the request.** The daemon can fall back to the load order it booted
with, and that fallback is a trap: `MODIFICATIONS` is read once, at container start, so it is
stale the moment the load order is edited here. A request relying on it would quietly fetch the
*previous* mod list, which is exactly the kind of silent, plausible-looking wrong answer this
codebase keeps turning up.

**Mods are still activated by a restart.** Arma reads its mod list once, at startup, and nothing
can change that — so the download is what becomes asynchronous, not the loading. The gain is
real anyway: the restart is then as fast as one with no mod changes at all, instead of being
however long a 40 GB download takes.

The button is hidden on the stock egg, because nothing there watches for the request and it would
otherwise write a file into a directory nobody reads and report success. Availability is detected
from the egg declaring `A3M_BACKGROUND_SYNC`, not from `status.json` existing — the variable says
the egg *has* the daemon, the file only says a container has already booted with it, and gating
on the file would hide the button on a correctly configured server that has never been started.

`DownloadStatus` treats `syncing` as a live phase alongside `mods`, so a background sync killed
halfway goes stale and falls back to the disk rather than showing a mod downloading forever on a
server that is otherwise running perfectly.

#### When the server is stopped

The daemon only exists while the server does, so a request simply waits and the next start
fetches it — which is what the stock egg does anyway.

**Mods are only ever downloaded by the server's own container**, at boot or by the daemon. That
is a deliberate narrowing. Two other routes were built and removed: an `A3M_SYNC_ONLY` variable
that made the container download and exit instead of launching, and a path that used Wings'
`reinstall` to run a mods-only install script in a throwaway container on the stopped server's
volume. Both worked. The first is a server that never comes back up if anyone forgets to unset
the variable; the second takes the server to **Installing** and puts a download behind an API
whose whole job is reinstalling things. Neither is worth the surface for "the mods arrive a few
minutes sooner".

The panel does not download anything itself — it has no Steam credentials, by design.

Creator DLC **are** in that list — the field is documented as "useful for loading CDLCs" and its
own example includes `vn`. They are loaded but never downloaded, so the Mods page shows them as
"Not from the Workshop" rather than leaving them on "Waiting" for a download that will never
come.

The Mods page therefore leads with what is in the load order but *not* on disk. That gap is
the failure with no readable symptom — Arma either refuses to start or starts and kicks every
client for a missing addon, naming a class rather than a mod.

## Developing

Nine checks, seven of which need no panel at all:

```
php tests/ArmaConfigFileTest.php                  # 63 round-trip assertions
php tests/ModListTest.php                         # 73 load-order assertions
php tests/LauncherPresetTest.php                  # 106 preset/id/entry assertions
php tests/DownloadStatusTest.php                  # 54 status.json parsing assertions
php tests/SteamAcfTest.php                        # 36 ACF edit/refusal assertions
php tests/MissionRotationTest.php                 # 30 rotation assertions
php tests/StartupParametersTest.php               # 33 command-line assertions
php tests/PageHooksTest.php                       # page conventions: headers, uploads, mod ids
php artisan arma3-manager:diagnose <server>        # what the plugin resolves, step by step
php tests/verify-imports.php   /path/to/panel     # every `use` resolves
php tests/verify-overrides.php /path/to/panel     # no narrowed inherited methods
```

**Run all of them before installing a build on a panel.** Each catches a failure mode that
`php -l` cannot see, and the last two catch faults that are invisible until a panel boots:

- A **mistyped namespace** fails silently. `PluginService` catches the exception, flips the
  plugin to Errored and moves on, so the symptom is a plugin that simply does not appear.
- **Overriding the wrong header-action method** renders a page with no buttons. Filament's own
  `Page` calls `getHeaderActions()`; the panel's `ServerFormPage` carries
  `CanCustomizeHeaderActions` and calls `getDefaultHeaderActions()` instead, merging other
  plugins' actions around it. Use the wrong name for your base class and the method is simply
  never called — no error, no warning, an empty header. Four pages shipped this way, including
  the Mods page's primary "Write mod list" button. `PageHooksTest.php` fails the build on it and
  needs no panel, since the base class name is in the file.
- **Narrowing an inherited method's visibility** — `protected function getFormActions()` over
  a trait's `public` one — is a fatal at *class-load* time. In a panel that is boot, so it
  does not break one page: it takes the entire panel down, and the error page itself fails to
  render (`No hint path defined for [filament]`) because Filament never got far enough to
  register its view namespace. `verify-overrides.php` deliberately does not autoload plugin
  classes, since doing so would hit the very fatal it reports.

The parser tests are not optional either. `server.cfg` is rewritten **in place on a live
server**, and a regression there corrupts a config file rather than failing loudly — an Arma
config that will not parse is a server that will not boot. The same is true of the mod list:
mis-serialising `-mod=` loads the wrong mods in the wrong order, which surfaces as a signature
mismatch kicking every player rather than as anything visible here.

## Releasing

Automatic. Merging to `main` cuts a release — there is nothing to run by hand and the version
is never edited manually.

The bump level comes from the commit messages since the last tag:

| Commit prefix | Bump |
|---|---|
| `feat!:` or a `BREAKING CHANGE` body | major |
| `feat:` | minor |
| anything else (`fix:`, `chore:`, `docs:`, …) | patch |

The workflow then writes the new version into `plugin.json` and `updater.json`, commits it as
`github-actions[bot]` with `[skip ci]`, tags `v<version>`, builds `arma3-manager.zip` and
publishes the release. Tests run first and block the release if they fail.

Both URLs — `plugin.json.update_url` and `updater.json.download_url` — are rewritten from the
repository context on every release rather than trusted from the file, so they stay correct
whichever organisation this lives under and cannot silently point a customer's panel at
another repository's releases after a rename or fork.

To force a specific version (the first release, or a correction), run the workflow manually
from the Actions tab and give it an explicit version.

Note the loop that isn't: the bot's own bump commit pushes to `main`, but pushes made with
`GITHUB_TOKEN` do not trigger workflows, and the `[skip ci]` marker guards the case where that
token is ever swapped for a PAT.

---

## When mods do not appear

`php artisan arma3-manager:diagnose <server>` prints the whole chain in one go: which capability
profile resolved and how, which variable names the profile looks for, which the egg actually
declares, which one matched, its raw value, what parsed out of it, and what is on disk.

That chain has failed at four different points so far, and each time the layer above reported
success — an egg with no profile, a variable name the egg does not declare, a value in a format
the egg cannot read, and a value that parsed to nothing. Guessing which one it is has been
wrong more often than right, so the command exists to stop guessing. It is read-only and never
prints credentials; the Steam account lives in variables this never touches.

## Presets are saved per server

Importing keeps the preset, so a unit that plays two campaigns can hold both and switch. The
Presets page lists what is saved, and **Make active** writes one over the load order.

Whether a preset is "Active" is decided by comparing its entries against the current load order,
not by a stored flag. The customer can edit mods afterwards, and a flag would go on claiming the
preset is running while the server loads something else — the screen and the server disagreeing
silently is the exact class of bug this plugin keeps turning up.

## Uploading a launcher preset

The Presets page takes the `.html` file the Arma 3 Launcher writes (*Mods → Preset →
Export*), or its contents pasted in. Being clear about what protects that upload matters,
because the obvious-looking check is the weakest one.

**The real defences are structural:**

- **The file is never rendered.** Not in a Blade view, not in a notification, not in an
  iframe. It is pattern-matched and dropped, so a `<script>` in it has nowhere to run. This
  is the property to preserve; it is not a check that can be added later.
- **No XML parser touches it.** `DOMDocument`/`SimpleXML` bring entity expansion with them —
  billion laughs, and XXE reading files off the panel. Presets *claim* to be XHTML, so
  reaching for an XML parser is the natural mistake. Regex over a byte string cannot expand
  an entity, and a test pins that `&xxe;` survives as inert text.
- **Only `\d{4,20}` escapes the parser.** Workshop ids are validated as digits, and mod
  folder names come from Steam's API response rather than from the file, so nothing
  attacker-controlled reaches a path.
- **The size cap runs before any pattern**, bounding the work every regex does. 2 MB, against
  a real 400-mod preset of well under 200 KB.
- **The upload is never stored.** `->storeFiles(false)` keeps it as a temporary file, read
  once and dropped.

**There is deliberately no MIME check.** `acceptedFileTypes()` becomes a Laravel `mimetypes:`
rule resolved by libmagic *on the server*, and a real launcher export — UTF-8 BOM, XML prolog,
HTML body — is classified differently by different libmagic builds. Windows PHP calls it
`text/xml`; a Linux panel called it something outside a list that already held `text/xml`,
`application/xml`, `text/html`, `application/xhtml+xml` and `text/plain`. Widening the list is
not a fix, it is the same bug waiting for a different server, and it fails with a framework
message naming MIME types the customer cannot check. Reading the bytes is the only check that
travels. `PageHooksTest.php` fails the build if `acceptedFileTypes()` is reintroduced.

**The `arma:Type` marker check is not a security control.** Anyone can put that meta tag in
a file. It stops a customer uploading the wrong file and getting a confusing error, which is
a different and equally worthwhile job — every refusal message says what was wrong and what
to do instead.

Refused: empty files, anything over 2 MB, binary content, invalid UTF-8, files that are not
preset-shaped, presets listing no Workshop mods, and presets over 500 mods (each one is a
Steam metadata lookup under this panel's IP).

**Two things about the real file format**, both learned by comparing against an actual export
rather than by reasoning:

- The marker is `arma:Type="list"`, **not** `"preset"`. Requiring `"preset"` refused every
  genuine export. The presence of the meta is now the signal, whatever it says — and the
  exporter here writes `list` too, so a preset produced by this plugin loads back into the
  launcher.
- A real export opens with a UTF-8 BOM followed by an XML prolog, so PHP's `finfo` reports it
  as **`text/xml`**. Filament turns `acceptedFileTypes()` into a server-side `mimetypes:`
  rule resolved by sniffing the bytes, so an html-only list rejects every real preset before
  this plugin sees one — with a validation error rather than an explanation. That list has to
  stay broad; the content check is what actually decides.

## Why the gating is a database table

The same reasoning as `minecraft-manager`, arrived at from a different direction.

That plugin can lean on egg *features*: `eula` and `java_version` are ids the panel genuinely
registers, and together with the `minecraft` tag they are a reliable signal.

Arma has no equivalent. The only feature id in that family is `steam_disk_space`, which every
SteamCMD game carries — it identifies **Steam, not Arma**, and gating on it would light this
plugin up on every Rust, ARK and CS2 server on the node. Tag matching alone is not enough
either: tags are free-form and an imported egg may carry none.

So availability comes from a **capability profile** mapped to an egg, resolved in this order:

1. **Explicit** — a profile an administrator mapped to this egg. Always wins.
2. **Inherited** — the profile mapped to the egg's parent (`config_from`), which covers a
   customised copy of a stock egg.
3. **Detected** — guessed from the egg's tags, its name, and above all the **Steam app id in
   its variables**: 233780 is the Arma 3 dedicated server, and an egg declaring it is Arma 3
   whatever it calls itself. Never written to the database, so it cannot fight an
   administrator's choice later.
4. **Nothing** — no pages, no navigation entries, no errors.

Detection order is load-bearing: `headless` is matched **before** the generic Arma tokens,
because a headless-client egg is also an Arma egg, and matching the generic token first hands
it a Missions page for a container that will never host a mission.

**Three of those four outcomes used to be invisible.** The profile screen lists the eggs
*explicitly* mapped to each profile, so an egg working through inheritance looked unmapped, an
egg working through detection looked unmapped, and an egg resolving to nothing was
indistinguishable from one resolving fine — the pages were simply absent and nothing said why.

**Admin → Arma 3 Eggs** is the answer: one row per egg, showing what it resolves to, how it got
there, which pages that grants, and how many servers are on it. It also distinguishes the two
kinds of "no pages" — an Arma egg that matched nothing (a gap worth fixing) from a Rust egg that
matched nothing (correct, and hidden by default). Detected guesses can be pinned from there,
which writes the guess down so a later change to the detection rules cannot move it silently.

## Why the egg variable names are a list

The profile carries `mod_list_variables` as an *ordered list of candidates* rather than one
name, and the first that exists on the server wins.

Arma eggs do not agree on anything. The mod list is `MODS` on one egg, `MODIFICATIONS` on
another, `WORKSHOP_MODS` on a third, and the startup command interpolates whichever one that
egg declared.

Writing to a variable the egg never declared is the worst available failure: a `ServerVariable`
row is only ever read through the egg's `startup` string, so the mods would be recorded in the
panel, shown in the UI as installed, and **never passed to the game**. The plugin therefore
refuses rather than creating a missing variable, and names the one it was looking for.

## Installation

1. Install the plugin from the panel (Admin → Plugins) or drop this folder into
   `plugins/arma3-manager` and run the installer.
2. Migrations create `a3_capability_profiles`, `egg_a3_capability_profile` and `a3_presets`,
   and drop the three `a3_mod_set*` tables if an older version left them behind. The seeder
   creates the two built-in profiles and maps any Arma eggs that already exist.
3. **After importing new eggs**, run `php artisan arma3-manager:sync-profiles` — eggs are
   imported from the `pelican-eggs` organisation *after* the panel is set up, so eggs added
   later are not mapped by the install-time seeder. Admin → Arma 3 Profiles has the same thing
   as a button.
4. Review coverage at **Admin → Arma 3 Eggs**. That screen is the one that answers "which of my
   eggs get the pages, and why" — including the eggs that get nothing. Pin anything it lists as
   *Detected* if you want the decision written down rather than re-guessed.
5. Check the variable names on the profile at **Admin → Arma 3 Profiles** match what your egg
   actually declares.

### Optional: a Steam Web API key

Everything works without one. A key adds the Workshop **search box**; without it customers
paste a workshop link or id, which resolves fully through the unauthenticated endpoint —
including the dependency graph.

Request one at [steamcommunity.com/dev/apikey](https://steamcommunity.com/dev/apikey) and paste
it into **Admin → Plugins → Arma 3 Manager → Settings**, then press **Test key**.

This is **not** a Steam login. No Steam password is ever stored in the panel.


## Permissions

Every action is gated on the subuser permissions a player already understands — no new
permission types are introduced.

| To do this | A subuser needs |
|---|---|
| See mods, browse the Workshop, list missions | `file.read` |
| Read configuration | `file.read-content` |
| Edit configuration | `file.read-content` + `file.update` |
| Change the load order, import a preset | `startup.update` + `file.update` |
| Schedule or unschedule a mission | `file.update` |
| Delete a mission | `file.delete` |
| Reinstall a mod (delete its files and SteamCMD's record) | `file.delete` + `file.update` |
| Ask for a background download | `startup.update` + `file.update` |
| See startup parameters | `startup.read` |
| Change startup parameters | `startup.update` |

Changing the load order needs `startup.update` and not merely a file permission, because what
it actually rewrites is a **startup variable**. The file half only covers the manifest written
alongside it.

Nothing here requires the server to be stopped, because nothing here can corrupt a running
server: Arma reads its command line, its config and its mod list **once, at startup**. Every
page says so where it saves. That is a genuine difference from `minecraft-manager`, where
Minecraft rewrites `server.properties` on shutdown and would discard an edit made underneath
it.

## Locked settings

`maxPlayers` is locked by default. On a host that sells slots the player limit belongs to the
order, not to the customer's config file.

A locked key stays **visible and disabled** with a reason attached, rather than disappearing —
a setting that has silently vanished is far more confusing than one that says why it is greyed
out. The lock is enforced **server-side on save**, not merely in the browser: Livewire state
arrives from the client and can say anything, and an attempt to change a locked value is
recorded in the activity log. It is the only signal a host gets that somebody is probing the
limits of their plan.

Configure the list at **Admin → Plugins → Arma 3 Manager → Settings**.

## Uninstalling

Uninstalling a plugin rolls back its migrations, so **the egg-to-profile mapping and every
saved preset are dropped**. Export the mapping first from Admin → Arma 3 Profiles, and any
preset you want to keep from the Presets page, if you intend to reinstall.

Mods, missions and configuration files live on the game server and are untouched.

## Relationship to `arma-reforger-workshop`

Different game. Arma Reforger has its own workshop with its own JSON config and shares nothing
with Arma 3's Steam Workshop. Both plugins can be installed at once and will not collide.

## License

GPL-3.0. See `LICENSE`.
