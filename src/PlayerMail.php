<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Html;

/**
 * The emails players get, in the site's look: the moss-and-cream card of the
 * restoration report and the Notes, the serif headings, the orange Play
 * button, the mark. Email clients ignore stylesheets, so every style is
 * inline and the layout is tables; the page stays light (most clients force
 * their own dark mode on top). Each email names the character, never the
 * username underneath. Sent as HTML from "Groddle Afternoon" at the address
 * the groddle_mail_from option names, else noreply@ the site's host.
 */
final class PlayerMail
{
    /** The site's palette (restoration/restoration.css, light). */
    private const BG = '#dcefc9';
    private const SURFACE = '#fbfaf2';
    private const BORDER = '#b8c9aa';
    private const INK = '#30462c';
    private const MUTED = '#697164';
    private const PLAY = '#e58b3f';
    private const SERIF = "'Iowan Old Style', Charter, Georgia, 'Times New Roman', serif";
    private const SANS = "'Trebuchet MS', -apple-system, 'Segoe UI', Arial, sans-serif";

    /**
     * The password reset email.
     *
     * @return array{0: string, 1: string, 2: list<string>} subject, HTML body, headers
     */
    public static function reset(string $home, string $from, string $link, string $character): array
    {
        $content = self::heading('Reset your password')
            . self::paragraph('Hi <strong>' . Html::esc($character) . '</strong>,')
            . self::paragraph('Someone asked to reset the password for your Groddle Afternoon character. If it was you, choose a new one below. The link works once, for a day.')
            . self::button($link, 'Choose a new password')
            . self::small('Or paste this into your browser:<br><a href="' . Html::attr($link) . '" style="color:' . self::INK . ';word-break:break-all;">' . Html::esc($link) . '</a>')
            . self::paragraph('If it wasn\'t you, ignore this email and nothing changes.');
        $subject = 'Reset your Groddle Afternoon password';
        return [$subject, self::layout($home, 'Choose a new password for ' . $character . '.', $content), self::headers($home, $from)];
    }

    /**
     * The email that proves a new player's address: no account exists until
     * its link is opened (Signups).
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    public static function verify(string $home, string $from, string $link, string $character): array
    {
        $content = self::heading('Check it\'s you')
            . self::paragraph('Hi <strong>' . Html::esc($character) . '</strong>,')
            . self::paragraph('Open the link below to finish making your Groddle Afternoon character. It works once, for a day.')
            . self::button($link, 'Start playing')
            . self::small('Or paste this into your browser:<br><a href="' . Html::attr($link) . '" style="color:' . self::INK . ';word-break:break-all;">' . Html::esc($link) . '</a>')
            . self::paragraph('If you didn\'t sign up, ignore this email and no character is made.');
        $subject = 'Finish making your Groddle Afternoon character';
        return [$subject, self::layout($home, 'One step left for ' . $character . '.', $content), self::headers($home, $from)];
    }

    /**
     * The notice that a password changed, to the account's address.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    public static function passwordChanged(string $home, string $from, string $character): array
    {
        $content = self::heading('Your password changed')
            . self::paragraph('Hi <strong>' . Html::esc($character) . '</strong>,')
            . self::paragraph('The password for your Groddle Afternoon character was just changed, and every other device was signed out.')
            . self::paragraph('If it wasn\'t you, choose a new password now: open the game, Play with others, and "Forgot your password?".')
            . self::button(rtrim($home, '/') . '/play/online/', 'Open Groddle Afternoon');
        return ['Your Groddle Afternoon password changed', self::layout($home, 'The password for ' . $character . ' changed.', $content), self::headers($home, $from)];
    }

    /**
     * The notice that the sign-in email changed, to the old address.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    public static function emailChanged(string $home, string $from, string $character, string $newEmail, string $contact = ''): array
    {
        $help = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false
            ? 'If it wasn\'t you, write to <a href="mailto:' . Html::attr($contact) . '" style="color:' . self::INK . ';">' . Html::esc($contact) . '</a> straight away.'
            : 'If it wasn\'t you, tell the site\'s owner straight away.';
        $content = self::heading('Your email changed')
            . self::paragraph('Hi <strong>' . Html::esc($character) . '</strong>,')
            . self::paragraph('Your Groddle Afternoon character now signs in with <strong>' . Html::esc($newEmail) . '</strong>. This address won\'t get its emails any more.')
            . self::paragraph($help);
        return ['Your Groddle Afternoon email changed', self::layout($home, $character . ' signs in with a new email.', $content), self::headers($home, $from)];
    }

    /**
     * An administrator replied to a player's bug report (AdminTickets): the
     * reply, quoted as plain text, and the way back to the thread in the game.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    public static function ticketReply(string $home, string $from, string $character, string $subject, string $reply, string $link): array
    {
        $hi = $character !== '' ? 'Hi <strong>' . Html::esc($character) . '</strong>,' : 'Hi,';
        $content = self::heading('We replied to your report')
            . self::paragraph($hi)
            . self::paragraph('An administrator replied to your bug report <strong>' . Html::esc($subject) . '</strong>:')
            . '<div style="margin:0 0 18px;padding:14px 16px;border-left:4px solid ' . self::BORDER . ';border-radius:8px;background:' . self::BG . ';color:' . self::INK . ';white-space:normal;">'
            . nl2br(Html::esc($reply), false) . '</div>'
            . self::button($link, 'Read it in the game')
            . self::small('Write back from the game: the bug button at the top, then your report. If more replies come before you read this one, we don\'t email again.');
        $subjectLine = 'A reply to your Groddle Afternoon bug report';
        return [$subjectLine, self::layout($home, 'An administrator replied to "' . $subject . '".', $content), self::headers($home, $from)];
    }

    /** From "Groddle Afternoon", sent as HTML. @return list<string> */
    public static function headers(string $home, string $from): array
    {
        $host = (string) (parse_url($home, PHP_URL_HOST) ?: 'localhost');
        $address = filter_var($from, FILTER_VALIDATE_EMAIL) !== false ? $from : "noreply@{$host}";
        return ['Content-Type: text/html; charset=UTF-8', "From: Groddle Afternoon <{$address}>"];
    }

