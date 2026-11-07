<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * Mail between real players (register G478), the rules of GameServerJS
 * players/inc_mail.js and main.js doMailSend 6646-6750, with the AS3
 * client's compose limits. Letters outlive sessions and reach players who
 * are offline, so they are the site's (table groddle_player_mail), never a
 * client's; anything of value moves through the ledger (Ledger, G335). Only
 * signed-in players call it; the realtime server only carries the "you have
 * mail" nudge (the answer's tell, a proof signed with the realtime secret:
 * groddle-afternoon-server mail.go).
 *
 *   GET  groddle/v1/mail                   {"letters":[Letter],"sent":[Letter],"next":ms|null,"receipts":[Receipt],"rules":{…},"now":ms}
 *   POST groddle/v1/mail                   {"id":"<16 hex>","to":"Name"|7,"text":"…","item":{"c","n","r"?,"t"?}|null,"currants":0,"reply":"<16 hex>"|null}
 *        (t: a one-copy attachment's own words, a Cubimal's name ...: CopyTags; they go with it)
 *        200 {"status":"sent","letter":Letter,"receipt":Receipt,"tell":"<token>"|null} | 409 {"status":"refused","why","text","cost"?}
 *   POST groddle/v1/mail/{id}/collect      200 {"status":"collected"|"done","receipt":Receipt|null,"letter":Letter} | 409 refused ("early")
 *
 *   Letter: {"id","box":"in"|"returned"|"sent","from":{id,name},"to":{id,name},"text","item":{c,n,r?}|null,"currants",
 *            "postage","at" (arrives, ms),"sent" (ms),"collected":bool,"reply":{"text","from":{id,name}}|null,"why"?}
 *   Receipt: {"op":"mail-<id>-s|c","gave":{"items":[{c,n}],"currants"},"got":{…}}, a settled trade with
 *   nobody (groddle_trades, b_id 0, as LedgerAuction records its moves): the save applies it once
 *   (the trade slice's applied list); until then a write is compared as if it had not happened, and a
 *   load applies it to the stored save (Ledger::catchUp).
 *
 * The moves (each recorded once per op, so asking again answers as recorded):
 * - Sending (doMailSend): the postage, the enclosed currants and the stack
 *   leave the sender's ledger (op -s) and wait in the letter, in nobody's
 *   ledger (the source moved the stack into the receiver's hidden mail bag,
 *   mail_add_item 1200-1244). Postage is mail_get_message_cost 1295-1312: 2,
 *   plus 10% of the stack's base cost times its count (at least 1), plus 10%
 *   of the currants, at most 200, plus the currants themselves. Never
 *   refunded (the source never refunds it either).
 * - Arriving: a letter with a stack or currants arrives
 *   config.mail_delivery_time_with_attachment later (420 s,
 *   config_prod.js:46; main.js 6689, 6728, 6742-6745), words alone at once
 *   (mail_delivery_time 0, config_prod.js:45). Until then the receiver
 *   cannot see it (build_mail_check_msg 1347).
 * - Collecting (doMailReceive, get_message_goodies 628-655): the stack and
 *   the currants come into the receiver's ledger (op -c).
 * - A reply (doMailSend reply_message_id, mail_get_player_reply 538-563)
 *   carries the words it answers, one level (build_mail_check_msg 1403-1429).
 *
 * Limits: one stack a letter (build_mail_check_msg 1363-1364), of at most
 * 999 (MailItemSlot.as:167), a class the ledger carries; at most 999,999
 * currants (MailReplyUI.as:352); at most 300 characters (MailComposeUI.as:39);
 * something in it (MailComposeUI.as:527). Nobody mails a player who blocks
 * them or whom they block (mail_can_receive 210-216); a plain letter from
 * someone the receiver has since blocked is not shown (build_mail_check_msg
 * 1356-1358, mail_count_messages 175).
 *
 * Ours, where the source had nothing (its mail lived on one game server and
 * a player never went away):
 * - Not to yourself, and only to a character that exists.
 * - A full mailbox: a receiver holding MAILBOX_MAX letters with things not
 *   collected yet gets no more parcels until they collect some (the limit
 *   inc_auctions.js:383 set for auction deliveries); the sender is refused
 *   at the counter, nothing taken.
 * - Returned mail: a letter with things in it whose receiver is gone (the
 *   account deleted) or has blocked the sender before collecting comes back
 *   to the sender's mailbox from the Post with the same things in it,
 *   postage kept (the source kept such letters forever; blocked senders'
 *   parcels stayed in the inbox).
 * - At most SEND_PER_HOUR letters an hour from one player.
 */
final readonly class LedgerMail
{
    public const TABLE = 'groddle_player_mail';

    /** MailComposeUI.as:39 MAX_CHARS. */
    public const TEXT_MAX = 300;
    /** MailItemSlot.as:167: min(stackmax, held, 999). */
    public const COUNT_MAX = 999;
    /** MailReplyUI.as:352. */
    public const CURRANTS_MAX = 999999;
    /** config_prod.js:45-46. */
    public const DELAY = 0;
    public const DELAY_ATTACHED = 420;
    /** mail_get_message_cost 1296, 1305. */
    public const BASE_COST = 2;
    public const POSTAGE_MAX = 200;
    /** inc_auctions.js:383 (ours for letters). */
    public const MAILBOX_MAX = 50;
    /** Ours. */
    public const SEND_PER_HOUR = 60;
    /** What GET lists: letters this recent, at most this many a box. */
    public const KEEP_DAYS = 60;
    public const LIST_MAX = 100;
    /** The realtime tell's life. */
    public const TELL_TTL = 120;
    /** A letter from nobody (from_id 0: the game itself, GardenPayout's crops) is signed so. */
    public const SYSTEM_NAME = 'Groddle Afternoon';

    public const TEXT_EMPTY = 'You need to put *something* in this message!'; // MailComposeUI.as:528
    public const TEXT_NOBODY = "There's nobody by that name.";
    public const TEXT_SELF = "You can't send mail to yourself.";
    public const TEXT_BLOCKED_BY = 'You are blocked by that player and cannot send them mail.';
    public const TEXT_BLOCKING = 'You are blocking that player and cannot send them mail.';
    public const TEXT_TYPE = "That can't be sent by mail."; // main.js 6672 "Item is not allowed to be mailed"
    public const TEXT_COUNT = 'Choose how many to send, from 1 to 999.';
    public const TEXT_CURRANTS = 'Enclose from 0 to 999,999 currants.';
    public const TEXT_SHORT = "You don't have that any more.";
    public const TEXT_NO_CASH = 'You need more currants!'; // MailComposeUI.as:621
    public const TEXT_FULL = 'Their mailbox is full of parcels. Try again once they collect some.';
    public const TEXT_BUSY = "You've sent a lot of mail this hour. Try again a little later.";
    public const TEXT_EARLY = 'That letter is still on its way.';
    public const TEXT_NOTHING = 'There is nothing to collect.';
    public const TEXT_RETURN_GONE = 'Your letter to %s came back: there is nobody at that address any more. Here are your things.';
    public const TEXT_RETURN_BLOCKED = 'Your letter to %s came back: they would not take it. Here are your things.';

    public function __construct(private Seams $minn)
    {
    }

    // —— Reading ——

    #[Route(Method::Get, '/groddle/v1/mail', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->me();
        $now = time();
        Ledger::atomically($db, fn () => $this->sweep($me, $now));
        $t = $db->table(self::TABLE);
        $since = gmdate('Y-m-d H:i:s', $now - self::KEEP_DAYS * 86400);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $in = $db->rows("SELECT * FROM {$t} WHERE deliver_to = ? AND deliver_at <= ? AND (deliver_at >= ? OR collected_at IS NULL) ORDER BY deliver_at DESC, id LIMIT " . self::LIST_MAX, [$me, $stamp, $since]);
        $ignoring = $this->ignoring($me);
        $in = array_values(array_filter($in, static fn (array $r): bool => self::shown($r, $ignoring)));
        $sent = $db->rows("SELECT * FROM {$t} WHERE from_id = ? AND created_at >= ? ORDER BY created_at DESC, id LIMIT " . self::LIST_MAX, [$me, $since]);
        $next = $db->value("SELECT MIN(deliver_at) FROM {$t} WHERE deliver_to = ? AND deliver_at > ?", [$me, $stamp]);
        return Response::json([
            'letters' => $this->letters(array_reverse($in), $me),
            'sent' => $this->letters(array_reverse($sent), $me),
            'next' => is_string($next) ? self::ms($next) : null,
            'receipts' => $this->receipts($me),
            'rules' => self::rules(),
            'now' => $now * 1000,
        ]);
    }

    // —— Sending ——

    #[Route(Method::Post, '/groddle/v1/mail', policy: new Policy(Access::SignedIn))]
    public function send(Request $request): Response
    {
        $body = $request->json();
        $call = is_array($body) ? self::call($body) : null;
        if ($call === null) {
            throw new RestError('groddle_mail_invalid', 'Not a letter.', 400);
        }
        $me = $this->me();
        $out = Ledger::atomically($this->minn->db, fn () => $this->post($call, $me, time()));
        if (($out['status'] ?? '') === 'sent' && isset($out['letter'])) {
            $out['tell'] = $this->tell($me, (int) $out['letter']['to']['id'], $call['id'], (int) intdiv((int) $out['letter']['at'], 1000));
        }
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /**
     * A letter as the game sends it, checked: null when it is not one. The
     * limits that have words for the player are post()'s.
     *
     * @return array{id: string, to: int|string, text: string, item: ?array{c: string, n: int, r?: int}, currants: int, reply: ?string}|null
     */
    public static function call(array $body): ?array
    {
        $id = $body['id'] ?? null;
        $to = $body['to'] ?? null;
        $currants = $body['currants'] ?? 0;
        $reply = $body['reply'] ?? null;
        if (!self::isId($id) || !(is_int($to) && $to > 0 || is_string($to) && trim($to) !== '' && mb_strlen($to) <= 64) || !is_int($currants)) {
            return null;
        }
        if ($reply !== null && !self::isId($reply)) {
            return null;
        }
        $item = null;
        if (($body['item'] ?? null) !== null) {
            $it = $body['item'];
            $c = is_array($it) ? ($it['c'] ?? null) : null;
            $n = is_array($it) ? ($it['n'] ?? null) : null;
            if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_int($n)) {
                return null;
            }
            $item = ['c' => $c, 'n' => $n];
            if (is_int($it['r'] ?? null) && $it['r'] >= 0 && $it['r'] <= 1000000) {
                $item['r'] = $it['r'];
            }
            $tag = isset($it['t']) ? CopyTags::json(Economy::shared(), $c, $n, $it['t']) : null;
            if ($tag !== null) {
                $item['t'] = $tag;
            }
        }
        $text = is_string($body['text'] ?? null) ? LedgerGift::text($body['text'], self::TEXT_MAX, true) : '';
        return ['id' => $id, 'to' => is_string($to) ? trim($to) : $to, 'text' => $text, 'item' => $item, 'currants' => $currants, 'reply' => $reply];
    }

    /** Why a letter is refused before anyone's ledger is asked ([why, words]), or null. */
    public static function sendRefusal(string $text, ?array $item, int $currants): ?array
    {
        if ($text === '' && $item === null && $currants === 0) {
            return ['empty', self::TEXT_EMPTY];
        }
        if ($currants < 0 || $currants > self::CURRANTS_MAX) {
            return ['bad_currants', self::TEXT_CURRANTS];
        }
        if ($item !== null) {
            if (!Economy::shared()->tradeable($item['c'])) {
                return ['type', self::TEXT_TYPE];
            }
            if ($item['n'] < 1 || $item['n'] > self::COUNT_MAX) {
                return ['bad_count', self::TEXT_COUNT];
            }
        }
        return null;
    }

    private function post(array $call, int $me, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $was = $db->row("SELECT * FROM {$t} WHERE id = ? FOR UPDATE", [$call['id']]);
        if ($was !== null) {
            return (int) $was['from_id'] === $me
                ? ['status' => 'sent', 'letter' => $this->letters([$was], $me)[0], 'receipt' => $this->receipt(self::op($call['id'], 's'))]
                : self::refused('bad', 'Not a letter.');
        }
        $why = self::sendRefusal($call['text'], $call['item'], $call['currants']);
        if ($why !== null) {
            return self::refused(...$why);
        }
        $to = $this->recipient($call['to']);
        if ($to === null) {
            return self::refused('nobody', self::TEXT_NOBODY);
        }
        if ($to === $me) {
            return self::refused('self', self::TEXT_SELF);
        }
        $ignores = $db->table(Buddies::IGNORES);
        if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$to, $me]) !== null) {
            return self::refused('blocked', self::TEXT_BLOCKED_BY);
        }
        if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$me, $to]) !== null) {
            return self::refused('blocking', self::TEXT_BLOCKING);
        }
        if ((int) $db->value("SELECT COUNT(*) FROM {$t} WHERE from_id = ? AND created_at >= ?", [$me, gmdate('Y-m-d H:i:s', $now - 3600)]) >= self::SEND_PER_HOUR) {
            return self::refused('busy', self::TEXT_BUSY);
        }
        $parcel = $call['item'] !== null || $call['currants'] > 0;
        if ($parcel && (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE deliver_to = ? AND collected_at IS NULL AND (class_tsid <> '' OR currants > 0)", [$to]) >= self::MAILBOX_MAX) {
            return self::refused('full', self::TEXT_FULL);
        }
        $cost = self::cost($call['item']['c'] ?? null, $call['item']['n'] ?? 0, $call['currants']);
        $postage = $cost - $call['currants'];
        $gave = ['items' => $call['item'] === null ? [] : [$call['item']['c'] => $call['item']['n']], 'currants' => $cost];
        $op = self::op($call['id'], 's');
        $short = self::settleAlone($this->minn, $op, $me, $gave, ['items' => [], 'currants' => 0], 'send', $now);
        if ($short !== null) {
            return $short === 'currants' ? self::refused('no_cash', self::TEXT_NO_CASH) + ['cost' => $cost] : self::refused('short', self::TEXT_SHORT);
        }
        $quote = '';
        $quoteFrom = null;
        if ($call['reply'] !== null) {
            $answered = $db->row("SELECT from_id, text FROM {$t} WHERE id = ? AND to_id = ? AND from_id = ?", [$call['reply'], $me, $to]);
            if ($answered !== null) {
                $quote = (string) $answered['text'];
                $quoteFrom = (int) $answered['from_id'];
                $db->execute("UPDATE {$t} SET replied_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), $call['reply']]);
            }
        }
        $at = $now + ($parcel ? self::DELAY_ATTACHED : self::DELAY);
        $db->execute("INSERT INTO {$t} (id, from_id, to_id, deliver_to, text, class_tsid, count, r, tag, currants, postage, reply_to, quote, quote_from, status, created_at, deliver_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?)", [
            $call['id'], $me, $to, $to, $call['text'], $call['item']['c'] ?? '', $call['item']['n'] ?? 0, $call['item']['r'] ?? null, $call['item']['t'] ?? null, $call['currants'], $postage,
            $quoteFrom === null ? null : $call['reply'], $quote, $quoteFrom, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $at),
        ]);
        $row = $db->row("SELECT * FROM {$t} WHERE id = ?", [$call['id']]);
        return ['status' => 'sent', 'letter' => $this->letters([$row], $me)[0], 'receipt' => $this->receipt($op)];
    }

    /** A character by id or by name (any letter case); null when there is none. */
    private function recipient(int|string $to): ?int
    {
        $db = $this->minn->db;
        $users = $db->table('users');
        if (is_int($to)) {
            $id = $db->value("SELECT ID FROM {$users} WHERE ID = ?", [$to]);
            return $id === null ? null : (int) $id;
        }
        $players = $db->table(Characters::TABLE);
        $id = $db->value("SELECT c.user_id FROM {$players} c JOIN {$users} u ON u.ID = c.user_id WHERE c.name_key = ?", [strtolower($to)]);
        return $id === null ? null : (int) $id;
    }

    /** The realtime nudge (mail.go): good once, for TELL_TTL, naming who and when it arrives. */
    private function tell(int $from, int $to, string $id, int $at): ?string
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $now = time();
        return RealtimeTicket::sign(['k' => 'mail', 'sub' => $from, 'to' => $to, 'n' => $id, 'at' => $at, 'iat' => $now, 'exp' => $now + self::TELL_TTL], $secret);
    }

    // —— Collecting ——

    #[Route(Method::Post, '/groddle/v1/mail/{id:[0-9a-f]+}/collect', policy: new Policy(Access::SignedIn))]
    public function collect(Request $request, string $id): Response
    {
        if (!self::isId($id)) {
            throw new RestError('groddle_mail_unknown', self::TEXT_NOTHING, 404);
        }
        $me = $this->me();
        $out = Ledger::atomically($this->minn->db, function () use ($id, $me): array {
            $db = $this->minn->db;
            $t = $db->table(self::TABLE);
            $now = time();
            $this->sweep($me, $now);
            $row = $db->row("SELECT * FROM {$t} WHERE id = ? FOR UPDATE", [$id]);
            if ($row === null || (int) $row['deliver_to'] !== $me || ((string) $row['class_tsid'] === '' && (int) $row['currants'] === 0)) {
                return self::refused('not_found', self::TEXT_NOTHING);
            }
            $op = self::op($id, 'c');
            if ($row['collected_at'] !== null) {
                return ['status' => 'done', 'receipt' => $this->receipt($op), 'letter' => $this->letters([$row], $me)[0]];
            }
            if (strtotime((string) $row['deliver_at'] . ' UTC') > $now) {
                return self::refused('early', self::TEXT_EARLY);
            }
            $got = ['items' => (string) $row['class_tsid'] === '' ? [] : [(string) $row['class_tsid'] => (int) $row['count']], 'currants' => (int) $row['currants']];
            self::settleAlone($this->minn, $op, $me, ['items' => [], 'currants' => 0], $got, 'collect', $now);
            $db->execute("UPDATE {$t} SET collected_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), $id]);
            $row = $db->row("SELECT * FROM {$t} WHERE id = ?", [$id]);
            return ['status' => 'collected', 'receipt' => $this->receipt($op), 'letter' => $this->letters([$row], $me)[0]];
        });
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    // —— Returns ——

    /**
     * Letters with things in them, not collected, sent by or to this player,
     * whose receiver is gone or blocks the sender now: back to the sender.
     */
    private function sweep(int $me, int $now): void
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $users = $db->table('users');
        $ignores = $db->table(Buddies::IGNORES);
        $rows = $db->rows("SELECT m.id, m.from_id, m.to_id, (u.ID IS NULL) AS gone, (i.user_id IS NOT NULL) AS blocked FROM {$t} m
            LEFT JOIN {$users} u ON u.ID = m.to_id
            LEFT JOIN {$ignores} i ON i.user_id = m.to_id AND i.ignored_id = m.from_id
            WHERE (m.from_id = ? OR m.to_id = ?) AND m.status = 'sent' AND m.collected_at IS NULL AND (m.class_tsid <> '' OR m.currants > 0)
              AND (u.ID IS NULL OR i.user_id IS NOT NULL) LIMIT 100", [$me, $me]);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        foreach ($rows as $r) {
            $why = (int) $r['gone'] === 1 ? 'gone' : 'blocked';
            $db->execute("UPDATE {$t} SET status = 'returned', why = ?, deliver_to = from_id, deliver_at = ?, returned_at = ? WHERE id = ? AND status = 'sent' AND collected_at IS NULL", [$why, $stamp, $stamp, (string) $r['id']]);
            (Ledger::fromSeams($this->minn))->log((int) $r['from_id'], 'mail', ['mail' => (string) $r['id'], 'did' => 'returned', 'why' => $why], $now);
        }
    }

    // —— The ledger ——

    /**
     * One move in one character's ledger, recorded once as a settled trade
     * with nobody (LedgerAuction::settleAlone's record). Returns null when it
     * moved (or was recorded before), else what the ledger lacked: 'currants'
     * or the class ('void' for an op recorded as refused).
     *
     * @param array{items: array<string,int>, currants: int} $gave
     * @param array{items: array<string,int>, currants: int} $got
     */
    public static function settleAlone(Seams $minn, string $op, int $user, array $gave, array $got, string $why, int $now): ?string
    {
        $db = $minn->db;
        $trades = $db->table(Ledger::TRADES);
        $held = $db->row("SELECT status FROM {$trades} WHERE trade_id = ? FOR UPDATE", [$op]);
        if ($held !== null) {
            return $held['status'] === 'settled' ? null : 'void';
        }
        $ledger = Ledger::fromSeams($minn);
        $state = $ledger->lock($user, $now);
        foreach ($gave['items'] as $c => $n) {
            if (!Economy::shared()->tradeable((string) $c) || ($state['items'][$c] ?? 0) < $n) {
                return (string) $c;
            }
        }
        if ($state['currants'] < $gave['currants']) {
            return 'currants';
        }
        $delta = ['items' => [], 'currants' => $got['currants'] - $gave['currants']];
        foreach ($gave['items'] as $c => $n) {
            $delta['items'][$c] = ($delta['items'][$c] ?? 0) - $n;
        }
        foreach ($got['items'] as $c => $n) {
            $delta['items'][$c] = ($delta['items'][$c] ?? 0) + $n;
        }
        $after = LedgerMath::add(['items' => $state['items'], 'currants' => $state['currants']], $delta);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [$after['currants'], $stamp, $user]);
        $items = $db->table(Ledger::ITEMS);
        foreach (array_keys($delta['items']) as $c) {
            $count = (int) ($after['items'][$c] ?? 0);
            if ($count <= 0) {
                $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, (string) $c]);
            } else {
                $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, $count]);
            }
        }
        $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $user, self::goodsJson($gave), self::goodsJson($got), $stamp, $stamp]);
        $ledger->log($user, 'mail', ['mail' => $op, 'did' => $why, 'gave' => ['items' => (object) $gave['items'], 'currants' => $gave['currants']], 'got' => ['items' => (object) $got['items'], 'currants' => $got['currants']]], $now);
        return null;
    }

    /** One recorded move as a save applies it, or null. */
    private function receipt(string $op): ?array
    {
        $r = $this->minn->db->row('SELECT trade_id, a_gave, b_gave FROM ' . $this->minn->db->table(Ledger::TRADES) . " WHERE trade_id = ? AND status = 'settled'", [$op]);
        return $r === null ? null : self::receiptOf($r);
    }

    /** The mail moves this character's save has not applied yet. */
    private function receipts(int $me): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT trade_id, a_gave, b_gave FROM ' . $db->table(Ledger::TRADES) . " WHERE a_id = ? AND b_id = 0 AND status = 'settled' AND a_applied_at IS NULL AND trade_id LIKE 'mail-%' AND created_at >= ? ORDER BY created_at, trade_id LIMIT 60", [$me, gmdate('Y-m-d H:i:s', time() - Ledger::PENDING_FOR)]);
        return array_map(self::receiptOf(...), $rows);
    }

    private static function receiptOf(array $r): array
    {
        $out = ['op' => (string) $r['trade_id']];
        foreach (['gave' => 'a_gave', 'got' => 'b_gave'] as $k => $col) {
            $g = LedgerMath::goods(json_decode((string) $r[$col], true));
            $items = [];
            foreach ($g['items'] as $c => $n) {
                $items[] = ['c' => (string) $c, 'n' => $n];
            }
            $out[$k] = ['items' => $items, 'currants' => $g['currants']];
        }
        return $out;
    }

    // —— Answers ——

    /** @return list<int> the players this one blocks */
    private function ignoring(int $me): array
    {
        $db = $this->minn->db;
        return array_map('intval', array_column($db->rows('SELECT ignored_id FROM ' . $db->table(Buddies::IGNORES) . ' WHERE user_id = ?', [$me]), 'ignored_id'));
    }

    /** build_mail_check_msg 1356-1358: a plain letter from someone the reader blocks is not shown; one with things in it is. */
    public static function shown(array $row, array $ignoring): bool
    {
        if ((string) $row['status'] === 'returned') {
            return true;
        }
        $plain = (string) $row['class_tsid'] === '' && (int) $row['currants'] === 0;
        return !($plain && in_array((int) $row['from_id'], $ignoring, true));
    }

    private function letters(array $rows, int $me): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['from_id'];
            $ids[] = (int) $r['to_id'];
            if ($r['quote_from'] !== null) {
                $ids[] = (int) $r['quote_from'];
            }
        }
        $names = (new Characters($this->minn->db))->namesOf($ids);
        $who = static fn (int $id): array => ['id' => $id, 'name' => $id === 0 ? self::SYSTEM_NAME : ($names[$id] ?? 'Someone')];
        $out = [];
        foreach ($rows as $r) {
            $returned = (string) $r['status'] === 'returned';
            $box = (int) $r['deliver_to'] === $me && (int) $r['from_id'] !== $me ? 'in' : ($returned && (int) $r['deliver_to'] === $me ? 'returned' : 'sent');
            $item = (string) $r['class_tsid'] === '' ? null : ['c' => (string) $r['class_tsid'], 'n' => (int) $r['count']];
            if ($item !== null && $r['r'] !== null) {
                $item['r'] = (int) $r['r'];
            }
            if ($item !== null && $item['n'] === 1 && isset($r['tag']) && ($tag = CopyTags::decode($r['tag'])) !== null) {
                $item['t'] = $tag;
            }
            $letter = [
                'id' => (string) $r['id'], 'box' => $box, 'from' => $who((int) $r['from_id']), 'to' => $who((int) $r['to_id']),
                'text' => (string) $r['text'], 'item' => $item, 'currants' => (int) $r['currants'], 'postage' => (int) $r['postage'],
                'at' => self::ms($r['deliver_at']), 'sent' => self::ms($r['created_at']), 'collected' => $r['collected_at'] !== null,
                'reply' => $r['quote_from'] === null ? null : ['text' => (string) $r['quote'], 'from' => $who((int) $r['quote_from'])],
                'replied' => $r['replied_at'] !== null,
            ];
            if ($returned) {
                $letter['why'] = (string) $r['why'];
                $letter['returned'] = sprintf((string) $r['why'] === 'gone' ? self::TEXT_RETURN_GONE : self::TEXT_RETURN_BLOCKED, $letter['to']['name']);
            }
            $out[] = $letter;
        }
        return $out;
    }

    private static function refused(string $why, string $text): array
    {
        return ['status' => 'refused', 'why' => $why, 'text' => $text];
    }

    private static function ms(mixed $datetime): int
    {
        return is_string($datetime) && $datetime !== '' ? (int) strtotime($datetime . ' UTC') * 1000 : 0;
    }

    private function me(): int
    {
        return $this->minn->reader->userId;
    }

    // —— Pure rules (tests share them) ——

    public static function rules(): array
    {
        return ['textMax' => self::TEXT_MAX, 'countMax' => self::COUNT_MAX, 'currantsMax' => self::CURRANTS_MAX, 'delay' => self::DELAY, 'delayAttached' => self::DELAY_ATTACHED, 'baseCost' => self::BASE_COST, 'postageMax' => self::POSTAGE_MAX, 'mailboxMax' => self::MAILBOX_MAX, 'sendPerHour' => self::SEND_PER_HOUR];
    }

    /**
     * mail_get_message_cost 1295-1312 with the currants included (doMailSend
     * charges cost with them): 2, + round(max(1, base * 0.1 * count)) for a
     * stack, + round(currants * 0.1), at most 200, + the currants. Math.round
     * is floor(x + 0.5).
     */
    public static function cost(?string $class, int $count, int $currants): int
    {
        $cost = self::BASE_COST;
        if ($class !== null && $count > 0) {
            $cost += (int) floor(max(1.0, self::baseCost($class) * 0.1 * $count) + 0.5);
        }
        if ($currants > 0) {
            $cost += (int) floor($currants * 0.1 + 0.5);
        }
        return min(self::POSTAGE_MAX, $cost) + max(0, $currants);
    }

    /** A class's base_cost (data/mail.json, the game repo's tools/build-mail.mjs), 0 when it has none. */
    public static function baseCost(string $class): float
    {
        static $base = null;
        if ($base === null) {
            $file = dirname(__DIR__) . '/data/mail.json';
            $raw = json_decode(is_file($file) ? (string) file_get_contents($file) : '', true);
            $base = is_array($raw['base'] ?? null) ? $raw['base'] : [];
        }
        $v = $base[$class] ?? 0;
        return is_int($v) || is_float($v) ? (float) $v : 0.0;
    }

    /** The ledger record of one move: mail-<id>-s (sent), -c (collected). */
    public static function op(string $id, string $what): string
    {
        return "mail-{$id}-{$what}";
    }

    /** A letter's id: 16 hex the sender's game makes, so a letter sent twice is one letter. */
    public static function isId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{16}$/', $id) === 1;
    }

    private static function goodsJson(array $goods): string
    {
        $items = [];
        foreach ($goods['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => (int) $n];
        }
        return (string) json_encode(['items' => $items, 'currants' => (int) $goods['currants']], JSON_UNESCAPED_SLASHES);
    }
}
