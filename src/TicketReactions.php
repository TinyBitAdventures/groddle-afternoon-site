<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\RestError;

/**
 * Reactions on a report's messages (Austin 2026-11-18: "In bug reports can we add a similar emoji
 * response, like we have in live chat? Both for users and on console."). The remake's own addition:
 * Glitch's bug form had no thread to react in (BootError.as 280-290).
 *
 * The set is the chat's (CommunityText::reactions(), data/reactions.json written by the game repo's
 * tools/build-reactions.mjs from js/chat-reactions.js REACTIONS), never a copy of its own. One row a
 * person a reaction a message in TABLE, with the ticket beside it (cleanup by ticket) and the side
 * they reacted from (role: player in the game's Reports, admin in the console).
 *
 * Who may react: the report's own player on any message of their own report (Tickets), and an
 * administrator on any message (AdminTickets). A reaction is not a message: it sends no email,
 * leaves the report's status, read marks, updated_at, last_message_at and last_author_role as they
 * are (so it is no news for the Reports badge, the console's unread dot or /groddle-check), and is
 * not counted as a reply anywhere.
 *
 * Each thread message carries "reactions": [{"e", "by": [{"id", "name", "you"}], "mine"}] in the
 * set's order, each person once, oldest first: a player by their character name, an administrator by
 * the display name on their account (adminName), "Administrator" when that is only their username.
 */
final readonly class TicketReactions
{
    public const TABLE = 'groddle_ticket_message_reactions';
    /** An administrator with no display name of their own (the replies' own word for them). */
    public const ADMIN_NAME = 'Administrator';

    public function __construct(private Db $db)
    {
    }

    /**
     * Puts one reaction on (or takes it off) a message of a ticket, as $user from $role's side.
     * The route has already checked that $user may reach the ticket. Nothing else changes.
     */
    public function toggle(int $ticket, int $message, int $user, string $role, mixed $key, bool $on, int $now): void
    {
        if (!CommunityText::isReaction($key)) {
            throw new RestError('groddle_ticket_reaction', 'That reaction isn\'t one of the set.', 400);
        }
        $messages = $this->db->table(TicketStore::MESSAGES);
        if ($ticket <= 0 || $message <= 0 || $this->db->value("SELECT 1 FROM {$messages} WHERE id = ? AND ticket_id = ?", [$message, $ticket]) === null) {
            throw new RestError('groddle_ticket_message_missing', 'That message is not in this report.', 404);
        }
        $t = $this->db->table(self::TABLE);
        if (!$on) {
            $this->db->execute("DELETE FROM {$t} WHERE message_id = ? AND user_id = ? AND reaction = ?", [$message, $user, $key]);
            return;
        }
        if ((int) $this->db->value("SELECT COUNT(*) FROM {$t} WHERE user_id = ? AND created_at >= ?", [$user, TicketStore::stamp($now - 60)]) >= CommunityText::REACTIONS_PER_MINUTE) {
            throw new RestError('groddle_ticket_react_slow', 'Slow down a little with the reactions.', 429);
        }
        $this->db->execute("INSERT IGNORE INTO {$t} (message_id, ticket_id, user_id, role, reaction, created_at) VALUES (?, ?, ?, ?, ?, ?)", [$message, $ticket, $user, $role === TicketStore::ADMIN ? TicketStore::ADMIN : TicketStore::PLAYER, $key, TicketStore::stamp($now)]);
    }

    /**
     * A thread's messages (TicketStore::messagesOut) with their reactions, as $viewer reads them.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function attach(int $ticket, array $messages, int $viewer): array
    {
        $rows = $this->rows($ticket);
        $names = $this->names($rows);
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['message_id']][] = $r;
        }
        foreach ($messages as $i => $m) {
            $messages[$i]['reactions'] = self::out($by[(int) $m['id']] ?? [], $names, $viewer);
        }
        return $messages;
    }

    /** One message's reactions, as $viewer reads them (the reaction routes' answer). */
    public function ofMessage(int $ticket, int $message, int $viewer): array
    {
        $rows = array_values(array_filter($this->rows($ticket), static fn (array $r): bool => (int) $r['message_id'] === $message));
        return self::out($rows, $this->names($rows), $viewer);
    }

    /** @return list<array<string, mixed>> a ticket's reaction rows, oldest first */
    private function rows(int $ticket): array
    {
        if ($ticket <= 0) {
            return [];
        }
        return $this->db->rows('SELECT message_id, user_id, role, reaction FROM ' . $this->db->table(self::TABLE) . ' WHERE ticket_id = ? ORDER BY created_at ASC, user_id ASC', [$ticket]);
    }

    /**
     * Who reacted, by name: "user_id:role" => name. A player by their character's name (Characters),
     * an administrator by their display name (adminName).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function names(array $rows): array
    {
        $players = [];
        $admins = [];
        foreach ($rows as $r) {
            if ((string) $r['role'] === TicketStore::ADMIN) {
                $admins[] = (int) $r['user_id'];
            } else {
                $players[] = (int) $r['user_id'];
            }
        }
        $out = [];
        foreach ((new Characters($this->db))->namesOf($players) as $id => $name) {
            $out["{$id}:" . TicketStore::PLAYER] = $name;
        }
        if ($admins !== []) {
            foreach ($this->db->rows('SELECT ID, user_login, display_name FROM ' . $this->db->table('users') . ' WHERE ID IN (?)', [array_values(array_unique($admins))]) as $u) {
                $out[(int) $u['ID'] . ':' . TicketStore::ADMIN] = self::adminName((string) $u['display_name'], (string) $u['user_login']);
            }
        }
        return $out;
    }

    /** PURE: an administrator's name on a reaction: their display name, never their username or an address. */
    public static function adminName(string $display, string $login): string
    {
        $d = trim(preg_replace('/\s+/u', ' ', $display) ?? '');
        if ($d === '' || strcasecmp($d, trim($login)) === 0 || str_contains($d, '@')) {
            return self::ADMIN_NAME;
        }
        return mb_substr($d, 0, 40);
    }

    /**
     * PURE: one message's reaction rows (message_id, user_id, role, reaction; oldest first) as the
     * thread carries them: the set's order, keys outside the set left out, each person once a
     * reaction; "you" and "mine" for $viewer.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $names "user_id:role" => name
     * @return list<array{e: string, by: list<array{id: int, name: string, you: bool}>, mine: bool}>
     */
    public static function out(array $rows, array $names, int $viewer): array
    {
        $by = [];
        foreach ($rows as $r) {
            $key = (string) $r['reaction'];
            $id = (int) $r['user_id'];
            if (!CommunityText::isReaction($key) || $id <= 0 || isset($by[$key][$id])) {
                continue;
            }
            $role = (string) $r['role'] === TicketStore::ADMIN ? TicketStore::ADMIN : TicketStore::PLAYER;
            $name = $names["{$id}:{$role}"] ?? ($role === TicketStore::ADMIN ? self::ADMIN_NAME : 'Someone');
            $by[$key][$id] = ['id' => $id, 'name' => $name, 'you' => $viewer > 0 && $id === $viewer];
        }
        $out = [];
        foreach (array_keys(CommunityText::reactions()) as $key) {
            if (isset($by[$key])) {
                $list = array_values($by[$key]);
                $out[] = ['e' => $key, 'by' => $list, 'mine' => in_array(true, array_column($list, 'you'), true)];
            }
        }
        return $out;
    }
}
