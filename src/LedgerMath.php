<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The ledger's arithmetic, pure (no database, no engine), so a test can run
 * it on fixture saves: what a multiplayer save holds of the things that can
 * change hands, how that compares with the ledger, what a write may gain,
 * and the edits the site makes to a stored save (taking back what was not
 * accounted for, applying a settled trade). The design and its reasons are
 * glitch-audit research/coverage/g335-economy.md.
 *
 * Goods and holdings are ['items' => [class => n], 'currants' => n], only
 * tradeable classes (Economy). A trade's goods from the realtime server
 * are ['items' => [['c' => class, 'n' => n, 'r' => points?], ...], 'currants' => n].
 *
 * Where a save keeps things (all counted, so moving between them is never a
 * gain): the pack and purse (playerState inventory, player.currants), the
 * trade hold (systems.features.trade.hold, js/trade.js), home furniture
 * storage, placed furniture, Storage Display Boxes and what cabinets hold (systems.features.
 * homes decor, towerDecor and the upper floors, floors.upper, js/home-decor.js; a
 * cabinet's slots are its row's contents, js/home-storage.js) and stacks dropped on
 * streets (the droppedItems entry, js/world-items.js, a dropped bag with what it
 * holds). Smaller stashes are
 * not counted: taking from one reads as a gain, which the budget pays for.
 *
 * Slice 2 explains gains so the budget can be small: a write's losses may
 * turn into a transform's outputs outside the budget (explain: recipes,
 * Cubimal boxes, artifact assembly, Economy::producing), rewards the source
 * pays for a marker the save carries are credited exactly once per
 * character (credits: quests, badges, level-ups, imagination cards, and
 * the house's starter furniture, homeKit), and a
 * class only drop tables grant gains at most its cap a day (reconcile's
 * $capsLeft).
 *
 * G895 (#432) credits what the save's own records say it earned (earned: a
 * shrine's emblems, the Gameshow Tickets its donations gave and a cracked
 * Petrified Rock's pieces), as many as the write shows, each rule with a daily
 * count; one past the count waits in the save outside the ledger.
 */
final class LedgerMath
{
    /** js/trade.js APPLIED_KEEP: trades a save remembers as applied. */
    public const APPLIED_KEEP = 24;

    /** js/street-jobs-mp.js APPLIED_KEEP: street project records a save remembers as applied. */
    public const PROJECT_APPLIED_KEEP = 64;

    /** js/feats-mp.js APPLIED_KEEP: Feat records a save remembers as applied. */
    public const FEAT_APPLIED_KEEP = 48;

    /** The save slices that list applied ids, and the prefix of a street project's records. */
    public const TRADE_SLICE = 'trade';
    public const PROJECT_SLICE = 'street-jobs';
    public const PROJECT_PREFIX = 'job-';
    /** G345: a shared Feat's reward (feats.go feat-r-), remembered in the Feats feature's slice. */
    public const FEAT_SLICE = 'solo-feats';
    public const FEAT_PREFIX = 'feat-';

    /**
     * Ours: purchases a day of one repeatable imagination card that are
     * credited. Purchases past the count wait for a later day's count, and
     * up to CARD_WAIT of them stand in the save meanwhile (check: deferred,
     * outside the ledger, so nothing can trade them on); more than that go
     * through the budget.
     */
    public const CARD_DAILY = 3;
    public const CARD_WAIT = 30;

    /**
     * Ours: paying level-ups credited a day (a level the economy table pays
     * nothing for takes no place; report #246). Levels and xp are the
     * client's, so a script could step a character from 2 to 60 at once
     * (1,441,400 currants in all); a few a day keeps the ledger's side of
     * that slower than real play ever was. A paying level past the count is
     * remembered (credited kind lvlwait, ref the level, n its currants; 0
     * once paid) and credited on a later day's count, and until then its
     * currants stand in the save outside the ledger (check: deferred), so an
     * honest pack is never corrected and nothing can trade them on before
     * they are credited. Each level is credited once (the level row moves
     * on when it is reached) and only when the xp reaches it.
     *
     * 12 (Austin 2026-11-12, measured on live): in 221 player-days since launch the most levels anyone gained
     * in a UTC day was 12 (a first day's 95th percentile 8), so honest play never waits; a forged save still
     * cannot cash in 60 levels at once. It was 3, which a third of player-days passed.
     */
    public const LEVELS_DAILY = 12;

    /** Credited kind of a paying level-up waiting for a later day's count. */
    public const LEVEL_WAIT = 'lvlwait';

    /**
     * Ours (G739): currants credited a day for levels, quests and badges together, per character.
     * Levels, quests and badges are the save's own claims until the realtime server counts them, so
     * this bounds what a forged save can cash in a day. 40,000 is about twice the 90th percentile of
     * honest characters' best day measured on live (about 19,000; median 2,640) on 2026-11-14.
     * Currants past it are never lost: they wait (PROG_WAIT) for a later day's room, standing in the
     * save meanwhile outside the ledger (check: deferred), so an honest pack is never corrected.
     * One paying level-up a day is outside it (PROG_DAY ref "free"): the first credited that day
     * that is a single step (exactly one level above the last the ledger credited), which is the
     * day's largest since rewards grow with the level, so an honest high-level player never waits for
     * an ordinary level-up (level 40 pays 97,570, level 50 216,100). It still takes a place in
     * LEVELS_DAILY and still needs the xp. A jump of two or more levels, and a level that waited for
     * LEVELS_DAILY, go through the room like everything else.
     */
    public const PROGRESSION_DAILY = 40000;

    /** Credited daily kind: progression currants credited today (ref "currants"), and whether today's level-up outside the cap is used (ref "free", 1). */
    public const PROG_DAY = 'progday';

    /** Credited kind: progression currants waiting for a later day's room (ref "level:<n>", "quest:<id>" or "badge:<id>", n what is left). */
    public const PROG_WAIT = 'progwait';

    /**
     * G895 (#432): what a save's own record says it earned (earned), credited outside the budget.
     * Credited kind MARK keeps, per character, how far each record has been credited (ref
     * "emblem:<giant>" the shrine's emblem count); MARK_DAY counts today's credits per rule. A record
     * seen for the first time is marked without paying (markBaseline), so only what it gains after
     * that is credited.
     */
    public const MARK = 'mark';
    public const MARK_DAY = 'markday';

    /**
     * Emblems (Austin 2026-11-16): a shrine's emblem stands up to the number the save's shrine record
     * shows earned (systems.shrines.emblems, js/shrines.js claimShrineEmblem; items/include/shrines.js
     * give_emblem 78-166), at most EMBLEM_DAILY a UTC day for all Giants together. The record is the
     * client's word, so the cap is what bounds a forged save: 4 is 4,000 of value a day, about an
     * hour of the budget's connected-time refill. Honest play rarely meets it: an emblem costs 1,000
     * favor and up (100 more each time), donations give a tenth of the base cost as favor and at most
     * one emblem's worth a Giant a game day (shrines.js 306-327), so each emblem takes some 10,000
     * currants of donated goods. One past the count is never taken: up to EMBLEM_WAIT of them stand
     * in the save outside the ledger (held) and are credited on a later day.
     */
    public const EMBLEM_DAILY = 4;
    public const EMBLEM_WAIT = 4;

    /**
     * Gameshow Tickets (G895): a shrine donation may give one (js/shrines.js donateToShrine 460-475,
     * spinning-wheel.js gameshowTicketChance; GSJS include/shrines.js 546-566: 15% for a donation worth
     * 300 or more), so a ticket stands up to the donations the save's shrine record counted since it was
     * last credited (systems.shrines.donations, one ticket a donation at most), TICKET_DAILY a UTC day
     * (4,000 of value at the ticket's fallback price; an honest 4 takes some 27 large donations), up to
     * TICKET_WAIT past it waiting. A ticket from a drop table (croppery, herbalism ...) goes through
     * the budget as before.
     */
    public const TICKET_DAILY = 4;
    public const TICKET_WAIT = 4;
    public const TICKET_CLASS = 'gameshow_ticket';

    /**
     * Artifact pieces and necklace beads (G895): only a cracked Petrified Rock gives them
     * (js/artifacts.js CRACK_STEPS, petrified_rock_*.js onCrack: a small rock one row of
     * petrified_rock_small_crack, at most 3 beads or a piece; a medium two artifact_pieces rolls; a large
     * three and a fifth at 20%), and only a Feat reward gives a rock (feats.go: every contributor a
     * small one, places 1 to 26 a large or medium one too, ROCKS_PER_FEAT; the rocks are not tradeable,
     * so the ledger never sees them). So they stand up to CRACK_YIELD for each crack the save's record
     * counted since it was last credited (systems.features.artifacts.cracked, artifacts-feature.js
     * finishCrack; mark ref crackyield, in pieces), never more than the realtime server's Feat rewards
     * could have given rocks for (its signed feat-r- records in groddle_trades, $feats in check: the
     * mark rockyield holds what the first sighting allowed, the rocks the stored save held then, at most
     * ROCKS_BASE_MAX, mark feats the rewards counted then), each class within its daily cap (the cap
     * rows the budget's own drops count too), up to CRACK_WAIT past the cap waiting.
     */
    public const CRACK_YIELD = ['petrified_rock_small' => 3, 'petrified_rock_medium' => 2, 'petrified_rock_large' => 4];
    public const ROCKS_PER_FEAT = 2;
    public const ROCKS_BASE_MAX = 20;
    public const CRACK_WAIT = 15;

    /**
     * Elemental Shake (G905, #439): once a game day a pouch's Shake adds round(count x delta) of each
     * element the shaken pouch holds (js/elemental-pouch.js shakeElement; GSJS items/
     * bag_elemental_pouch.js doShake 669-690: delta one of 0.08 to 0.12 with Super Elemental Shake,
     * 0.04 to 0.06 with Elemental Shake, a pouch slot holds 5,000), and the save's pouch record counts
     * the shakes (systems.features['elemental-pouch'].shakes). Each shake the record gained since it
     * was last credited allows, per element, round(min(5,000, the ledger's count) x the best delta of
     * the card the save owns), outside the budget, SHAKE_DAILY a UTC day (a game day is 4 real
     * hours, so 6 honest shakes and one across the edge). Without the card nothing is allowed.
     */
    public const SHAKE_DAILY = 7;
    public const SHAKE_CARDS = ['element_handling_bonus_2' => 0.12, 'element_handling_bonus_1' => 0.06];
    public const SHAKE_ELEMENTS = ['element_blue', 'element_green', 'element_red', 'element_shiny'];
    public const ELEMENT_STACK = 5000;

    /**
     * G905 (#438): what the tutorial and the skill quests' practice streets hand a new player, credited
     * once per character (credited kind TUTORIAL, ref "<set>:<class>", n credited so far) when the
     * save's own record says the player got there, outside the budget: a new character's NEW_BUDGET
     * went on these and its first apples and Musicblock were then taken back. Each set: the gate the
     * save must show, then class => the most it gives. Sources (the game):
     *  - training2: js/newxp.js TRAINING2_FOOD / TRAINING2_DRINKS 410-418 (newxp_training2.js special_items).
     *  - house: features/newxp-feature.js harvestEgg 2135-2146, the Egg Plant's one egg. G948 (G942 gap
     *    A25): its gate is stage training2, where the Dustbunny's egg scene harvests it (runTraining2),
     *    not house, which the record only reaches after the egg was given; the ref keeps its name.
     *  - island: Gentle Island's trees (assets/content/street-ecology.json: two Fruit Trees, a Bean
     *    Tree and a Gas Tree, 3 a harvest without Gardening, yard-verbs arbor), the Spinach garden
     *    (flag garden: a Spinach Seed's 12), the Gameshow Ticket stand (flag gst, newxp-feature.js
     *    gullySetup 3485-3495), the Musicblock on Doonboggle Trail (flag musicblock, its class in the
     *    record, newxp-feature.js 3449-3460) and the two bags buy_two_bags pays for (2715-2726).
     *  - ak, lgt, ezc: the first skill's practice street (the newxp record's skill, or its quest),
     *    js/newxp.js PRACTICE_TOOLS 112-118; A L'il Animal Kinship's one chicken, piggy and butterfly
     *    (js/skillquest-ak.js AK_ANIMALS; their most at the best rank, data/yard-verbs.json ak); Lift
     *    Your Light Green Thumb's three trees; EZ Cookin's table and counters (js/practice-streets.js
     *    EZ_KNIFE_AND_BOARD 297, EZ_FOOD 299-307).
     *  - soil: Talking 'bout Soil Appreciation's Hoe and Shovel (js/practice-streets.js SOIL_TOOLS 495-498).
     *  - funpickle: the Musicblock the Funpickle gives once every power was used (js/funpickle.js
     *    AWARD_ITEM 146, recordPower givenAward; GSJS giveAward 372-378).
     *  - startblock (G938): the Musicblock a new player finds on their start street, the street the
     *    tutorial left them on or Hechey Track, when they own none (features/musicblocks-feature.js
     *    layStartStreetMusicblock, its class in the record's startBlock; GSJS locations/location.js
     *    646-667, one of the twenty D and X blocks).
     *  - musicblockgift (G938, desk FUNPICKLE): Musicblock GNG, sent once when all 25 series tunes are
     *    owned (features/musicblocks-feature.js sendEndgameGift, the record's gift; the way GSJS
     *    giveAward 372-378 sends it).
     *  - islandcurrants (G948, G942 gap A14): the 978 currants Gentle Island's rock hands a new player
     *    (features/newxp-feature.js islandRockScript, js/newxp.js ISLAND_CURRANTS; GSJS newbie_island.js
     *    revealCurrants2 stats_add_currants(978)), by the island flag island_currants the game sets in
     *    the same tick. A set's currants are credited like its items, once per character (ref
     *    "<set>:currants"). They came out of the new character's NEW_BUDGET and were taken back when
     *    other gains had spent it first.
     */
    public const TUTORIAL = 'tut';
    public const TUTORIAL_SETS = [
        'training2' => ['gate' => 'stage:training2', 'items' => ['common_crudites' => 1, 'flummery' => 1, 'spicy_grog' => 4, 'fruity_juice' => 3]],
        'house' => ['gate' => 'stage:training2', 'items' => ['egg_plain' => 1]],
        'island' => ['gate' => 'stage:island', 'items' => ['cherry' => 6, 'bean_plain' => 3, 'general_vapour' => 3, 'bag_generic' => 2]],
        'islandgarden' => ['gate' => 'flag:garden', 'items' => ['spinach' => 12]],
        'islandticket' => ['gate' => 'flag:gst', 'items' => ['gameshow_ticket' => 1]],
        'islandblock' => ['gate' => 'flag:musicblock', 'items' => ['musicblock' => 1]],
        'ak' => ['gate' => 'skill:animalkinship_1', 'items' => ['butterfly_lotion' => 1, 'meat' => 4, 'milk_butterfly' => 5, 'grain' => 5]],
        'lgt' => ['gate' => 'skill:light_green_thumb_1:lightgreenthumb_1', 'items' => ['watering_can' => 1, 'plain_bubble' => 3, 'bean_plain' => 3, 'cherry' => 3]],
        'ezc' => ['gate' => 'skill:ezcooking_1', 'items' => ['knife_and_board' => 1, 'cheese' => 6, 'tomato' => 2, 'bun' => 11, 'meat' => 6]],
        'soil' => ['gate' => 'quest:soilappreciation_1', 'items' => ['hoe' => 1, 'shovel' => 1]],
        'funpickle' => ['gate' => 'funpickle', 'items' => ['musicblock_goodnight_groddle' => 1]],
        'startblock' => ['gate' => 'startblock', 'items' => ['startblock' => 1]],
        'musicblockgift' => ['gate' => 'musicblockgift', 'items' => ['musicblock_goodnight_groddle' => 1]],
        'islandcurrants' => ['gate' => 'flag:island_currants', 'items' => [], 'currants' => 978],
    ];

    /** G938: the start street's twenty (GSJS locations/location.js 663: the D and X series, no BB). */
    public const START_BLOCK_CLASS = '/^musicblock_(d_(blue|green|red)|x_shiny)_0[1-5]$/';
    /** js/newxp.js STAGES, in order. */
    public const NEWXP_STAGES = ['clouds', 'training1', 'training2', 'house', 'skill', 'island', 'done'];

    /** Credited kinds that count per day (Ledger keeps today's only): capped gains, card purchases, level-ups, progression currants, earned records. */
    public const DAILY_KINDS = ['cap', 'cardday', 'lvlday', self::PROG_DAY, self::MARK_DAY];

    /**
     * G895 (#432): the most value of capped drops (classes only drop tables give, at most their cap a
     * day: Musicblocks, artifact pieces ...) that waits in a write when the budget cannot pay for them
     * yet, instead of being taken back. A rare drop is the find a player remembers; taking it because
     * other gains drained the budget a minute before was the worst kind of correction. 3,000 is about
     * six Musicblocks, 50 minutes of the budget's connected-time refill; what waits never enters the
     * ledger until a later write pays for it (so it cannot be traded, mailed or auctioned meanwhile),
     * and it waits only within its class's cap, so a forged pile of drops gains nothing by waiting.
     */
    public const CAPPED_HOLD_VALUE = 3000.0;

    /**
     * G905 (#436, #438, #439): the credited kind that remembers what waited on the last write (ref the
     * class, n how many). On the next write those gains are paid first, before the write's other
     * gains (still within the budget), so a Musicblock that waited is not starved by the cheap
     * harvests that drain the budget each minute. A class only drop tables give (capped) waits as
     * G895 made it; so does one a drop table gives besides an ordinary source (Economy::drop: a
     * quest's Musicblock, a mined gem, a home Wood Tree's Planks), not past CAPPED_HOLD_VALUE a write,
     * but one find worth more than that (the 9,000 secret Musicblock) may always wait alone.
     */
    public const WAITED = 'held';

    /**
     * G905 (#436, #438, #439): timed production. A Community Machine Room's machine takes a job's
     * ingredients when it starts and hands the output over on Collect, minutes later
     * (js/features/machine-rooms-feature.js startJob / collect; players/inc_making.js 306-308,
     * fuelmaker.js setContents 1533-1544), and a garden seed planted gives its crop when picked: the
     * inputs leave in one save write and the output comes in a later one, so explain (the same
     * write's losses) never saw them together and the budget alone paid for every Girder, Board and
     * Cucumber. Now a write's spent inputs of a timed transform (economy w; what explain did not use
     * and the write's other gains did not consume) wait in the ledger's pool (credited kind POOL, ref
     * "<class>@<UTC hour>", n left), and a later write's output of those transforms stands against
     * them outside the budget (fromPool), the inputs used up oldest first; an input that comes back
     * (a failed job's ingredients left in the machine, onMakingFailed fuelmaker.js 1408-1411) stands
     * against them too. Bounded by what was really spent and by the recipe: nothing is made from
     * nothing, and spent inputs are gone after POOL_HOURS (a machine keeps output 6 game days, 24 real
     * hours, fuelmaker.js expireOldContents 1146-1158).
     */
    public const POOL = 'pool';
    public const POOL_HOURS = 48;

    /** How deep explain looks for an input made from other losses in the same write (grain to flour to a bun). */
    public const EXPLAIN_DEPTH = 2;

    // —— Reading a save ——

    /** The save's tradeable holdings, everywhere it keeps them. */
    public static function holdings(array $entries, Economy $economy): array
    {
        $items = [];
        $add = static function (mixed $class, mixed $n) use (&$items, $economy): void {
            $count = self::count($n);
            if (is_string($class) && $count > 0 && $economy->tradeable($class)) {
                $items[$class] = ($items[$class] ?? 0) + $count;
            }
        };
        $state = self::decode($entries['playerState'] ?? null);
        $currants = 0;
        if (is_array($state)) {
            foreach ((array) ($state['inventory'] ?? []) as $class => $n) {
                $add($class, $n);
            }
            $currants += self::count($state['player']['currants'] ?? 0);
            $hold = $state['systems']['features']['trade']['hold'] ?? null;
            if (is_array($hold)) {
                foreach ((array) ($hold['items'] ?? []) as $class => $n) {
                    $add($class, $n);
                }
                $currants += self::count($hold['currants'] ?? 0);
            }
            // G332: a wrapped Gift Box (features/gifts-feature.js) is the box and what was wrapped in it.
            $boxes = $state['systems']['features']['gifts']['boxes'] ?? null;
            foreach (is_array($boxes) ? $boxes : [] as $box) {
                if (!is_array($box)) {
                    continue;
                }
                $add('bag_gift_box', 1);
                foreach (is_array($box['items'] ?? null) ? $box['items'] : [] as $it) {
                    if (is_array($it)) {
                        $add($it['c'] ?? null, $it['n'] ?? 0);
                    }
                }
            }
            $homes = $state['systems']['features']['homes'] ?? null;
            if (is_array($homes)) {
                // G153: the floors above the ground floor (homes-feature save floors.upper) hold placed pieces too.
                $decors = array_merge([$homes['decor'] ?? null], is_array($homes['towerDecor'] ?? null) ? $homes['towerDecor'] : [], is_array($homes['floors']['upper'] ?? null) ? $homes['floors']['upper'] : []);
                foreach ($decors as $decor) {
                    if (!is_array($decor)) {
                        continue;
                    }
                    foreach ((array) ($decor['storage'] ?? []) as $class => $n) {
                        $add($class, $n);
                    }
                    foreach ((array) ($decor['placed'] ?? []) as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $add($row['classTsid'] ?? null, 1);
                        if (is_array($row['display'] ?? null)) {
                            $add($row['display']['classTsid'] ?? null, $row['display']['count'] ?? 0);
                        }
                        // Ticket 51: a cabinet's slots (js/home-storage.js normalizeContents).
                        foreach (is_array($row['contents'] ?? null) ? $row['contents'] : [] as $slot) {
                            if (is_array($slot)) {
                                $add($slot['classTsid'] ?? null, $slot['count'] ?? 0);
                                // #108: a bag stored whole is the bag and what it holds, as a dropped one.
                                foreach (is_array($slot['contents'] ?? null) ? $slot['contents'] : [] as $it) {
                                    if (is_array($it)) {
                                        $add($it['classTsid'] ?? null, $it['count'] ?? 0);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $features = is_array($state['systems']['features'] ?? null) ? $state['systems']['features'] : [];
            // G948 (G942 gap A3): the Craftybot's jobs hold what it fetched (holder general) and what it made
            // (holder complete) until Collect (js/craftybot.js hold / unhold, collectJob; GSJS
            // npc_crafty_bot.js). Fetching, crafting and collecting are then moves and same-write
            // transforms, not ingredients lost in one write and outputs from nowhere in a later one. A held
            // tool (its row names the tool's ids, tsid) stays where it was and is counted there.
            $bot = $features['craftybot'] ?? null;
            foreach (is_array($bot['queue'] ?? null) ? $bot['queue'] : [] as $job) {
                foreach (['general', 'complete'] as $h) {
                    $rows = is_array($job) && is_array($job['holder'][$h] ?? null) ? $job['holder'][$h] : [];
                    foreach ($rows as $class => $row) {
                        if (is_array($row) && empty($row['tsid'])) {
                            $add($class, $row['count'] ?? 0);
                        }
                    }
                }
            }
        }
        $dropped = self::decode($entries['droppedItems'] ?? null);
        if (is_array($dropped)) {
            foreach ((array) ($dropped['streets'] ?? []) as $rows) {
                foreach ((array) $rows as $row) {
                    if (is_array($row)) {
                        $add($row['classTsid'] ?? null, $row['count'] ?? 0);
                        // A dropped bag is the bag and what it holds (js/world-items.js contents;
                        // items/include/takeable.js takeable_drop moves a bag with its contents).
                        foreach (is_array($row['contents'] ?? null) ? $row['contents'] : [] as $it) {
                            if (is_array($it)) {
                                $add($it['classTsid'] ?? null, $it['count'] ?? 0);
                            }
                        }
                    }
                }
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => $currants];
    }

    /**
     * The trade ids the save says it has applied (js/trade.js normalizeSlice),
     * and the street project ids (G346: a contribution taken out, a phase
     * reward put in; features/street-jobs-feature.js keeps them in its own
     * slice's `applied`), and the Feat records (G345: a shared Feat's reward,
     * features/solo-feats-feature.js keeps them in its slice's `applied` in
     * multiplayer).
     */
    public static function applied(array $entries): array
    {
        $state = self::decode($entries['playerState'] ?? null);
        $features = is_array($state) ? ($state['systems']['features'] ?? []) : [];
        $out = [];
        foreach ([self::TRADE_SLICE, self::PROJECT_SLICE, self::FEAT_SLICE] as $slice) {
            $list = is_array($features) ? ($features[$slice]['applied'] ?? []) : [];
            foreach (is_array($list) ? $list : [] as $id) {
                if (is_string($id) && $id !== '') {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }

    /** A street project's ledger record (Ledger::project): its id starts so (projects.go). */
    public static function isProject(string $id): bool
    {
        return str_starts_with($id, self::PROJECT_PREFIX);
    }

    /** A shared Feat's ledger record (LedgerService::feat): its id starts so (feats.go). */
    public static function isFeat(string $id): bool
    {
        return str_starts_with($id, self::FEAT_PREFIX);
    }

    /** A trade's goods from the realtime server as counts, tradeable classes only. */
    public static function goods(mixed $goods): array
    {
        $items = [];
        foreach (is_array($goods['items'] ?? null) ? $goods['items'] : [] as $it) {
            if (is_array($it) && is_string($it['c'] ?? null) && self::count($it['n'] ?? 0) > 0) {
                $items[$it['c']] = ($items[$it['c']] ?? 0) + self::count($it['n']);
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => self::count($goods['currants'] ?? 0)];
    }

    // —— Comparing ——

    /**
     * What a save is expected to hold: the ledger, with every settled trade
     * this save has not applied yet undone (it still holds what it gave and
     * lacks what it got). $pending: list of ['gave' => counts, 'got' => counts].
     */
    public static function view(array $ledger, array $pending): array
    {
        $items = $ledger['items'];
        $currants = $ledger['currants'];
        foreach ($pending as $p) {
            foreach ($p['gave']['items'] as $c => $n) {
                $items[$c] = ($items[$c] ?? 0) + $n;
            }
            foreach ($p['got']['items'] as $c => $n) {
                $items[$c] = ($items[$c] ?? 0) - $n;
            }
            $currants += $p['gave']['currants'] - $p['got']['currants'];
        }
        return ['items' => $items, 'currants' => $currants];
    }

    /**
     * Compares a save's holdings with the expected view and decides what the
     * write keeps. Losses always stand. Gains a transform explains from the
     * write's own losses stand outside the budget (explain), and the losses
     * they used refund nothing. The other losses pay for this write's other
     * gains first (a vendor sale's currants, a purchase's item: an exchange
     * within the write) and are never banked: what they do not pay for is
     * gone, so the budget never ends a write above where it started (G742,
     * ours: selling below value or giving things away earned budget before).
     * The other gains stand smallest first (by their whole value) while the
     * write's losses and then the budget last, so a forged pile never crowds
     * out an honest pick. A credit-only class never stands as a gain; a capped
     * class stands at most $capsLeft[class] (default its daily cap), and what
     * of that the budget cannot pay yet waits (held, G895: up to
     * CAPPED_HOLD_VALUE a write; G905: a drop class too, Economy::drop, and
     * what waited last write, $waited, is paid first). Returns the changes that stand (to add to
     * the ledger), what was clamped, what waits, the budget left, what
     * transforms explained and the capped counts taken.
     *
     * @param array<string,int>|null $capsLeft capped class -> gains left today (null: a full day)
     * @param array<string,int> $waited class -> how many waited on the last write (paid first)
     * G905: spent, the losses no transform used and no gain consumed (what may wait in the pool).
     *
     * @return array{delta: array{items: array<string,int>, currants: int}, clamped: array{items: array<string,int>, currants: int}, held: array<string,int>, budget: float, gained: float, made: array<string,int>, runs: array<string,int>, capped: array<string,int>, spent: array<string,int>}
     */
    public static function reconcile(array $view, array $holdings, float $budget, float $capacity, Economy $economy, ?array $capsLeft = null, array $waited = []): array
    {
        $delta = ['items' => [], 'currants' => 0];
        $clamped = ['items' => [], 'currants' => 0];
        $loss = [];
        $gain = [];
        foreach (array_unique(array_merge(array_keys($view['items']), array_keys($holdings['items']))) as $c) {
            $c = (string) $c;
            // #217: a letter's receipt may carry a class the ledger does not carry (LedgerMail unpriced);
            // holdings never count one, so it is no loss or gain here.
            if (!$economy->tradeable($c)) {
                continue;
            }
            $d = ($holdings['items'][$c] ?? 0) - ($view['items'][$c] ?? 0);
            if ($d < 0) {
                $delta['items'][$c] = $d;
                $loss[$c] = -$d;
            } elseif ($d > 0) {
                $gain[$c] = $d;
            }
        }
        $x = self::explain($gain, $loss, $economy, $budget);
        // G742: the losses' value pays this write's gains first and is never banked (see above).
        $kept = $budget;
        foreach ($loss as $c => $n) {
            $budget += ($n - ($x['used'][$c] ?? 0)) * $economy->value((string) $c);
        }
        $gains = [];
        foreach ($gain as $c => $n) {
            $made = $x['made'][$c] ?? 0;
            if ($made > 0) {
                $delta['items'][$c] = $made;
            }
            $left = $n - $made;
            // G905: what waited last write is paid first (its own group, ahead of the rest).
            $first = $economy->drop((string) $c) ? min($left, max(0, (int) ($waited[$c] ?? 0))) : 0;
            if ($first > 0) {
                $gains[] = [(string) $c, $first, $economy->value((string) $c), 0];
            }
            if ($left - $first > 0) {
                $gains[] = [(string) $c, $left - $first, $economy->value((string) $c), 1];
            }
        }
        $dc = $holdings['currants'] - $view['currants'];
        if ($dc < 0) {
            $delta['currants'] = $dc;
            $budget += -$dc;
        } elseif ($dc > 0) {
            $gains[] = [null, $dc, 1.0, 1];
        }
        usort($gains, static fn ($a, $b) => $a[3] <=> $b[3] ?: ($a[1] * $a[2] <=> $b[1] * $b[2] ?: strcmp((string) $a[0], (string) $b[0])));
        $gained = 0.0;
        $capped = [];
        $held = [];
        $holdRoom = self::CAPPED_HOLD_VALUE;
        $capLeft = [];
        foreach ($gains as [$c, $n, $v]) {
            $most = $n;
            if ($c !== null && $economy->creditOnly($c)) {
                $most = 0;
            } elseif ($c !== null && $economy->dayCap($c) > 0) {
                // A class split in two (what waited, then the rest) shares one day's cap.
                $capLeft[$c] ??= max(0, (int) ($capsLeft[$c] ?? $economy->dayCap($c)));
                $most = min($n, $capLeft[$c]);
            }
            $keep = (int) min($most, floor(($budget + 1e-9) / $v));
            if ($c !== null && isset($capLeft[$c])) {
                $capLeft[$c] -= $keep;
            }
            $budget -= $keep * $v;
            $gained += $keep * $v;
            if ($c === null) {
                $delta['currants'] = $keep;
                if ($keep < $n) {
                    $clamped['currants'] = $n - $keep;
                }
                continue;
            }
            if ($keep > 0) {
                $delta['items'][$c] = ($delta['items'][$c] ?? 0) + $keep;
                if ($economy->dayCap($c) > 0) {
                    $capped[$c] = ($capped[$c] ?? 0) + $keep;
                }
            }
            // G895: a capped drop within today's cap that only the budget is short for waits in the save
            // (outside the ledger, so it cannot be traded or mailed) and is tried again on the next write.
            // G905: so does a class a drop table gives besides an ordinary source (Economy::drop), and one
            // find worth more than the whole room may wait alone.
            $hold = 0;
            if ($economy->drop($c) && $keep < $most) {
                $hold = (int) min($most - $keep, floor(($holdRoom + 1e-9) / $v));
                if ($hold === 0 && $held === [] && $holdRoom >= self::CAPPED_HOLD_VALUE) {
                    $hold = 1;
                }
                $holdRoom -= $hold * $v;
                if ($hold > 0) {
                    $held[$c] = ($held[$c] ?? 0) + $hold;
                }
                if (isset($capLeft[$c])) {
                    $capLeft[$c] -= $hold;
                }
            }
            if ($keep + $hold < $n) {
                $clamped['items'][$c] = ($clamped['items'][$c] ?? 0) + $n - $keep - $hold;
            }
        }
        ksort($delta['items']);
        ksort($clamped['items']);
        ksort($capped);
        ksort($held);
        // G905: the losses no transform used and the write's gains did not consume (the losses pay
        // first, so what is left is their value past what was kept, shared out class by class).
        $left = [];
        $lossValue = max(0, -$dc);
        foreach ($loss as $c => $n) {
            $k = $n - ($x['used'][$c] ?? 0);
            if ($k > 0) {
                $left[(string) $c] = $k;
                $lossValue += $k * $economy->value((string) $c);
            }
        }
        $share = $lossValue > 0 ? max(0.0, $lossValue - $gained) / $lossValue : 0.0;
        $spent = [];
        foreach ($left as $c => $k) {
            $m = (int) floor($k * $share + 1e-9);
            if ($m > 0) {
                $spent[$c] = $m;
            }
        }
        ksort($spent);
        return ['delta' => $delta, 'clamped' => $clamped, 'held' => $held, 'budget' => max(0.0, min($kept, $capacity, $budget)), 'gained' => $gained, 'made' => $x['made'], 'runs' => $x['runs'], 'capped' => $capped, 'spent' => $spent];
    }

    /**
     * The gains a write's own losses explain through transforms
     * (Economy::producing): the most valuable gains first, each from the
     * transforms that make it, as many runs as the losses cover; an input
     * the losses lack may itself be made from other losses (EXPLAIN_DEPTH),
     * and what such a step makes beyond the need is not kept anywhere. A
     * roll (a Cubimal box) whose outcome is worth more than its inputs
     * charges the difference to the budget, so always choosing the rarest
     * figure is not free. Returns what was made (to stand outside the
     * budget), the losses used (which refund nothing) and the runs.
     *
     * @param array<string,int> $gain
     * @param array<string,int> $loss
     * $only (G905): the transforms it may use (null: all).
     *
     * @return array{made: array<string,int>, used: array<string,int>, runs: array<string,int>}
     */
    public static function explain(array $gain, array $loss, Economy $economy, float &$budget, ?callable $only = null): array
    {
        $out = ['made' => [], 'used' => [], 'runs' => []];
        if ($gain === [] || $loss === []) {
            return $out;
        }
        $order = array_keys($gain);
        usort($order, static fn ($a, $b) => $gain[$b] * $economy->value((string) $b) <=> $gain[$a] * $economy->value((string) $a) ?: strcmp((string) $a, (string) $b));
        $need = $gain;
        $state = ['avail' => $loss, 'used' => [], 'budget' => $budget, 'runs' => []];
        foreach ($order as $c) {
            $c = (string) $c;
            foreach ($economy->producing($c) as $t) {
                if (($need[$c] ?? 0) <= 0) {
                    break;
                }
                if ($only !== null && !$only($t)) {
                    continue;
                }
                $want = (int) ceil($need[$c] / max(1, (int) $t['o'][$c]));
                // A dry run says how many the losses cover; the real run then takes exactly that many.
                $try = $state;
                $k = self::run($t, $want, $try, $economy, self::EXPLAIN_DEPTH, $only);
                if ($k <= 0) {
                    continue;
                }
                self::run($t, $k, $state, $economy, self::EXPLAIN_DEPTH, $only);
                foreach ($t['o'] as $o => $q) {
                    $o = (string) $o;
                    $m = min($need[$o] ?? 0, $k * (int) $q);
                    if ($m > 0) {
                        $out['made'][$o] = ($out['made'][$o] ?? 0) + $m;
                        $need[$o] -= $m;
                    }
                }
            }
        }
        $budget = $state['budget'];
        ksort($out['made']);
        ksort($state['used']);
        ksort($state['runs']);
        $out['used'] = $state['used'];
        $out['runs'] = $state['runs'];
        return $out;
    }

    /**
     * Runs a transform up to $want times from the state's losses (making a
     * short input from other losses while $depth lasts), taking what it uses.
     * Returns the runs made. The state: avail (losses left), used (losses
     * taken), budget, runs.
     */
    private static function run(array $t, int $want, array &$state, Economy $economy, int $depth, ?callable $only = null): int
    {
        $k = $want;
        // A roll worth more than its inputs pays the difference from the budget.
        if (!empty($t['r'])) {
            $premium = 0.0;
            foreach ($t['o'] as $o => $q) {
                $premium += (int) $q * $economy->value((string) $o);
            }
            foreach ($t['i'] as $i => $q) {
                $premium -= (int) $q * $economy->value((string) $i);
            }
            if ($premium > 0) {
                $k = min($k, (int) floor(($state['budget'] + 1e-9) / $premium));
            }
        }
        if ($k <= 0) {
            return 0;
        }
        foreach ($t['i'] as $i => $q) {
            $i = (string) $i;
            $q = max(1, (int) $q);
            $have = (int) ($state['avail'][$i] ?? 0);
            if ($have < $k * $q && $depth > 0) {
                // Make the shortfall from other losses: what is made stands in for a loss of this input.
                $short = $k * $q - $have;
                foreach ($economy->producing($i) as $sub) {
                    if ($short <= 0) {
                        break;
                    }
                    if (isset($sub['i'][$i]) || ($only !== null && !$only($sub))) {
                        continue;
                    }
                    $per = max(1, (int) $sub['o'][$i]);
                    $try = $state;
                    $m = self::run($sub, (int) ceil($short / $per), $try, $economy, $depth - 1, $only);
                    if ($m > 0) {
                        self::run($sub, $m, $state, $economy, $depth - 1, $only);
                        $state['made'][$i] = ($state['made'][$i] ?? 0) + $m * $per;
                        $short -= $m * $per;
                    }
                }
            }
            $k = min($k, intdiv((int) ($state['avail'][$i] ?? 0) + (int) ($state['made'][$i] ?? 0), $q));
        }
        if ($k <= 0) {
            return 0;
        }
        foreach ($t['i'] as $i => $q) {
            $i = (string) $i;
            $take = $k * max(1, (int) $q);
            // Made stand-ins first, then real losses (only those count as used).
            $fromMade = min($take, (int) ($state['made'][$i] ?? 0));
            if ($fromMade > 0) {
                $state['made'][$i] -= $fromMade;
            }
            $fromLoss = $take - $fromMade;
            if ($fromLoss > 0) {
                $state['avail'][$i] -= $fromLoss;
                $state['used'][$i] = ($state['used'][$i] ?? 0) + $fromLoss;
            }
        }
        if (!empty($t['r'])) {
            $premium = 0.0;
            foreach ($t['o'] as $o => $q) {
                $premium += (int) $q * $economy->value((string) $o);
            }
            foreach ($t['i'] as $i => $q) {
                $premium -= (int) $q * $economy->value((string) $i);
            }
            if ($premium > 0) {
                $state['budget'] -= $k * $premium;
            }
        }
        $state['runs'][$t['s']] = ($state['runs'][$t['s']] ?? 0) + $k;
        return $k;
    }

    /**
     * A whole save write against the ledger, as Ledger::write runs it: the
     * write's credits go into the ledger first, then the save is compared
     * with that (and with settled trades it has not applied yet). Returns
     * the ledger after, the credit result, the reconcile result and the
     * credited rows to store (credits and today's capped counts).
     *
     * G895: then what the save's records say it earned (earned), as many as
     * the write shows beyond the ledger, before the budget.
     *
     * Slice 3: $receipts are the realtime server's outcomes not yet shown
     * (LedgerCredits). Each gain they cover stands exactly, outside the
     * budget and before any transform, and 'allowed' says how much of them
     * the write used; a receipt the save does not show is no loss.
     *
     * @param array<string, array<string,int>> $credited kind -> ref -> n (daily kinds: today's)
     * @param array{items: array<string,int>, currants: int}|null $receipts
     * G905: receipts the write does not show as themselves may stand for what a transform made from
     * them in the same write ('inputs': the receipts so used, for LedgerCredits::used).
     *
     * @return array{after: array, credits: array, reconciled: array, rows: list<array{0: string, 1: string, 2: int}>, allowed: array{items: array<string,int>, currants: int}, held: array{items: array<string,int>, currants: int}, inputs: array{items: array<string,int>, currants: int}}
     */
    public static function check(array $ledger, array $credited, array $pending, array $entries, float $budget, float $capacity, Economy $economy, ?array $receipts = null, ?callable $feats = null, int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $credits = self::credits($entries, $credited, $economy);
        $owed = self::add($ledger, $credits['goods']);
        $view = self::view($owed, $pending);
        $holdings = self::holdings($entries, $economy);
        $allowed = self::covered($view, $holdings, $receipts ?? ['items' => [], 'currants' => 0]);
        $owed = self::add($owed, $allowed);
        foreach ($allowed['items'] as $c => $n) {
            $view['items'][$c] = ($view['items'][$c] ?? 0) + $n;
        }
        $view['currants'] += $allowed['currants'];
        // The house's starter furniture, once (homeKit): only what the write shows beyond the ledger.
        $kit = self::homeKit($entries, $credited, $economy);
        $kitIn = self::covered($view, $holdings, $kit['goods']);
        $owed = self::add($owed, $kitIn);
        foreach ($kitIn['items'] as $c => $n) {
            $view['items'][$c] = ($view['items'][$c] ?? 0) + $n;
            $credits['goods']['items'][$c] = ($credits['goods']['items'][$c] ?? 0) + $n;
        }
        if ($kit['rows'] !== []) {
            ksort($credits['goods']['items']);
            $credits['rows'] = array_merge($credits['rows'], $kit['rows']);
            $credits['detail']['homeKit'] = (object) $kitIn['items'];
        }
        // G905: the tutorial's and the practice streets' gifts, once per character, by the save's record.
        $tut = self::tutorial($entries, $credited, $economy);
        $tutIn = self::covered($view, $holdings, $tut['goods']);
        $owed = self::add($owed, $tutIn);
        foreach ($tutIn['items'] as $c => $n) {
            $view['items'][$c] = ($view['items'][$c] ?? 0) + $n;
            $credits['goods']['items'][$c] = ($credits['goods']['items'][$c] ?? 0) + $n;
        }
        // G948: a set's currants (the island rock's 978), as much as the write shows beyond the view.
        $view['currants'] += $tutIn['currants'];
        $credits['goods']['currants'] += $tutIn['currants'];
        if ($tutIn['items'] !== [] || $tutIn['currants'] > 0) {
            ksort($credits['goods']['items']);
            $credits['rows'] = array_merge($credits['rows'], self::tutorialRows($tut['refs'], $tutIn['items'] + ($tutIn['currants'] > 0 ? ['$' => $tutIn['currants']] : []), $credited));
            $credits['detail']['tutorial'] = (object) ($tutIn['items'] + ($tutIn['currants'] > 0 ? ['currants' => $tutIn['currants']] : []));
        }
        // G895: what the save's own records say it earned (a shrine's emblems, a donation's tickets, a
        // cracked rock's pieces), outside the budget; pieces count toward their class's daily cap.
        $capsLeft = [];
        foreach ($credited['cap'] ?? [] as $c => $n) {
            $capsLeft[(string) $c] = max(0, $economy->dayCap((string) $c) - (int) $n);
        }
        $earned = self::earned($view, $holdings, $entries, $credited, $economy, $capsLeft, $feats);
        $owed = self::add($owed, $earned['goods']);
        foreach ([$earned['goods'], $earned['held']] as $g) {
            foreach ($g['items'] as $c => $n) {
                $view['items'][$c] = ($view['items'][$c] ?? 0) + $n;
            }
        }
        foreach ($earned['goods']['items'] as $c => $n) {
            $credits['goods']['items'][$c] = ($credits['goods']['items'][$c] ?? 0) + $n;
        }
        ksort($credits['goods']['items']);
        $credits['rows'] = array_merge($credits['rows'], $earned['rows']);
        if ($earned['detail'] !== []) {
            $credits['detail']['earned'] = $earned['detail'];
        }
        // Report #246: a credit waiting for a later day's count (a paying level-up or card purchase past
        // today's) is never clamped. As much of it as the write shows stands in the save, outside the
        // ledger and the budget: it is in the view but not owed, so the ledger stays short of the save by
        // that much until the later day credits it (then the two agree, and nothing is paid twice), and
        // no trade, letter or sale can move it on before then (each checks the ledger).
        $held = self::covered($view, $holdings, $credits['defer']);
        foreach ($held['items'] as $c => $n) {
            $view['items'][$c] = ($view['items'][$c] ?? 0) + $n;
        }
        $view['currants'] += $held['currants'];
        $held = self::add($held, $earned['held']);
        // G905: the realtime server's receipts the write does not show as themselves may be what the
        // write turned into something else (chunks mined then smelted or crushed, planks cut then made
        // into boards): their transforms' output stands outside the budget, and the receipts are used.
        $unused = self::less($receipts ?? ['items' => [], 'currants' => 0], $allowed);
        $fromReceipts = self::fromPool($view, $holdings, $unused['items'], $economy, false);
        $owed = self::add($owed, $fromReceipts['goods']);
        foreach ($fromReceipts['goods']['items'] as $c => $n) {
            $view['items'][$c] = ($view['items'][$c] ?? 0) + $n;
        }
        if ($fromReceipts['goods']['items'] !== []) {
            $credits['detail']['made from receipts'] = (object) $fromReceipts['goods']['items'];
        }
        // G905: the output of timed production whose inputs an earlier write spent (the pool).
        $pool = self::poolOf($credited, $now);
        $timed = self::fromPool($view, $holdings, $pool['avail'], $economy);
        $owed = self::add($owed, $timed['goods']);
        foreach ($timed['goods']['items'] as $c => $n) {
            $view['items'][$c] = ($view['items'][$c] ?? 0) + $n;
        }
        if ($timed['goods']['items'] !== []) {
            $credits['detail']['timed'] = (object) $timed['goods']['items'];
        }
        $waited = [];
        foreach ((array) ($credited[self::WAITED] ?? []) as $c => $n) {
            $waited[(string) $c] = (int) $n;
        }
        $r = self::reconcile($view, $holdings, $budget, $capacity, $economy, $capsLeft, $waited);
        $rows = $credits['rows'];
        // G905: what waits now is remembered for the next write (0 once it no longer waits).
        foreach (array_unique(array_merge(array_keys($waited), array_keys($r['held']))) as $c) {
            $n = (int) ($r['held'][$c] ?? 0);
            if ($n !== ($waited[$c] ?? 0)) {
                $rows[] = [self::WAITED, (string) $c, $n];
            }
        }
        $rows = array_merge($rows, self::poolRows($pool, $timed['used'], $r['spent'], $economy, $now));
        $capped = $earned['capped'];
        foreach ($r['capped'] as $c => $n) {
            $capped[$c] = ($capped[$c] ?? 0) + $n;
        }
        ksort($capped);
        foreach ($capped as $c => $n) {
            $rows[] = ['cap', (string) $c, (int) ($credited['cap'][$c] ?? 0) + $n];
        }
        return ['after' => self::add($owed, $r['delta']), 'credits' => $credits, 'reconciled' => $r, 'rows' => $rows, 'allowed' => $allowed, 'held' => $held, 'inputs' => ['items' => $fromReceipts['used'], 'currants' => 0]];
    }

    /**
     * G905: the pool's spent inputs as credited rows hold them (ref "<class>@<YmdH>"), within
     * POOL_HOURS of $now: avail, class -> n; buckets, class -> list of [ref, n] oldest first; stale,
     * the refs past it (put to 0, so Ledger deletes them).
     *
     * @return array{avail: array<string,int>, buckets: array<string, list<array{0: string, 1: int}>>, stale: list<string>}
     */
    public static function poolOf(array $credited, int $now): array
    {
        $out = ['avail' => [], 'buckets' => [], 'stale' => []];
        $from = gmdate('YmdH', $now - self::POOL_HOURS * 3600);
        $rows = (array) ($credited[self::POOL] ?? []);
        ksort($rows);
        foreach ($rows as $ref => $n) {
            $ref = (string) $ref;
            if (preg_match('/^([a-z0-9_]{1,64})@(\d{10})$/', $ref, $m) !== 1 || (int) $n <= 0) {
                continue;
            }
            if (strcmp($m[2], $from) < 0) {
                $out['stale'][] = $ref;
                continue;
            }
            $out['buckets'][$m[1]][] = [$ref, (int) $n, $m[2]];
            $out['avail'][$m[1]] = ($out['avail'][$m[1]] ?? 0) + (int) $n;
        }
        foreach ($out['buckets'] as $c => $list) {
            usort($list, static fn ($a, $b) => strcmp($a[2], $b[2]));
            $out['buckets'][$c] = array_map(static fn ($b) => [$b[0], $b[1]], $list);
        }
        ksort($out['avail']);
        return $out;
    }

    /**
     * G905: gains a write shows beyond the expected view that the pool's spent inputs explain:
     * first the output of timed transforms only (economy w, explain), then an input that came back as
     * itself. Returns the goods that stand (outside the budget) and the pool inputs used. $pool false:
     * the realtime server's unused receipts in the same write instead (any transform, nothing comes
     * back as itself: covered took those already).
     *
     * @param array<string,int> $avail
     * @return array{goods: array{items: array<string,int>, currants: int}, used: array<string,int>}
     */
    public static function fromPool(array $view, array $holdings, array $avail, Economy $economy, bool $pool = true): array
    {
        $out = ['goods' => ['items' => [], 'currants' => 0], 'used' => []];
        if ($avail === []) {
            return $out;
        }
        $gain = [];
        foreach ($holdings['items'] as $c => $n) {
            $d = (int) $n - (int) ($view['items'][$c] ?? 0);
            if ($d > 0 && $economy->tradeable((string) $c)) {
                $gain[(string) $c] = $d;
            }
        }
        $none = 0.0;
        $x = self::explain($gain, $avail, $economy, $none, $pool ? static fn (array $t): bool => !empty($t['w']) : static fn (array $t): bool => empty($t['r']));
        foreach ($x['made'] as $c => $n) {
            $out['goods']['items'][$c] = $n;
            $gain[$c] -= $n;
        }
        $out['used'] = $x['used'];
        if (!$pool) {
            ksort($out['goods']['items']);
            return $out;
        }
        // A pooled input that comes back stands as itself (a failed job's ingredients, collected).
        foreach ($avail as $c => $n) {
            $k = min($n - ($out['used'][$c] ?? 0), $gain[$c] ?? 0);
            if ($k > 0) {
                $out['goods']['items'][$c] = ($out['goods']['items'][$c] ?? 0) + $k;
                $out['used'][$c] = ($out['used'][$c] ?? 0) + $k;
            }
        }
        ksort($out['goods']['items']);
        ksort($out['used']);
        return $out;
    }

    /**
     * G905: the pool's rows after a write: what it used comes out oldest first, the timed inputs this
     * write spent go into this hour's bucket, and stale buckets go to 0.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function poolRows(array $pool, array $used, array $spent, Economy $economy, int $now): array
    {
        $n = [];
        foreach ($pool['buckets'] as $c => $list) {
            $take = (int) ($used[$c] ?? 0);
            foreach ($list as [$ref, $have]) {
                $t = min($take, $have);
                $take -= $t;
                if ($t > 0) {
                    $n[$ref] = $have - $t;
                }
            }
        }
        $hour = gmdate('YmdH', $now);
        foreach ($spent as $c => $k) {
            if ($k > 0 && $economy->timedInput((string) $c)) {
                $ref = "{$c}@{$hour}";
                $was = 0;
                foreach ($pool['buckets'][$c] ?? [] as [$r, $have]) {
                    if ($r === $ref) {
                        $was = $have;
                    }
                }
                $n[$ref] = ($n[$ref] ?? $was) + $k;
            }
        }
        foreach ($pool['stale'] as $ref) {
            $n[$ref] = 0;
        }
        ksort($n);
        $rows = [];
        foreach ($n as $ref => $k) {
            $rows[] = [self::POOL, (string) $ref, $k];
        }
        return $rows;
    }

    /**
     * How much of a write's gains (holdings over the expected view) the
     * receipts cover, class by class and in currants.
     *
     * @return array{items: array<string,int>, currants: int}
     */
    public static function covered(array $view, array $holdings, array $receipts): array
    {
        $items = [];
        foreach ($receipts['items'] as $c => $n) {
            $k = min((int) $n, ($holdings['items'][$c] ?? 0) - ($view['items'][$c] ?? 0));
            if ($k > 0) {
                $items[(string) $c] = $k;
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => max(0, min((int) $receipts['currants'], $holdings['currants'] - $view['currants']))];
    }

    /** G905: a less b, class by class (never below 0). */
    public static function less(array $a, array $b): array
    {
        $items = [];
        foreach ($a['items'] as $c => $n) {
            $k = (int) $n - (int) ($b['items'][$c] ?? 0);
            if ($k > 0) {
                $items[(string) $c] = $k;
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => max(0, (int) $a['currants'] - (int) $b['currants'])];
    }

    /** The ledger with changes added (counts never below 0). */
    public static function add(array $ledger, array $delta): array
    {
        $items = $ledger['items'];
        foreach ($delta['items'] as $c => $n) {
            $items[$c] = max(0, ($items[$c] ?? 0) + $n);
            if ($items[$c] === 0) {
                unset($items[$c]);
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => max(0, $ledger['currants'] + $delta['currants'])];
    }

    /** Whether goods are empty. */
    public static function nothing(array $goods): bool
    {
        return $goods['items'] === [] && $goods['currants'] === 0;
    }

    // —— Editing a stored save ——

    /**
     * Takes clamped goods out of a save: the pack and purse first, then the
     * trade hold, home furniture storage, dropped stacks, display boxes,
     * cabinets and placed furniture. A cabinet taken away leaves what is left
     * in it in the pack (ticket 51). Returns the edited entries.
     */
    public static function remove(array $entries, array $goods): array
    {
        $state = self::objects($entries['playerState'] ?? null);
        $dropped = self::objects($entries['droppedItems'] ?? null);
        $want = $goods['items'];
        // The pack keeps a class it ran out of at 0, as the game does (js/inventory.js removeItem):
        // an absent key reads as a save from before a starter grant, and the boot grants it again
        // (game.js the Hatchet and Paradise tickets; resource-nodes-feature.js the kit).
        $take = static function (object $map, string $c, bool $keepZero = false) use (&$want): void {
            if (($want[$c] ?? 0) <= 0 || !isset($map->{$c})) {
                return;
            }
            $have = self::count($map->{$c});
            $n = min($have, $want[$c]);
            $want[$c] -= $n;
            if ($have - $n > 0 || $keepZero) {
                $map->{$c} = $have - $n;
            } else {
                unset($map->{$c});
            }
        };
        $cur = $goods['currants'];
        if ($state instanceof \stdClass) {
            if (($state->inventory ?? null) instanceof \stdClass) {
                foreach (array_keys($want) as $c) {
                    $take($state->inventory, (string) $c, true);
                }
            }
            if (($state->player ?? null) instanceof \stdClass && $cur > 0) {
                $have = self::count($state->player->currants ?? 0);
                $n = min($have, $cur);
                $state->player->currants = $have - $n;
                $cur -= $n;
            }
            $hold = $state->systems->features->trade->hold ?? null;
            if ($hold instanceof \stdClass) {
                if (($hold->items ?? null) instanceof \stdClass) {
                    foreach (array_keys($want) as $c) {
                        $take($hold->items, $c);
                    }
                }
                if ($cur > 0) {
                    $have = self::count($hold->currants ?? 0);
                    $n = min($have, $cur);
                    $hold->currants = $have - $n;
                    $cur -= $n;
                }
            }
            $homes = $state->systems->features->homes ?? null;
            $decors = [];
            if ($homes instanceof \stdClass) {
                if (($homes->decor ?? null) instanceof \stdClass) {
                    $decors[] = $homes->decor;
                }
                $upper = ($homes->floors ?? null) instanceof \stdClass ? ($homes->floors->upper ?? null) : null;
                foreach (array_merge(is_array($homes->towerDecor ?? null) ? $homes->towerDecor : [], is_array($upper) ? $upper : []) as $d) {
                    if ($d instanceof \stdClass) {
                        $decors[] = $d;
                    }
                }
            }
            foreach ($decors as $d) {
                if (($d->storage ?? null) instanceof \stdClass) {
                    foreach (array_keys($want) as $c) {
                        $take($d->storage, $c);
                    }
                }
            }
        }
        if ($dropped instanceof \stdClass && ($dropped->streets ?? null) instanceof \stdClass) {
            foreach (get_object_vars($dropped->streets) as $street => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                $keep = [];
                foreach ($rows as $row) {
                    // What a dropped bag holds first, then the bag (a clamped bag's contents go to the pack).
                    if (is_array($row->contents ?? null)) {
                        $inside = [];
                        foreach ($row->contents as $it) {
                            $ic = $it->classTsid ?? null;
                            if ($it instanceof \stdClass && is_string($ic) && ($want[$ic] ?? 0) > 0) {
                                $n = min(self::count($it->count ?? 0), $want[$ic]);
                                $want[$ic] -= $n;
                                $it->count = self::count($it->count ?? 0) - $n;
                                if ($it->count <= 0) {
                                    continue;
                                }
                            }
                            $inside[] = $it;
                        }
                        $row->contents = $inside;
                    }
                    $c = $row->classTsid ?? null;
                    if (is_string($c) && ($want[$c] ?? 0) > 0) {
                        $n = min(self::count($row->count ?? 0), $want[$c]);
                        $want[$c] -= $n;
                        $row->count = self::count($row->count ?? 0) - $n;
                        if ($row->count <= 0) {
                            if ($state instanceof \stdClass && is_array($row->contents ?? null)) {
                                if (!($state->inventory ?? null) instanceof \stdClass) {
                                    $state->inventory = new \stdClass();
                                }
                                foreach ($row->contents as $it) {
                                    $ic = $it->classTsid ?? null;
                                    if (is_string($ic)) {
                                        $state->inventory->{$ic} = self::count($state->inventory->{$ic} ?? 0) + self::count($it->count ?? 0);
                                    }
                                }
                            }
                            continue;
                        }
                    }
                    $keep[] = $row;
                }
                $dropped->streets->{$street} = $keep;
            }
        }
        if ($state instanceof \stdClass) {
            foreach ($decors ?? [] as $d) {
                if (!is_array($d->placed ?? null)) {
                    continue;
                }
                $keep = [];
                foreach ($d->placed as $row) {
                    $slots = is_array($row->contents ?? null) ? $row->contents : null;
                    if ($slots !== null) {
                        for ($i = count($slots) - 1; $i >= 0; $i--) {
                            $slot = $slots[$i];
                            // #108: what a stored bag holds first, as a dropped bag's.
                            if ($slot instanceof \stdClass && is_array($slot->contents ?? null)) {
                                $inside = [];
                                foreach ($slot->contents as $it) {
                                    $ic = $it instanceof \stdClass ? ($it->classTsid ?? null) : null;
                                    if (is_string($ic) && ($want[$ic] ?? 0) > 0) {
                                        $n = min(self::count($it->count ?? 0), $want[$ic]);
                                        $want[$ic] -= $n;
                                        $it->count = self::count($it->count ?? 0) - $n;
                                        if (is_array($it->points ?? null)) {
                                            $it->points = array_slice($it->points, 0, $it->count);
                                        }
                                        if ($it->count <= 0) {
                                            continue;
                                        }
                                    }
                                    $inside[] = $it;
                                }
                                $slot->contents = $inside;
                            }
                            $sc = $slot instanceof \stdClass ? ($slot->classTsid ?? null) : null;
                            if (!is_string($sc) || ($want[$sc] ?? 0) <= 0) {
                                continue;
                            }
                            $n = min(self::count($slot->count ?? 0), $want[$sc]);
                            $want[$sc] -= $n;
                            $slot->count = self::count($slot->count ?? 0) - $n;
                            if (is_array($slot->points ?? null)) {
                                $slot->points = array_slice($slot->points, 0, $slot->count);
                            }
                            if ($slot->count <= 0) {
                                // A stored bag taken away leaves what it held in the pack.
                                self::spill($state, $slot->contents ?? null);
                                $slots[$i] = null;
                            }
                        }
                        $row->contents = $slots;
                    }
                    $disp = $row->display ?? null;
                    if ($disp instanceof \stdClass && is_string($disp->classTsid ?? null) && ($want[$disp->classTsid] ?? 0) > 0) {
                        $n = min(self::count($disp->count ?? 0), $want[$disp->classTsid]);
                        $want[$disp->classTsid] -= $n;
                        $disp->count = self::count($disp->count ?? 0) - $n;
                        if ($disp->count <= 0) {
                            unset($row->display);
                        }
                    }
                    $c = $row->classTsid ?? null;
                    if (is_string($c) && ($want[$c] ?? 0) > 0) {
                        $want[$c] -= 1;
                        foreach ($slots ?? [] as $slot) {
                            $sc = $slot instanceof \stdClass ? ($slot->classTsid ?? null) : null;
                            if (is_string($sc) && self::count($slot->count ?? 0) > 0) {
                                if (!($state->inventory ?? null) instanceof \stdClass) {
                                    $state->inventory = new \stdClass();
                                }
                                $state->inventory->{$sc} = self::count($state->inventory->{$sc} ?? 0) + self::count($slot->count ?? 0);
                                self::spill($state, $slot->contents ?? null);
                            }
                        }
                        continue;
                    }
                    $keep[] = $row;
                }
                $d->placed = $keep;
            }
        }
        if ($state !== null) {
            $entries['playerState'] = self::encode($state);
        }
        if ($dropped !== null) {
            $entries['droppedItems'] = self::encode($dropped);
        }
        return $entries;
    }

    /** #108: what a stored bag held goes into the pack (the bag or its cabinet was taken away). */
    private static function spill(\stdClass $state, mixed $contents): void
    {
        if (!is_array($contents)) {
            return;
        }
        foreach ($contents as $it) {
            $ic = $it instanceof \stdClass ? ($it->classTsid ?? null) : null;
            if (!is_string($ic) || self::count($it->count ?? 0) <= 0) {
                continue;
            }
            if (!($state->inventory ?? null) instanceof \stdClass) {
                $state->inventory = new \stdClass();
            }
            $state->inventory->{$ic} = self::count($state->inventory->{$ic} ?? 0) + self::count($it->count ?? 0);
        }
    }

    /**
     * Applies a settled trade to a stored save, as the game's settleDone does
     * (js/trade.js): what was given leaves the hold (or the pack, when the
     * save has no hold for it), the rest of the hold goes back to the pack,
     * what was got comes in, and the trade is remembered as applied. A save
     * that already lists the trade is left as it is.
     */
    public static function applyTrade(array $entries, string $trade, array $gave, array $got): array
    {
        $state = self::objects($entries['playerState'] ?? null);
        if (!$state instanceof \stdClass || in_array($trade, self::applied($entries), true)) {
            return $entries;
        }
        foreach (['inventory', 'player', 'systems'] as $k) {
            if (!($state->{$k} ?? null) instanceof \stdClass) {
                $state->{$k} = new \stdClass();
            }
        }
        if (!($state->systems->features ?? null) instanceof \stdClass) {
            $state->systems->features = new \stdClass();
        }
        // A street project's record is remembered in the Job Board's slice, a Feat's in the Feats', a trade in the trade's.
        $sliceId = self::isProject($trade) ? self::PROJECT_SLICE : (self::isFeat($trade) ? self::FEAT_SLICE : self::TRADE_SLICE);
        if (!($state->systems->features->{$sliceId} ?? null) instanceof \stdClass) {
            $state->systems->features->{$sliceId} = new \stdClass();
        }
        $slice = $state->systems->features->{$sliceId};
        $inv = $state->inventory;
        // A class the pack runs out of stays at 0, as the game keeps it (see remove).
        $put = static function (string $c, int $n) use ($inv): void {
            if ($n === 0 && !isset($inv->{$c})) {
                return;
            }
            $inv->{$c} = max(0, self::count($inv->{$c} ?? 0) + $n);
        };
        $hold = ($slice->hold ?? null) instanceof \stdClass && ($slice->hold->trade ?? null) === $trade ? $slice->hold : null;
        $held = $hold !== null && ($hold->items ?? null) instanceof \stdClass ? array_map(self::count(...), get_object_vars($hold->items)) : [];
        foreach (array_unique(array_merge(array_keys($held), array_keys($gave['items']))) as $c) {
            $put((string) $c, ($held[$c] ?? 0) - ($gave['items'][$c] ?? 0));
        }
        foreach ($got['items'] as $c => $n) {
            $put((string) $c, $n);
        }
        $purse = self::count($state->player->currants ?? 0) + ($hold !== null ? self::count($hold->currants ?? 0) : 0) - $gave['currants'] + $got['currants'];
        $state->player->currants = max(0, $purse);
        if ($hold !== null) {
            $slice->hold = null;
        }
        $applied = is_array($slice->applied ?? null) ? $slice->applied : [];
        $applied[] = $trade;
        $slice->applied = array_values(array_slice($applied, -(self::isProject($trade) ? self::PROJECT_APPLIED_KEEP : (self::isFeat($trade) ? self::FEAT_APPLIED_KEEP : self::APPLIED_KEEP))));
        $entries['playerState'] = self::encode($state);
        return $entries;
    }

    // —— Credits (slice 2) ——

    /**
     * What the save says it has done that the source pays for: completed
     * quests (systems.quests.completed), unlocked badges (systems.
     * achievements.unlocked), the level and xp (player), the reward buff
     * multiplier and the owned imagination upgrades (player), and the
     * purchases of each card (systems.features.imagination.counts,
     * js/imagination.js grantUpgrade; a card owned with no count counts once).
     *
     * and whether the house has had its starter furniture
     * (systems.features.homes.decor.starterKit, js/home-decor.js grantStarterKit).
     *
     * @return array{quests: list<string>, skills: list<string>, badges: list<string>, level: int, xp: int, mult: float, owned: list<string>, cards: array<string,int>, homeKit: bool}
     */
    public static function markers(array $entries): array
    {
        $state = self::decode($entries['playerState'] ?? null);
        $state = is_array($state) ? $state : [];
        $strings = static fn (mixed $list): array => array_values(array_unique(array_filter(is_array($list) ? $list : [], static fn ($v) => is_string($v) && $v !== '')));
        $player = is_array($state['player'] ?? null) ? $state['player'] : [];
        $owned = $strings($player['imaginationUpgrades'] ?? []);
        $cards = [];
        $counts = $state['systems']['features']['imagination']['counts'] ?? null;
        foreach (is_array($counts) ? $counts : [] as $id => $n) {
            if (is_string($id) && self::count($n) > 0) {
                $cards[$id] = self::count($n);
            }
        }
        foreach ($owned as $id) {
            $cards[$id] ??= 1;
        }
        ksort($cards);
        return [
            'quests' => $strings($state['systems']['quests']['completed'] ?? []),
            'skills' => $strings($state['systems']['skills']['learned'] ?? []),
            'badges' => $strings($state['systems']['achievements']['unlocked'] ?? []),
            'level' => max(1, self::count($player['level'] ?? 1)),
            'xp' => self::count($player['xp'] ?? 0),
            'mult' => is_numeric($player['rewardMultiplier'] ?? null) ? (float) $player['rewardMultiplier'] : 1.0,
            'owned' => $owned,
            'cards' => $cards,
            'homeKit' => ($state['systems']['features']['homes']['decor']['starterKit'] ?? null) === true,
        ];
    }

    /**
     * The house's starter furniture, credited once per character (credited
     * row home/kit): players/inc_furniture.js furniture_populate 73-113 gives
     * a new house owner its set once (furniture_create_bag 44-53), and the
     * game hands it over on the first visit inside (js/home-decor.js
     * grantStarterKit, which sets decor.starterKit). The set is the economy
     * table's credits.homeKit. It covers only what the write shows beyond
     * the ledger (check), so a character whose ledger was seeded from a save
     * that already held the set gains nothing from it.
     *
     * @return array{goods: array{items: array<string,int>, currants: int}, rows: list<array{0: string, 1: string, 2: int}>}
     */
    public static function homeKit(array $entries, array $credited, Economy $economy): array
    {
        if (isset($credited['home']['kit']) || !self::markers($entries)['homeKit']) {
            return ['goods' => ['items' => [], 'currants' => 0], 'rows' => []];
        }
        $items = [];
        foreach ((array) ($economy->credits('homeKit') ?? []) as $c => $n) {
            if (is_string($c) && $economy->tradeable($c) && self::count($n) > 0) {
                $items[$c] = self::count($n);
            }
        }
        ksort($items);
        return ['goods' => ['items' => $items, 'currants' => 0], 'rows' => [['home', 'kit', 1]]];
    }

    /**
     * G895: the save's records that earned rules follow, ref -> count: "emblem:<giant>" the
     * shrine's emblems earned (systems.shrines.emblems, js/shrines.js normalizeShrineState) and
     * "donations" the shrine donations made (systems.shrines.donations), "crack:<rock>" the
     * Petrified Rocks cracked (systems.features.artifacts.cracked) and "rocks" those held.
     *
     * @return array<string,int>
     */
    public static function marks(array $entries): array
    {
        $state = self::decode($entries['playerState'] ?? null);
        $state = is_array($state) ? $state : [];
        $out = [];
        $emblems = $state['systems']['shrines']['emblems'] ?? null;
        foreach (is_array($emblems) ? $emblems : [] as $giant => $n) {
            if (is_string($giant) && preg_match('/^[a-z]{1,16}$/', $giant) === 1) {
                $out["emblem:{$giant}"] = self::count($n);
            }
        }
        $out['donations'] = self::count($state['systems']['shrines']['donations'] ?? 0);
        // G905: the pouch's shakes (js/elemental-pouch.js createPouchState shakes).
        $out['shakes'] = self::count($state['systems']['features']['elemental-pouch']['shakes'] ?? 0);
        $cracked = $state['systems']['features']['artifacts']['cracked'] ?? null;
        $out['rocks'] = 0;
        foreach (self::CRACK_YIELD as $rock => $y) {
            $out["crack:{$rock}"] = self::count(is_array($cracked) ? ($cracked[$rock] ?? 0) : 0);
            $out['rocks'] += self::count($state['inventory'][$rock] ?? 0);
        }
        ksort($out);
        return $out;
    }

    /**
     * G895: the rows that mark what a save's records show already, without paying (the first write
     * the earned rules see for a character; Ledger::write takes them from the stored save, so what
     * the write itself earns still counts).
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function markBaseline(array $entries, int $feats = 0): array
    {
        $rows = [[self::MARK, 'base', 1], [self::MARK, 'base905', 1]];
        $marks = self::marks($entries);
        foreach ($marks as $ref => $n) {
            if ($n > 0 && !str_starts_with($ref, 'crack:') && $ref !== 'rocks') {
                $rows[] = [self::MARK, $ref, $n];
            }
        }
        // The pieces the cracks so far allowed, and what the rocks held then (and the Feat rewards since) may still give.
        $yield = self::crackYield($marks);
        $rocks = min(self::ROCKS_BASE_MAX, $marks['rocks'] ?? 0);
        foreach (['crackyield' => $yield, 'rockyield' => $yield + $rocks * max(self::CRACK_YIELD), 'feats' => max(0, $feats)] as $ref => $n) {
            if ($n > 0) {
                $rows[] = [self::MARK, $ref, $n];
            }
        }
        return $rows;
    }

    /**
     * G905: the rows that mark the records G905's rules follow, for a character the G895 baseline
     * already saw (Ledger::write, from the stored save): the pouch's shakes so far, and every tutorial
     * set whose part of the game is finished (the tutorial done, the quest completed, the Funpickle's
     * award given), without paying. A set still under way stays open, so a player in the tutorial
     * when this ships is still credited.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function markBaseline905(array $entries): array
    {
        $rows = [[self::MARK, 'base905', 1]];
        $n = self::marks($entries)['shakes'] ?? 0;
        if ($n > 0) {
            $rows[] = [self::MARK, 'shakes', $n];
        }
        foreach (self::TUTORIAL_SETS as $set => $def) {
            if (self::tutorialGate($entries, (string) $def['gate'], true)) {
                foreach ($def['items'] as $c => $most) {
                    $rows[] = [self::TUTORIAL, "{$set}:{$c}", (int) $most];
                }
                if ((int) ($def['currants'] ?? 0) > 0) {
                    $rows[] = [self::TUTORIAL, "{$set}:currants", (int) $def['currants']];
                }
            }
        }
        return $rows;
    }

    /**
     * G905: whether the save's record opens a tutorial set's gate ($finished: whether that part of the
     * game is over, for the baseline). Gates: stage:<s> the newxp record reached stage s (or the
     * tutorial is done); flag:<f> its island flag f; skill:<id>[:<quest>] its first skill was id, or
     * the skill's quest (id, else <quest>) is under way or done; quest:<q> the quest is under way or
     * done; funpickle the Funpickle's award was given; startblock the start street's Musicblock was
     * laid (its class one of the twenty); musicblockgift the 25-tune GNG gift was sent.
     */
    public static function tutorialGate(array $entries, string $gate, bool $finished = false): bool
    {
        $state = self::decode($entries['playerState'] ?? null);
        $state = is_array($state) ? $state : [];
        $features = is_array($state['systems']['features'] ?? null) ? $state['systems']['features'] : [];
        $newxp = is_array($features['newxp'] ?? null) ? $features['newxp'] : [];
        $done = ($newxp['status'] ?? '') === 'done';
        $quests = is_array($state['systems']['quests'] ?? null) ? $state['systems']['quests'] : [];
        $completed = is_array($quests['completed'] ?? null) ? $quests['completed'] : [];
        $active = is_array($quests['active'] ?? null) ? $quests['active'] : [];
        $quest = static fn (string $q): bool => in_array($q, $completed, true) || (!$finished && array_key_exists($q, $active));
        $parts = explode(':', $gate);
        switch ($parts[0]) {
            case 'stage':
                if ($done || $finished) {
                    return $done;
                }
                $want = array_search($parts[1] ?? '', self::NEWXP_STAGES, true);
                $at = array_search((string) ($newxp['stage'] ?? ''), self::NEWXP_STAGES, true);
                $stageAt = is_array($newxp['stageAt'] ?? null) ? $newxp['stageAt'] : [];
                return $want !== false && ((($newxp['status'] ?? '') === 'active' && $at !== false && $at >= $want) || !empty($stageAt[$parts[1]]));
            case 'flag':
                return $finished ? $done : in_array($parts[1] ?? '', is_array($newxp['flags'] ?? null) ? $newxp['flags'] : [], true);
            case 'skill':
                $q = $parts[2] ?? ($parts[1] ?? '');
                return $quest($q) || (($finished ? $done : true) && ($newxp['skill'] ?? '') === ($parts[1] ?? '') && ($newxp['skill'] ?? '') !== '');
            case 'quest':
                return $quest($parts[1] ?? '');
            case 'funpickle':
                return ($features['funpickle']['givenAward'] ?? null) === true;
            case 'startblock':
                return self::startBlockClass($state) !== '';
            case 'musicblockgift':
                return ($features['musicblocks']['gift'] ?? null) === true;
        }
        return false;
    }

    /**
     * G905: what the tutorial sets the save's record opens may still credit (each set's class at most
     * once per character, less what was credited before): goods, and refs class -> list of [ref, left].
     * The island's Musicblock is the class the record names (one of the island's, js/newxp.js
     * ISLAND_MUSICBLOCKS); the start street's is the class the musicblocks record names (G938). A set's
     * currants (G948) are goods currants, their refs under the key "$".
     *
     * @return array{goods: array{items: array<string,int>, currants: int}, refs: array<string, list<array{0: string, 1: int}>>}
     */
    public static function tutorial(array $entries, array $credited, Economy $economy): array
    {
        $out = ['goods' => ['items' => [], 'currants' => 0], 'refs' => []];
        $had = (array) ($credited[self::TUTORIAL] ?? []);
        $state = self::decode($entries['playerState'] ?? null);
        $block = is_array($state) ? ($state['systems']['features']['newxp']['musicblock'] ?? '') : '';
        foreach (self::TUTORIAL_SETS as $set => $def) {
            if (!self::tutorialGate($entries, (string) $def['gate'])) {
                continue;
            }
            foreach ($def['items'] as $c => $most) {
                $ref = "{$set}:{$c}";
                $class = match ((string) $c) {
                    'musicblock' => is_string($block) && preg_match('/^musicblock_[a-z]_[a-z]+_0[1-5]$/', $block) === 1 ? $block : '',
                    'startblock' => self::startBlockClass(is_array($state) ? $state : []),
                    default => (string) $c,
                };
                $left = (int) $most - (int) ($had[$ref] ?? 0);
                if ($class === '' || $left <= 0 || !$economy->tradeable($class)) {
                    continue;
                }
                $out['goods']['items'][$class] = ($out['goods']['items'][$class] ?? 0) + $left;
                $out['refs'][$class][] = [$ref, $left];
            }
            $left = (int) ($def['currants'] ?? 0) - (int) ($had["{$set}:currants"] ?? 0);
            if ($left > 0) {
                $out['goods']['currants'] += $left;
                $out['refs']['$'][] = ["{$set}:currants", $left];
            }
        }
        ksort($out['goods']['items']);
        return $out;
    }

    /** G938: the start street Musicblock's class in a decoded save (features.musicblocks.startBlock), or ''. */
    public static function startBlockClass(array $state): string
    {
        $c = $state['systems']['features']['musicblocks']['startBlock']['classTsid'] ?? '';
        return is_string($c) && preg_match(self::START_BLOCK_CLASS, $c) === 1 ? $c : '';
    }

    /**
     * G905: the credited rows for what the tutorial credited this write, each class's refs filled in
     * the sets' order.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function tutorialRows(array $refs, array $credited, array $had): array
    {
        $rows = [];
        foreach ($credited as $c => $n) {
            $n = (int) $n;
            foreach ($refs[$c] ?? [] as [$ref, $left]) {
                $k = min($n, $left);
                if ($k <= 0) {
                    break;
                }
                $n -= $k;
                $rows[] = [self::TUTORIAL, $ref, (int) ($had[self::TUTORIAL][$ref] ?? 0) + $k];
            }
        }
        return $rows;
    }

    /** G895: the most pieces and beads the cracks a save's record counts could have given (CRACK_YIELD). */
    public static function crackYield(array $marks): int
    {
        $n = 0;
        foreach (self::CRACK_YIELD as $rock => $y) {
            $n += (int) ($marks["crack:{$rock}"] ?? 0) * $y;
        }
        return $n;
    }

    /**
     * G895 (#432): gains the save's own records say it earned, credited outside the budget, as
     * many as the write shows beyond the expected view: a shrine's emblems, up to the emblems the
     * shrine record gained since it was last credited, EMBLEM_DAILY a day for all Giants; Gameshow
     * Tickets, one a shrine donation counted since, TICKET_DAILY a day; artifact pieces and beads,
     * CRACK_YIELD a crack counted since, within the rocks the Feat rewards gave ($feats() counts the
     * realtime server's signed rewards) and each class's daily cap ($capsLeft, lowered). What the
     * record allows past today's count waits (held: it stands in the save outside the ledger, up to
     * EMBLEM_WAIT, and the record keeps it owed for a later day); what the record allows and the
     * write does not show (an emblem spent in the write that earned it) is not kept owed. Returns the
     * goods to credit, what is held, the rows to store and what was credited, for the log.
     *
     * @return array{goods: array{items: array<string,int>, currants: int}, held: array{items: array<string,int>, currants: int}, rows: list<array{0: string, 1: string, 2: int}>, detail: array}
     */
    public static function earned(array $view, array $holdings, array $entries, array $credited, Economy $economy, array &$capsLeft = [], ?callable $feats = null): array
    {
        $out = ['goods' => ['items' => [], 'currants' => 0], 'held' => ['items' => [], 'currants' => 0], 'rows' => [], 'detail' => [], 'capped' => []];
        $had = (array) ($credited[self::MARK] ?? []);
        $marks = self::marks($entries);
        $gainOf = static fn (string $c): int => max(0, ($holdings['items'][$c] ?? 0) - ($view['items'][$c] ?? 0));
        // One class from one record: credits what today's room allows, holds what waits, moves the mark.
        $take = static function (string $ref, string $class, int $now, int &$room, int &$wait) use (&$out, $had, $gainOf): void {
            $was = (int) ($had[$ref] ?? 0);
            $allow = max(0, $now - $was);
            $gain = $gainOf($class);
            $c = min($allow, $gain, max(0, $room));
            $w = min($allow - $c, $gain - $c, max(0, $wait));
            $room -= $c;
            $wait -= $w;
            if ($c > 0) {
                $out['goods']['items'][$class] = ($out['goods']['items'][$class] ?? 0) + $c;
                $out['detail'][$class] = ($out['detail'][$class] ?? 0) + $c;
            }
            if ($w > 0) {
                $out['held']['items'][$class] = ($out['held']['items'][$class] ?? 0) + $w;
                $out['detail']['waits'][$class] = $w;
            }
            $mark = max($was, $now - $w);
            if ($mark !== $was) {
                $out['rows'][] = [self::MARK, $ref, $mark];
            }
        };
        // Today's count of one rule goes up by what it credited.
        $day = static function (string $rule, int $used) use (&$out, $credited): void {
            if ($used > 0) {
                $out['rows'][] = [self::MARK_DAY, $rule, (int) ($credited[self::MARK_DAY][$rule] ?? 0) + $used];
            }
        };
        // Emblems: every Giant's class shares the day's count.
        $room = max(0, self::EMBLEM_DAILY - (int) ($credited[self::MARK_DAY]['emblem'] ?? 0));
        $start = $room;
        $wait = self::EMBLEM_WAIT;
        foreach ($marks as $ref => $now) {
            $class = str_starts_with($ref, 'emblem:') ? 'emblem_' . substr($ref, 7) : '';
            if ($class !== '' && $economy->tradeable($class)) {
                $take($ref, $class, $now, $room, $wait);
            }
        }
        $day('emblem', $start - $room);
        // Gameshow Tickets: one a shrine donation at most.
        $room = max(0, self::TICKET_DAILY - (int) ($credited[self::MARK_DAY]['ticket'] ?? 0));
        $start = $room;
        $wait = self::TICKET_WAIT;
        if ($economy->tradeable(self::TICKET_CLASS)) {
            $take('donations', self::TICKET_CLASS, $marks['donations'] ?? 0, $room, $wait);
        }
        $day('ticket', $start - $room);
        // G905: Elemental Shake, the elements each new shake on the pouch record allows.
        $delta = 0.0;
        $owned = self::markers($entries)['owned'];
        foreach (self::SHAKE_CARDS as $card => $d) {
            if (in_array($card, $owned, true)) {
                $delta = max($delta, $d);
            }
        }
        $was = (int) ($had['shakes'] ?? 0);
        $fresh = max(0, ($marks['shakes'] ?? 0) - $was);
        $k = min($fresh, max(0, self::SHAKE_DAILY - (int) ($credited[self::MARK_DAY]['shake'] ?? 0)));
        if ($k > 0) {
            foreach ($delta > 0 ? self::SHAKE_ELEMENTS : [] as $class) {
                $each = (int) round(min(self::ELEMENT_STACK, max(0, (int) ($view['items'][$class] ?? 0))) * $delta);
                $c = min($gainOf($class), $k * $each);
                if ($c > 0 && $economy->tradeable($class)) {
                    $out['goods']['items'][$class] = ($out['goods']['items'][$class] ?? 0) + $c;
                    $out['detail'][$class] = ($out['detail'][$class] ?? 0) + $c;
                }
            }
            $out['rows'][] = [self::MARK, 'shakes', $was + $k];
            $day('shake', $k);
        }
        // Artifact pieces and beads: the cracks' yield, within what the Feat rewards gave rocks for.
        $was = (int) ($had['crackyield'] ?? 0);
        $yield = self::crackYield($marks);
        if ($yield > $was) {
            $rewards = $feats === null ? 0 : max(0, (int) $feats());
            $most = (int) ($had['rockyield'] ?? 0) + max(0, $rewards - (int) ($had['feats'] ?? 0)) * self::ROCKS_PER_FEAT * max(self::CRACK_YIELD);
            $top = min($yield, $most);
            $allow = max(0, $top - $was);
            $wait = self::CRACK_WAIT;
            $waiting = 0;
            foreach ($economy->assemblyInputs() as $class) {
                $gain = $gainOf($class);
                if ($gain <= 0 || $allow <= 0) {
                    continue;
                }
                $capLeft = $economy->cap($class) > 0 ? max(0, (int) ($capsLeft[$class] ?? $economy->cap($class))) : $gain;
                $c = min($gain, $allow, $capLeft);
                $allow -= $c;
                $w = min($gain - $c, $allow, $wait);
                $allow -= $w;
                $wait -= $w;
                $waiting += $w;
                if ($c > 0) {
                    $out['goods']['items'][$class] = ($out['goods']['items'][$class] ?? 0) + $c;
                    $out['detail'][$class] = ($out['detail'][$class] ?? 0) + $c;
                    if ($economy->cap($class) > 0) {
                        $capsLeft[$class] = $capLeft - $c;
                        $out['capped'][$class] = $c;
                    }
                }
                if ($w > 0) {
                    $out['held']['items'][$class] = ($out['held']['items'][$class] ?? 0) + $w;
                    $out['detail']['waits'][$class] = $w;
                }
            }
            $mark = max($was, $top - $waiting);
            if ($mark !== $was) {
                $out['rows'][] = [self::MARK, 'crackyield', $mark];
            }
        }
        ksort($out['goods']['items']);
        ksort($out['held']['items']);
        return $out;
    }

    /** js/progression.js levelInfoForXp: the level a lifetime xp total reaches. */
    public static function levelFor(int $xp, Economy $economy): int
    {
        $level = 1;
        foreach ((array) $economy->credits('xp') as $need) {
            if ($xp < (int) $need) {
                break;
            }
            $level++;
        }
        return $level;
    }

    /** common.js round_to_5 (js/quests.js roundTo5): a remainder of 2.5 rounds up. */
    public static function roundTo5(float $v): int
    {
        $t = (int) ($v / 5) * 5;
        return fmod($v, 5) >= 2.5 ? $t + 5 : $t;
    }

    /**
     * The rows that mark what a save has already done as credited without
     * paying it (a character's first write, or a save from before slice 2):
     * [kind, ref, n] with kinds quest, badge (n 0), level (n the level) and
     * card (n the purchases).
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function baseline(array $entries, Economy $economy): array
    {
        $m = self::markers($entries);
        $rows = [['base', 'base', 1]];
        foreach ($m['quests'] as $q) {
            if (is_array(($economy->credits('quests') ?? [])[$q] ?? null)) {
                $rows[] = ['quest', $q, 0];
            }
        }
        foreach ($m['badges'] as $b) {
            if (is_array(($economy->credits('badges') ?? [])[$b] ?? null)) {
                $rows[] = ['badge', $b, 0];
            }
        }
        $rows[] = ['level', 'level', min($m['level'], self::levelFor($m['xp'], $economy))];
        foreach ($m['cards'] as $id => $n) {
            if (is_array(($economy->credits('cards') ?? [])[$id] ?? null)) {
                $rows[] = ['card', (string) $id, $n];
            }
        }
        return $rows;
    }

    /**
     * The exact credits a write earns for markers not credited before
     * ($credited: kind -> ref -> n; cardday holds today's card purchases):
     * a newly completed quest's currants (times the reward multiplier, at
     * most the best buff plus the owned Fit for a King tier, js/quests.js
     * applyQuestRewards, inc_quests.js:844, round_to_5) and its tradeable
     * items; a newly unlocked badge's currants (Overachiever, js/
     * achievements.js unlockAchievement, inc_achievements.js:331, Completist
     * x level/4 past level 4); a level reached above the last one credited
     * whose xp agrees, the final level's currants only, as the game pays a
     * jump (js/progression.js reconcilePlayerProgression 106-116, inc_stats.js),
     * at most LEVELS_DAILY paying ones a day, the rest waiting (LEVEL_WAIT)
     * for a later day's count; quests only when the save shows their
     * prerequisites done (prereqsDone); levels, quests and badges together at
     * most PROGRESSION_DAILY currants a day (but for the day's first paying
     * level-up), the rest waiting (PROG_WAIT) for a later day's room; and each new purchase of an imagination card that hands out an
     * item (reward type 8), at most CARD_DAILY a day for a repeatable card,
     * the rest waiting uncredited. Returns the goods (to add to the ledger
     * before the write is compared), what waits (defer: check lets the save
     * keep as much of it as it shows, outside the ledger), the rows to store
     * ([kind, ref, n], n the new total) and what was credited, for the log.
     *
     * @return array{goods: array{items: array<string,int>, currants: int}, rows: list<array{0: string, 1: string, 2: int}>, detail: array, defer: array{items: array<string,int>, currants: int}}
     */
    public static function credits(array $entries, array $credited, Economy $economy): array
    {
        $m = self::markers($entries);
        $goods = ['items' => [], 'currants' => 0];
        $defer = ['items' => [], 'currants' => 0];
        $rows = [];
        $detail = [];
        $put = static function (array $items) use (&$goods): void {
            foreach ($items as $c => $n) {
                $goods['items'][(string) $c] = ($goods['items'][(string) $c] ?? 0) + max(0, (int) $n);
            }
        };
        $buff = min((float) ($economy->credits('buff') ?? 1.0), max(1.0, $m['mult']));
        $bonus = static function (mixed $tiers) use ($m): float {
            $best = 0.0;
            foreach (is_array($tiers) ? $tiers : [] as $id => $v) {
                if (in_array((string) $id, $m['owned'], true)) {
                    $best = max($best, (float) $v);
                }
            }
            return $best;
        };
        // G739: progression currants (levels, quests, badges) share PROGRESSION_DAILY a day; what does
        // not fit waits (PROG_WAIT) and stands in the save meanwhile (defer).
        $doneToday = (int) ($credited[self::PROG_DAY]['currants'] ?? 0);
        $left = max(0, self::PROGRESSION_DAILY - $doneToday);
        $paid = 0;
        // Pays what today's room allows of $c (returns it); the rest waits under $ref. $waited: $ref is a waiting row already.
        $pay = static function (string $ref, int $c, bool $waited) use (&$left, &$paid, &$goods, &$defer, &$rows, &$detail): int {
            $k = min(max(0, $c), $left);
            $left -= $k;
            $paid += $k;
            $goods['currants'] += $k;
            if ($k < $c || $waited) {
                $rows[] = [self::PROG_WAIT, $ref, $c - $k];
            }
            if ($k < $c) {
                $defer['currants'] += $c - $k;
                $detail['waits'][$ref] = $c - $k;
            }
            return $k;
        };
        // G739: the day's first paying single-step level-up is paid whole, outside the room; any other goes through $pay.
        $freeUsed = isset($credited[self::PROG_DAY]['free']);
        $freeNow = false;
        $payLevel = static function (int $lv, int $c, bool $step) use (&$freeUsed, &$freeNow, &$goods, $pay): int {
            if ($step && !$freeUsed && $c > 0) {
                $freeUsed = $freeNow = true;
                $goods['currants'] += $c;
                return $c;
            }
            return $pay("level:{$lv}", $c, false);
        };
        // What earlier days left waiting for room, first.
        $owed = [];
        foreach ((array) ($credited[self::PROG_WAIT] ?? []) as $ref => $c) {
            if ((int) $c > 0) {
                $owed[(string) $ref] = (int) $c;
            }
        }
        ksort($owed);
        foreach ($owed as $ref => $c) {
            if (($k = $pay($ref, $c, true)) > 0) {
                $detail['waited'][$ref] = $k;
            }
        }
        $was = (int) ($credited['level']['level'] ?? 1);
        $day = (int) ($credited['lvlday']['level'] ?? 0);
        $today = $day;
        // Paying levels an earlier day's count left waiting, lowest first, while today's count lasts.
        $waiting = [];
        foreach ((array) ($credited[self::LEVEL_WAIT] ?? []) as $lv => $c) {
            if ((int) $c > 0) {
                $waiting[(int) $lv] = (int) $c;
            }
        }
        ksort($waiting);
        foreach ($waiting as $lv => $c) {
            if ($today >= self::LEVELS_DAILY) {
                $defer['currants'] += $c;
                continue;
            }
            $today++;
            $rows[] = [self::LEVEL_WAIT, (string) $lv, 0];
            if (($k = $payLevel($lv, $c, false)) > 0) {
                $detail['waited'][(string) $lv] = $k;
            }
        }
        $quests = (array) ($economy->credits('quests') ?? []);
        $qm = $buff + $bonus($economy->credits('questBonus'));
        foreach ($m['quests'] as $q) {
            if (!is_array($quests[$q] ?? null) || isset($credited['quest'][$q])) {
                continue;
            }
            // G739: only when the save shows the quest's prerequisites done too (quests.js assignQuest).
            if (!self::prereqsDone((array) ($quests[$q]['p'] ?? []), $m)) {
                $detail['prereqs'][] = $q;
                continue;
            }
            $c = self::roundTo5((float) ($quests[$q]['c'] ?? 0) * $qm);
            $put((array) ($quests[$q]['i'] ?? []));
            $rows[] = ['quest', $q, 1];
            $detail['quests'][$q] = $pay("quest:{$q}", $c, false);
        }
        $badges = (array) ($economy->credits('badges') ?? []);
        $bm = $buff + $bonus($economy->credits('badgeBonus'));
        foreach ($m['badges'] as $b) {
            if (!is_array($badges[$b] ?? null) || isset($credited['badge'][$b])) {
                continue;
            }
            $factor = !empty($badges[$b]['x']) && $m['level'] > 4 ? $m['level'] / 4 : 1;
            $c = self::roundTo5((float) ($badges[$b]['c'] ?? 0) * $bm * $factor);
            $rows[] = ['badge', $b, 1];
            $detail['badges'][$b] = $pay("badge:{$b}", $c, false);
        }
        if ($m['level'] > $was && self::levelFor($m['xp'], $economy) >= $m['level']) {
            $c = (int) (((array) ($economy->credits('levels') ?? []))[(string) $m['level']] ?? 0);
            $rows[] = ['level', 'level', $m['level']];
            if ($c > 0 && $today >= self::LEVELS_DAILY) {
                $rows[] = [self::LEVEL_WAIT, (string) $m['level'], $c];
                $defer['currants'] += $c;
                $detail['deferred']['level'] = [$was, $m['level'], $c];
            } else {
                $today += $c > 0 ? 1 : 0;
                $detail['level'] = [$was, $m['level'], $payLevel($m['level'], $c, $m['level'] === $was + 1)];
            }
        }
        if ($today !== $day) {
            $rows[] = ['lvlday', 'level', $today];
        }
        if ($freeNow) {
            $rows[] = [self::PROG_DAY, 'free', 1];
        }
        if ($paid > 0) {
            $rows[] = [self::PROG_DAY, 'currants', $doneToday + $paid];
        }
        $cards = (array) ($economy->credits('cards') ?? []);
        foreach ($m['cards'] as $id => $n) {
            $card = $cards[$id] ?? null;
            $had = (int) ($credited['card'][$id] ?? 0);
            if (!is_array($card) || $n <= $had) {
                continue;
            }
            $add = $n - $had;
            $per = max(1, (int) ($card['n'] ?? 1));
            if (!empty($card['k'])) {
                $all = $add;
                $add = min($add, max(0, self::CARD_DAILY - (int) ($credited['cardday'][$id] ?? 0)));
                if ($add > 0) {
                    $rows[] = ['cardday', (string) $id, (int) ($credited['cardday'][$id] ?? 0) + $add];
                }
                // Purchases past today's count wait for a later day's (they stay uncredited); the save keeps their items meanwhile.
                $wait = min($all - $add, self::CARD_WAIT);
                if ($wait > 0) {
                    $defer['items'][(string) $card['c']] = ($defer['items'][(string) $card['c']] ?? 0) + $wait * $per;
                    $detail['deferred']['cards'][$id] = $wait;
                }
            }
            if ($add <= 0) {
                continue;
            }
            $put([(string) $card['c'] => $add * $per]);
            $rows[] = ['card', (string) $id, $had + $add];
            $detail['cards'][$id] = $add;
        }
        ksort($goods['items']);
        ksort($defer['items']);
        return ['goods' => $goods, 'rows' => $rows, 'detail' => $detail, 'defer' => $defer];
    }

    /**
     * Whether a save shows a quest's prerequisites done (G739): each of the economy table's "p"
     * entries is a quest id in the completed list, or "<quest>/<skill>" where the learned skill
     * counts too (js/quest-offers.js questDone, prereqProxy).
     *
     * @param list<mixed> $prereqs
     * @param array{quests: list<string>, skills: list<string>} $m markers()
     */
    public static function prereqsDone(array $prereqs, array $m): bool
    {
        foreach ($prereqs as $p) {
            [$q, $skill] = array_pad(explode('/', (string) $p, 2), 2, null);
            if (!in_array($q, $m['quests'], true) && ($skill === null || !in_array($skill, $m['skills'], true))) {
                return false;
            }
        }
        return true;
    }

    // —— Words ——

    /** What the player reads when a write is clamped (no em-dashes). */
    public static function correctedText(array $clamped, Economy $economy): string
    {
        $parts = [];
        $items = $clamped['items'];
        ksort($items);
        foreach ($items as $c => $n) {
            $parts[] = $economy->label((string) $c, $n);
        }
        if ($clamped['currants'] > 0) {
            $parts[] = number_format($clamped['currants']) . ($clamped['currants'] === 1 ? ' currant' : ' currants');
        }
        if ($parts === []) {
            return '';
        }
        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;
        return "Your pack was corrected: {$list} could not be accounted for.";
    }

    // —— Helpers ——

    public static function count(mixed $n): int
    {
        return is_numeric($n) ? max(0, (int) floor((float) $n)) : 0;
    }

    private static function decode(mixed $json): mixed
    {
        return is_string($json) && $json !== '' ? json_decode($json, true) : null;
    }

    private static function objects(mixed $json): mixed
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $v = json_decode($json, false);
        return $v instanceof \stdClass ? $v : null;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
