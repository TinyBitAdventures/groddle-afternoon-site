<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The console's Night sky panel (console/sky.js; Austin 2026-11-27): a meteor shower or a lone shooting star
 * that every player online sees at once (the game's js/meteor-shower.js, the realtime server's meteor.go). Ours:
 * Glitch had neither. Never in any rotation: only an administrator starts one, here. Administrators only
 * (manage_options, as every admin/* route: 401 signed out, 403 signed in); each press writes one line in
 * groddle_admin_actions (kind meteor, player 0), as the other console actions do.
 *
 *   GET  admin/sky                {"server":"up"|"down","event":Event|null,"now":ms,"ur":Ur}   what is on (now: the server's clock) and Ur's clock
 *   POST admin/meteor {"op":"shower","lead":0|120}  a shower now, or in two minutes with the hint in every activity feed
 *        {"op":"star"}            a lone shooting star now
 *        {"op":"stop"}            stops what is on
 *                                {"delivery":"live"|"busy"|"none"|"down","event":Event|null,"players":n,"text"}
 *
 *   Event: {"id","kind":"shower"|"star","seed","start":ms,"dur":ms,"hint":bool} (meteor.go)
 *   Ur:    {"hour","minute","time":"11:42 PM","sky":"night"|"dusk"|"dawn"|"day"}  (the game's js/game-day.js clock;
 *          sky: how the clock's sky looks, js/sky-clock.js SKY_KEYS: streaks show at night, faintly at dusk and dawn)
 *
 * The site never decides when or where: the server picks the seed and the length, and each game draws the streaks
 * on the server's clock where its own sky is the clock's at night.
 */
final readonly class AdminSky
{
    /** The console's "in 2 minutes" (seconds; meteor.go allows up to meteorLeadMax). */
    public const LEAD = 120;

    /** The game's clock (js/game-day.js GAME_EPOCH_MS, GAME_DAY_MS; HomePool::GAME_EPOCH_MS). */
    public const GAME_EPOCH_MS = 1238562000000;
    public const GAME_DAY_MS = 14400000;

    public const OPS = ['shower', 'star', 'stop'];

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/admin/sky', policy: new Policy(Access::Cap, self::ADMIN))]
    public function status(Request $request): Response
    {
        $answer = (new RealtimeCall($this->minn))->post('meteor', ['op' => 'status']);
        $up = ($answer['status'] ?? null) === 'ok';
        return Response::json([
            'server' => $up ? 'up' : 'down',
            'event' => $up ? self::eventOf($answer['event'] ?? null) : null,
            'now' => $up && is_int($answer['now'] ?? null) ? $answer['now'] : (int) floor(microtime(true) * 1000),
            'ur' => self::urClock((int) floor(microtime(true) * 1000)),
        ])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, '/groddle/v1/admin/meteor', policy: new Policy(Access::Cap, self::ADMIN))]
    public function meteor(Request $request): Response
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $call = self::callOf($request->json());
        if ($call === null) {
            throw new RestError('groddle_admin_meteor', 'Choose a shower, a lone star or stop.', 400);
        }
        $answer = (new RealtimeCall($this->minn))->post('meteor', $call);
        $delivery = self::deliveryOf($answer);
        $event = self::eventOf($answer['event'] ?? null);
        $players = max(0, (int) ($answer['players'] ?? 0));
        $detail = ['op' => $call['op']] + ($call['op'] === 'shower' ? ['lead' => $call['lead']] : []);
        if ($event !== null) {
            $detail += ['id' => $event['id'], 'kind' => $event['kind'], 'seed' => $event['seed'], 'dur' => $event['dur']];
        }
        if ($delivery === 'live') {
            $detail['players'] = $players;
        }
        AdminConsole::recordAction($this->minn->db, $admin->id, 0, 'meteor', $detail, $delivery);
        return Response::json(['delivery' => $delivery, 'event' => $event, 'players' => $players, 'text' => self::text($call['op'], $call['lead'] ?? 0, $delivery, $players, $event)]);
    }

    /**
     * PURE: the server call a console press asks for, or null: {"op":"shower","lead":0|LEAD}, {"op":"star"} or
     * {"op":"stop"}. A shower's lead is 0 or LEAD; nothing else is sent.
     *
     * @return array{op: string, lead?: int}|null
     */
    public static function callOf(mixed $body): ?array
    {
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        if (!is_string($op) || !in_array($op, self::OPS, true)) {
            return null;
        }
        if ($op !== 'shower') {
            return ['op' => $op];
        }
        $lead = $body['lead'] ?? 0;
        if ($lead !== 0 && $lead !== self::LEAD) {
            return null;
        }
        return ['op' => 'shower', 'lead' => $lead];
    }

    /** PURE: what the server's answer means for the log: live (started or stopped), busy, none, or down. */
    public static function deliveryOf(?array $answer): string
    {
        return match ($answer['status'] ?? null) {
            'started', 'stopped' => 'live',
            'busy' => 'busy',
            'none' => 'none',
            default => 'down',
        };
    }

    /**
     * PURE: an event as the server gave it, checked (meteor.go Event), or null.
     *
     * @return array{id: string, kind: string, seed: int, start: int, dur: int, hint: bool}|null
     */
    public static function eventOf(mixed $e): ?array
    {
        if (!is_array($e) || !is_string($e['id'] ?? null) || preg_match('/^mt-[0-9a-f]{16}$/', $e['id']) !== 1 || !in_array($e['kind'] ?? null, ['shower', 'star'], true)) {
            return null;
        }
        return ['id' => $e['id'], 'kind' => $e['kind'], 'seed' => (int) ($e['seed'] ?? 0), 'start' => (int) ($e['start'] ?? 0), 'dur' => (int) ($e['dur'] ?? 0), 'hint' => ($e['hint'] ?? false) === true];
    }

    /**
     * PURE: Ur's clock at unix ms `ms` (js/game-day.js dametimeParts: a game day is four real hours from Glitch's
     * epoch, a game year a whole number of days) and how the clock's sky looks (js/sky-clock.js SKY_KEYS: night
     * till 4:45 and from 21:15, dawn about 4:45 to 8:15, dusk about 17:15 to 21:15, day between).
     *
     * @return array{hour: int, minute: int, time: string, sky: string}
     */
    public static function urClock(int $ms): array
    {
        $within = ($ms - self::GAME_EPOCH_MS) % self::GAME_DAY_MS;
        if ($within < 0) {
            $within += self::GAME_DAY_MS;
        }
        $hour = intdiv($within, 600000);
        $minute = intdiv($within % 600000, 10000);
        $h = $hour + $minute / 60;
        $sky = $h < 4.75 || $h >= 21.25 ? 'night' : ($h < 8.25 ? 'dawn' : ($h < 17.25 ? 'day' : 'dusk'));
        $h12 = $hour % 12 === 0 ? 12 : $hour % 12;
        return ['hour' => $hour, 'minute' => $minute, 'time' => sprintf('%d:%02d %s', $h12, $minute, $hour < 12 ? 'AM' : 'PM'), 'sky' => $sky];
    }

    /** PURE: what the console says after a press. */
    public static function text(string $op, int $lead, string $delivery, int $players, ?array $event): string
    {
        $who = match ($players) {
            0 => 'nobody is online to see it',
            1 => '1 player online',
            default => "{$players} players online",
        };
        if ($delivery === 'down') {
            return 'The realtime server is not answering, so nothing started.';
        }
        if ($delivery === 'busy') {
            return ($event['kind'] ?? '') === 'star' ? 'A shooting star is crossing the sky now. Try again in a few seconds.' : 'A meteor shower is already on. Stop it first.';
        }
        if ($op === 'stop') {
            return $delivery === 'none' ? 'Nothing is on in the sky.' : "Stopped the sky show ({$who}).";
        }
        $minutes = $event !== null ? (int) round($event['dur'] / 60000) : 0;
        return match ($op) {
            'star' => "A lone shooting star is crossing the sky ({$who}).",
            default => $lead > 0
                ? "A meteor shower starts in 2 minutes and runs about {$minutes} minutes; the hint is out ({$who})."
                : "A meteor shower is on for about {$minutes} minutes ({$who}).",
        };
    }
}
