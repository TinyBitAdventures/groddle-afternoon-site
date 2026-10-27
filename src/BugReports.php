<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\PostWriter;
use Minn\Content\Posts;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * groddle/v1/feedback: a bug report a signed-in player sends from the game's
 * header (the original client's bug icon, GameHeaderUI.as 47-69, and its
 * report form, BootError.as 280-290). Each report is a private
 * groddle_feedback post (declared in minn.json "types") by the player, so it
 * lists in Minn Admin under Bug reports and never shows on the public site.
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
    private const CONTEXT = ['street' => 32, 'streetName' => 80, 'x' => 12, 'y' => 12, 'build' => 80, 'page' => 200, 'agent' => 300, 'screen' => 20];
    private const MAX_ERRORS = 10;
    private const MAX_ERROR = 300;

    public function __construct(private Seams $minn)
    {
    }

    /** Files one report and answers { status: sent, id }. */
    #[Route(Method::Post, '/groddle/v1/feedback', policy: new Policy(Access::SignedIn))]
    public function send(Request $request): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('groddle_feedback_signed_out', 'Sign in to send a bug report.', 401);
        }
        $body = $request->json();
        $message = self::clean((string) ($body['message'] ?? ''), self::MAX_MESSAGE, true);
        if ($message === '') {
            throw new RestError('groddle_feedback_empty', 'Please describe the problem you are having.', 400);
        }
        $db = $this->minn->db;
        $posts = $db->table('posts');
        $since = static fn (int $seconds): string => gmdate('Y-m-d H:i:s', time() - $seconds);
        $count = static fn (int $seconds): int => (int) $db->value(
            "SELECT COUNT(*) FROM {$posts} WHERE post_type = ? AND post_author = ? AND post_date_gmt >= ?",
            [self::TYPE, $user->id, $since($seconds)],
        );
        if ($count(self::WINDOW) >= self::PER_WINDOW || $count(86400) >= self::PER_DAY) {
            throw new RestError('groddle_feedback_slow', 'Thanks! You have sent a few reports just now. Try again in a little while.', 429);
        }

        $context = self::context(is_array($body['context'] ?? null) ? $body['context'] : []);
        $name = (new Characters($db))->nameOf($user);
        $site = $this->minn->site;
        $now = $site->localNow();
        $gmt = gmdate('Y-m-d H:i:s');
        $writer = new PostWriter($db, new Posts($db), $site);
        // Titles are rendered as HTML (title.rendered): no markup in one, as kses leaves a title by someone without unfiltered_html.
        $title = self::clean(str_replace(['<', '>'], '', strip_tags(($name !== '' ? $name . ': ' : '') . self::firstLine($message))), 120, false);
        $id = $writer->insert([
            'post_author' => $user->id,
            'post_date' => $now,
            'post_date_gmt' => $gmt,
            'post_content' => self::content($message, $context, $name),
            'post_title' => $title,
            'post_excerpt' => '',
            'post_status' => 'private',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $writer->uniqueSlug('bug-report', 0, self::TYPE),
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $now,
            'post_modified_gmt' => $gmt,
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => self::TYPE,
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);
        $home = rtrim((string) ($site->option('home') ?? ''), '/');
        $writer->update($id, ['guid' => $home . '/?post_type=' . self::TYPE . '&p=' . $id]);
        foreach (['street' => 'groddle_street', 'build' => 'groddle_build', 'agent' => 'groddle_agent'] as $key => $meta) {
            if (($context[$key] ?? '') !== '') {
                $writer->addMeta($id, $meta, $context[$key]);
            }
        }
        if ($context['errors'] !== []) {
            $writer->addMeta($id, 'groddle_errors', (string) json_encode($context['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        return Response::json(['status' => 'sent', 'id' => $id], 201);
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

    /** The post's blocks: the player's words, then what the game knew, all escaped. */
    public static function content(string $message, array $context, string $name): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $blocks = [];
        foreach (preg_split('/\n\s*\n/', $message) ?: [] as $para) {
            if (($para = trim($para)) !== '') {
                $blocks[] = "<!-- wp:paragraph -->\n<p>" . nl2br($esc($para), false) . "</p>\n<!-- /wp:paragraph -->";
            }
        }
        $lines = [];
        $label = ['street' => 'Street', 'streetName' => 'Street name', 'x' => 'x', 'y' => 'y', 'build' => 'Build', 'page' => 'Page', 'agent' => 'Browser', 'screen' => 'Screen'];
        if ($name !== '') {
            $lines[] = 'Player: ' . $name;
        }
        foreach ($label as $key => $text) {
            if (($context[$key] ?? '') !== '') {
                $lines[] = $text . ': ' . $context[$key];
            }
        }
        foreach ($context['errors'] ?? [] as $i => $error) {
            $lines[] = 'Error ' . ($i + 1) . ': ' . $error;
        }
        if ($lines !== []) {
            $blocks[] = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Details</h3>\n<!-- /wp:heading -->";
            $blocks[] = "<!-- wp:preformatted -->\n<pre class=\"wp-block-preformatted\">" . $esc(implode("\n", $lines)) . "</pre>\n<!-- /wp:preformatted -->";
        }
        return implode("\n\n", $blocks);
    }

    /** Text without control characters (newlines kept when $lines), trimmed and cut to $max characters. */
    public static function clean(string $text, int $max, bool $lines): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace($lines ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', $lines ? '' : ' ', $text);
        $text = trim($text);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) : $text;
    }

    private static function firstLine(string $message): string
    {
        $line = trim(strtok($message, "\n") ?: $message);
        return mb_strlen($line) > 60 ? rtrim(mb_substr($line, 0, 59)) . '…' : $line;
    }
}
