# Contributing to the Groddle Afternoon site

Issues and pull requests are welcome. A page that looks wrong, a sign-in or save problem, a block that misbehaves in Minn Admin: [open an issue](https://github.com/TinyBitAdventures/groddle-afternoon-site/issues), or [send the fix](https://github.com/TinyBitAdventures/groddle-afternoon-site/pulls).

This repo is the website at https://groddleafternoon.com: a theme and extension for [Minn Engine](https://github.com/austinginder/minn-engine), in PHP. The theme draws the pages and Notes; the extension serves the built game beside them, adds the page blocks (`blocks/*/block.json`, drawn in `src/`) and answers the game's `groddle/v1` REST routes. The game is [groddle-afternoon](https://github.com/TinyBitAdventures/groddle-afternoon) and the realtime server is [groddle-afternoon-server](https://github.com/TinyBitAdventures/groddle-afternoon-server); https://groddleafternoon.com/code/ shows what is in each.

## Run it locally

Clone the three repos side by side:

    git clone https://github.com/TinyBitAdventures/groddle-afternoon
    git clone https://github.com/TinyBitAdventures/groddle-afternoon-server
    git clone https://github.com/TinyBitAdventures/groddle-afternoon-site

The site needs a Minn site to run on ([Cove](https://cove.run) sets one up locally); the readme's Install section has the steps.

The site's tests live in the game repo and read this checkout from `../groddle-afternoon-site` (or `GRODDLE_SITE_DIR`):

    cd groddle-afternoon
    npm install
    npm test

Check the PHP too:

    for f in src/*.php; do php -l "$f"; done

## House rules

- **Commit subjects use Emoji-Log**: `📦 NEW:`, `👌 IMPROVE:`, `🐛 FIX:`, `📖 DOC:` or `🤖 TEST:`, then the change in plain words. The body, if any, is short `- ` bullets with the facts that matter.
- **Every change comes with a test** (in the game repo), and `npm test` stays green.
- **Minn's own classes only**: `src/` uses the engine's classes (`Minn\Extension\Seams`, `Minn\Html`, ...), never WordPress functions.
- **Tables stay additive**: a change to `minn.json` `tables` adds columns or tables, never renames or drops them, so a rollback still runs.
- **Figures come from data**: a number on a page is drawn from a JSON file the game build writes, never typed into the page.
- **Solo play does not change for multiplayer work.**
- **Words**: our own, plain, with no em-dashes; item and game text come from Tiny Speck's files.

## Licences

The code is MIT (see `LICENSE`). Tiny Speck's art, sound and game data are CC0. The Glitch name and logo are Tiny Speck's and not part of that release, so they are not used.
