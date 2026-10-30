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

/**
 * groddle/v1/feedback: a bug report a signed-in player sends from the game's
 * header (the original client's bug icon, GameHeaderUI.as 47-69, and its
 * report form, BootError.as 280-290). Since 2026-10-30 each report is a
 * ticket (TicketStore, the player's routes in Tickets), so the player sees it
 * and the administrators' replies in the game's Reports sheet; this route is
 * POST groddle/v1/tickets under its old name, kept for games built before
 * the sheet. Reports from before then were private groddle_feedback posts
 * (minn.json "types", still listed in Minn Admin under Bug reports);
 * TicketMigration brings them in as tickets.
 *
 * Body: { message, context: { street, streetName, x, y, build, page, agent,
 * screen, errors: [..] } }. The message is required (at most MAX_MESSAGE
 * characters); context keys outside the list are dropped and each value is cut
 * short. A player may send PER_WINDOW reports in WINDOW seconds and PER_DAY a day.
 */
final readonly class BugReports
{
    public const TYPE = 'groddle_feedback';
    public const MAX_MESSAGE = 2000;
    public const WINDOW = 600;
    public const PER_WINDOW = 3;
    public const PER_DAY = 20;
    /** Context keys kept, with the most characters each may carry. */
    public const CONTEXT = ['street' => 32, 'streetName' => 80, 'x' => 12, 'y' => 12, 'build' => 80, 'page' => 200, 'agent' => 300, 'screen' => 20];
    public const MAX_ERRORS = 10;
    public const MAX_ERROR = 300;

    public function __construct(private Seams $minn)
    {
    }

    /** Files one report as a new ticket and answers { status: sent, id, ticket }. */
    #[Route(Method::Post, '/groddle/v1/feedback', policy: new Policy(Access::SignedIn))]
    public function send(Request $request): Response
    {
        return (new Tickets($this->minn))->create($request);
    }

    /**
     * The context the game sent, kept to the known keys and cut to length.
     *
     * @param array<mixed> $raw
     * @return array<string, mixed>
     */
    public static function context(array $raw): array
    {
        $out = [];
        foreach (self::CONTEXT as $key => $max) {
            $value = $raw[$key] ?? '';
            $out[$key] = is_scalar($value) ? self::clean((string) $value, $max, false) : '';
        }
        $errors = [];
        foreach (array_slice(is_array($raw['errors'] ?? null) ? array_values($raw['errors']) : [], 0, self::MAX_ERRORS) as $line) {
            if (is_scalar($line) && ($line = self::clean((string) $line, self::MAX_ERROR, false)) !== '') {
                $errors[] = $line;
            }
        }
        $out['errors'] = $errors;
        return $out;
    }

    /** Text as UTF-8 without control characters (newlines kept when $lines), trimmed and cut to $max characters. */
    public static function clean(string $text, int $max, bool $lines): string
    {
        $text = str_replace("\r\n", "\n", mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace($lines ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', $lines ? '' : ' ', $text);
        $text = trim($text);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) : $text;
    }
}
