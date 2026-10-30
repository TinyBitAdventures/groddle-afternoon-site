# Groddle Afternoon site

The website for [Groddle Afternoon](https://github.com/TinyBitAdventures/groddle-afternoon), a
fresh take on Tiny Speck's Glitch, live at [groddleafternoon.com](https://groddleafternoon.com).

It is a theme for [Minn](https://github.com/austinginder/minn-engine), a PHP engine that speaks
WordPress's contracts, and the theme carries its own Minn extension. Together they:

- serve the built game and its pages beside the site's own content (`/`, `/play/`, `/compare/`
  and the other folders of the game build),
- draw the pages written in Minn Admin: the Notes blog, the restoration report, Musicblocks and
  Street music, with blocks for their structured parts,
- let players make an account and sign in from the game itself, by email (no username, no admin sign-in page),
- keep multiplayer saves and solo save backups,
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
| `PlayerAccount` | The game's own sign-up, sign-in, sign-out and password reset, by email, through Minn's accounts seam (`$minn->accounts()`) |
| `Player`, `MultiplayerSave`, `SoloBackups`, `Buddies` | The `groddle/v1` REST routes for players |
| `PlayerMail` | The emails players get, as HTML in the site's look, from "Groddle Afternoon" (the `groddle_mail_from` option names the address, else `noreply@` the site's host) |
| `RealtimeTicket` | Signs a one-use, 60-second ticket for the realtime server |
| `Tickets`, `AdminTickets`, `TicketStore` | Bug reports as threads: the player's Reports sheet in the game, and the Reports tab of the administrators' console at `/console/`. A reply from an administrator emails the player (`PlayerMail`), unless they tick Don't email: then it is a note the player sees only in the game |
| `ReportPicture`, `TicketPictures` | The picture of the game a bug report may carry: checked (a JPEG from its bytes, at most 1280x1280 and 300 KiB, written again by GD so no metadata stays) and served at `/report-pictures/<report>` only to its player and the administrators |
| `TicketMigration` | Brings the bug reports from before tickets (private `groddle_feedback` posts) in as tickets, once |

### REST routes

All under `/wp-json/groddle/v1/`. The ones for signed-in players check the REST nonce.

| Route | What it does |
|---|---|
| `POST players` | A new player: email, password (8 or more characters) and a character name (3 to 19 letters, numbers, spaces, `-` or `_`, unique), then signed straight in. The account is a subscriber whose username is made up (`player-<hex>`) and never shown; the character name is its display name |
| `POST session`, `DELETE session` | Sign in by email (optionally remembered for two weeks), sign out |
| `POST password-reset` | Mails a reset link back into the game (`/play/online/#reset=<key>&login=<login>`); the answer is the same whether or not the email has an account |
| `POST password-reset/confirm` | The new password from that link (key, login), then signed straight in |
| `PUT account/password`, `PUT account/email` | A signed-in player's new password (signed in again here, signed out everywhere else) or sign-in email, each with the current password; the account's address is told in the site's look |
| `GET me` | Who is signed in (or nobody) |
| `GET save`, `PUT save` | The multiplayer character's save, with a version check so two windows never overwrite each other |
| `GET backups`, `GET/PUT/DELETE backups/{slot}` | Three slots to back up a solo save to the account |
| `POST realtime` | A ticket for the realtime server |
| `GET/POST buddies`, `DELETE buddies/{id}` | Your buddies (online through the realtime server), who added you, and adding or removing one (1,000 at most, one Buddies group, as in the original) |
| `POST buddies/ignores`, `DELETE buddies/ignores/{id}` | Blocking a player and un-blocking them |
| `GET tickets`, `GET tickets/unread`, `GET tickets/{id}` | Your bug reports (status open, answered or closed, and whether an administrator replied since you looked), the count of unread replies, and one thread (which marks it read). Only ever your own: anyone else's answers 404 |
| `POST tickets` (also `POST feedback`) | A new bug report: the message (at most 2,000 characters), what the game knew (street, place, build, page, browser, screen, the last errors) and, when the player leaves it ticked, a picture of the game (a base64 JPEG); 3 in 10 minutes and 20 a day |
| `POST tickets/{id}/messages`, `POST tickets/{id}/close` | A reply (it opens a closed report again) and closing your own report |
| `GET admin/tickets`, `GET admin/tickets/{id}`, `POST admin/tickets/{id}/reply\|close\|reopen`, `POST admin/tickets/migrate` | The console's Reports tab, `manage_options` only, each action in the console's activity log |

The tables are declared in `minn.json`: `groddle_players` (each player's character name, unique),
`groddle_mp_saves`, `groddle_solo_backups`, `groddle_buddies` and `groddle_ignores` (with the site's
table prefix). Each buddies answer carries a list signed with the realtime secret, which the server
watches for who is online; an add also carries a one-use proof that tells the other player.
Bug reports are `groddle_tickets` (one row a report: its player, status, subject, when each side last
read it, what the game knew), `groddle_ticket_messages` (the thread, oldest first) and
`groddle_ticket_pictures` (a report's picture, one row a report, kept in the database rather than under
uploads because the web server serves uploads to anyone).

**Bringing in the old bug reports** (once, after the release that adds tickets; safe to run again, the
posts stay, nobody is emailed): the console's Reports tab offers "Bring in old reports" while any are
left, or from the webroot:

    wp eval-file wp-content/themes/groddle/bin/migrate-bug-reports.php dry-run
    wp eval-file wp-content/themes/groddle/bin/migrate-bug-reports.php

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
   When the realtime server runs on another host than the site (a site on managed WordPress hosting
   that cannot pass `/ws` on), set its address in `wp-config.php`:
   `define( 'GRODDLE_REALTIME_URL', 'wss://play.example.com/ws' );`. Each ticket then carries it as
   `url` and the game connects there; run that server with the site's host in `-origins`.

The pages that hold words (the restoration report, Musicblocks, Street music, the Notes) are
created once by scripts in the game repo (`tools/*/to-minn.mjs`) and edited in Minn Admin after
that.

## Licence

MIT, see [LICENSE](LICENSE). The art the site shows is Tiny Speck's public-domain (CC0) release of
Glitch. Groddle Afternoon is a fan project, not affiliated with or endorsed by Tiny Speck or Slack,
and it does not use the Glitch name or logo.
