# Groddle Afternoon site

The website for [Groddle Afternoon](https://github.com/TinyBitAdventures/groddle-afternoon), a
fresh take on Tiny Speck's Glitch, live at [groddleafternoon.com](https://groddleafternoon.com).

It is a theme for [Minn](https://github.com/austinginder/minn-engine), a PHP engine that speaks
WordPress's contracts, and the theme carries its own Minn extension. Together they:

- serve the built game and its pages beside the site's own content (`/`, `/play/`, `/compare/`
  and the other folders of the game build),
- draw the pages written in Minn Admin: the Notes blog, the restoration report, Musicblocks and
  Street music, with blocks for their structured parts,
- keep player accounts, multiplayer saves and solo save backups,
- sign the tickets the [realtime server](https://github.com/TinyBitAdventures/groddle-afternoon-server)
  checks when a multiplayer game connects.

## What is in it

| Path | What it is |
|---|---|
| `minn.json` | The manifest: the extension class, its blocks and the tables it needs |
| `theme.json`, `style.css`, `templates/`, `parts/` | The block theme: notes, pages, the full-width `page-wide` template, header and footer |
| `blocks/` | The site's blocks (`groddle/*`), each a `block.json` drawn in PHP and edited with forms in Minn Admin |
| `marks/` | Icons the note cards use |
| `src/` | The extension: plain PHP that uses only Minn's own classes, no WordPress functions |

The extension's main pieces:

| Class | What it does |
|---|---|
| `Extension` | Registers everything below with Minn |
| `StaticPages`, `StaticRoot` | Answer the game build's folder pages; a published Minn page at the same path wins |
| `Blocks`, `Restoration`, `Musicblocks`, `StreetMusic` | Draw the blocks, reading the JSON files the game build writes |
| `SiteNav` | The header menu, drawn from the site's Navigation menu |
| `Player`, `MultiplayerSave`, `SoloBackups` | The `groddle/v1` REST routes for players |
| `RealtimeTicket` | Signs a one-use, 60-second ticket for the realtime server |

### REST routes

All under `/wp-json/groddle/v1/`. The ones for signed-in players check the REST nonce.

| Route | What it does |
|---|---|
| `GET me` | Who is signed in (or nobody) |
| `GET save`, `PUT save` | The multiplayer character's save, with a version check so two windows never overwrite each other |
| `GET backups`, `GET/PUT/DELETE backups/{slot}` | Three slots to back up a solo save to the account |
| `POST realtime` | A ticket for the realtime server |

The tables are declared in `minn.json`: `groddle_mp_saves` and `groddle_solo_backups` (with the
site's table prefix).

## Install

1. Set up a Minn site (see [minn-engine](https://github.com/austinginder/minn-engine)).
2. Put this repo in the site's `wp-content/themes/groddle` and make Groddle the active theme.
3. Build the game ([groddle-afternoon](https://github.com/TinyBitAdventures/groddle-afternoon),
   its `public/` folder) into a folder beside the webroot, for example `game/`, and set the
   `groddle_static_root` option to it (absolute, or relative to the webroot: `../game`). Without the
   option the webroot itself is used.
4. Let the web server find the game's files too. Folder pages go through Minn, but files (scripts,
   images, sound) are served straight from disk: link each top-level entry of the build into the
   webroot (`ln -s ../game/assets assets`, and so on), never over Minn's own files.
5. For multiplayer, put the shared secret in `realtime.secret` beside the webroot (or set the
   `GRODDLE_REALTIME_SECRET` constant, or name the file with the `groddle_realtime_secret_file`
   option), run the realtime server with the same secret, and pass `/ws` to it. Without a secret
   the ticket route answers 503 and the game plays on without other players.

The pages that hold words (the restoration report, Musicblocks, Street music, the Notes) are
created once by scripts in the game repo (`tools/*/to-minn.mjs`) and edited in Minn Admin after
that.

## Licence

MIT, see [LICENSE](LICENSE). The art the site shows is Tiny Speck's public-domain (CC0) release of
Glitch. Groddle Afternoon is a fan project, not affiliated with or endorsed by Tiny Speck or Slack,
and it does not use the Glitch name or logo.