    /** The whole email around its content: the mark and name, the card, the footer. */
    public static function layout(string $home, string $preheader, string $content): string
    {
        $home = rtrim($home, '/');
        $logo = Html::attr("{$home}/apple-touch-icon.png");
        $site = Html::attr("{$home}/");
        $hostLabel = Html::esc((string) (parse_url($home, PHP_URL_HOST) ?: $home));
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>Groddle Afternoon</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::BG . ';">'
            // The line inboxes show beside the subject, never shown in the email itself.
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . Html::esc($preheader) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::BG . ';">'
            . '<tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">'
            . '<tr><td align="center" style="padding:0 0 18px;">'
            . '<a href="' . $site . '" style="text-decoration:none;color:' . self::INK . ';">'
            . '<img src="' . $logo . '" width="48" height="48" alt="" style="display:block;margin:0 auto 8px;border:0;border-radius:12px;">'
            . '<span style="font-family:' . self::SERIF . ';font-size:22px;font-weight:600;color:' . self::INK . ';">Groddle Afternoon</span></a>'
            . '</td></tr>'
            . '<tr><td style="background:' . self::SURFACE . ';border:1px solid ' . self::BORDER . ';border-radius:16px;padding:30px 30px 26px;font-family:' . self::SANS . ';font-size:16px;line-height:1.55;color:' . self::INK . ';">'
            . $content
            . '</td></tr>'
            . '<tr><td align="center" style="padding:18px 12px 0;font-family:' . self::SANS . ';font-size:12px;line-height:1.5;color:' . self::MUTED . ';">'
            . 'Groddle Afternoon is a fan remake made with Tiny Speck\'s public-domain art.<br>'
            . '<a href="' . $site . '" style="color:' . self::MUTED . ';">' . $hostLabel . '</a>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function heading(string $text): string
    {
        return '<h1 style="margin:0 0 14px;font-family:' . self::SERIF . ';font-size:26px;line-height:1.2;font-weight:600;color:' . self::INK . ';">' . Html::esc($text) . '</h1>';
    }

    /** A paragraph of trusted markup (the callers escape what they put in it). */
    private static function paragraph(string $html): string
    {
        return '<p style="margin:0 0 14px;">' . $html . '</p>';
    }

    private static function small(string $html): string
    {
        return '<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:' . self::MUTED . ';">' . $html . '</p>';
    }

    /** The orange Play button, as a table so Outlook keeps its shape. */
    private static function button(string $href, string $label): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;"><tr>'
            . '<td style="border-radius:10px;background:' . self::PLAY . ';box-shadow:0 3px 0 #b85a24;">'
            . '<a href="' . Html::attr($href) . '" style="display:inline-block;padding:13px 24px;font-family:' . self::SANS . ';font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">' . Html::esc($label) . '</a>'
            . '</td></tr></table>';
    }
}
