<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;

/**
 * Messages players leave with the team's Butlers (Austin 2026-11-13: a player left Aeghor a Hooch and a question
 * about Petrified Rocks, read days later), for /groddle-check. Administrators only (manage_options).
 *
 *   GET  admin/butler_messages?since=<ISO time>   (default: the last 30 days)
 *        {"messages":[Row],"staff":[id]}
 *
 *   Row: {"id","to":{id,name},"from":{id,name},"text","item":{"class","count"}|null,"at"(ISO, UTC),"taken":bool}
 *
 * Only parcels whose owner is on the team (an administrator) are listed, worked out here from capabilities: the
 * route takes no owner, so it can never read what players leave for each other. Those stay private, like IMs.
 * Parcels with neither a message nor an item are skipped; a package without words is listed with text "".
 */
final readonly class AdminButler
{
    /** Rows one list shows. */
    public const LIST_MAX = 200;

    /** How far back a list without `since` reaches. */
    public const DEFAULT_DAYS = 30;

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/admin/butler_messages', policy: new Policy(Access::Cap, self::ADMIN))]
    public function index(Request $request): Response
    {
        $db = $this->minn->db;
        $t = $db->table(ButlerParcels::TABLE);
        $since = self::sinceOf($request->query('since'), time());
        $owners = array_map('intval', array_column($db->rows("SELECT DISTINCT owner_id FROM {$t} WHERE created_at >= ?", [$since]), 'owner_id'));
        $caps = Capabilities::fromDb($db);
        $staff = self::staffOf($owners, static fn (int $id): bool => $caps->can($id, self::ADMIN));
        if ($staff === []) {
            return Response::json(['messages' => [], 'staff' => []])->withHeader('Cache-Control', 'no-store');
        }
        $rows = $db->rows("SELECT id, owner_id, from_id, class_tsid, count, message, created_at, taken_at FROM {$t} WHERE owner_id IN (?) AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT " . self::LIST_MAX, [$staff, $since]);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['owner_id'];
            $ids[] = (int) $r['from_id'];
        }
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_filter($ids))));
        return Response::json(['messages' => self::rowsOut($rows, $staff, $names), 'staff' => $staff])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The team members among these owners, sorted.
     *
     * @param list<int> $owners
     * @param callable(int): bool $isStaff
     * @return list<int>
     */
    public static function staffOf(array $owners, callable $isStaff): array
    {
        $out = array_values(array_filter(array_unique(array_filter($owners, static fn (int $id): bool => $id > 0)), $isStaff));
        sort($out);
        return $out;
    }

    /**
     * The listed rows: only those owned by a team member (a second guard beside the query), and only parcels that
     * hold words or an item.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int> $staff
     * @param array<int, string> $names
     * @return list<array<string, mixed>>
     */
    public static function rowsOut(array $rows, array $staff, array $names): array
    {
        $out = [];
        foreach ($rows as $r) {
            $to = (int) $r['owner_id'];
            if (!in_array($to, $staff, true)) {
                continue;
            }
            $text = (string) ($r['message'] ?? '');
            $class = (string) ($r['class_tsid'] ?? '');
            if ($text === '' && $class === '') {
                continue;
            }
            $from = (int) $r['from_id'];
            $out[] = [
                'id' => (string) $r['id'],
                'to' => ['id' => $to, 'name' => $names[$to] ?? "#{$to}"],
                'from' => ['id' => $from, 'name' => $names[$from] ?? 'Someone'],
                'text' => $text,
                'item' => $class === '' ? null : ['class' => $class, 'count' => max(0, (int) $r['count'])],
                'at' => self::iso((string) $r['created_at']),
                'taken' => ($r['taken_at'] ?? null) !== null,
            ];
        }
        return $out;
    }

    /** `since` as a UTC datetime for the query: an ISO time, else DEFAULT_DAYS ago. */
    public static function sinceOf(mixed $since, int $now): string
    {
        $at = is_string($since) && $since !== '' ? strtotime($since) : false;
        if ($at === false || $at > $now) {
            $at = $now - self::DEFAULT_DAYS * 86400;
        }
        return gmdate('Y-m-d H:i:s', $at);
    }

    private static function iso(string $datetime): string
    {
        $at = strtotime($datetime . ' UTC');
        return $at === false ? $datetime : gmdate('Y-m-d\TH:i:s\Z', $at);
    }
}
