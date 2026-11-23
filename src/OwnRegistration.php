<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Closure;
use Minn\Runtime\Runtime;

/**
 * The game's own registrations are not a spam plugin's to judge (G1211, root R49). Every account the
 * game makes (a new character, a sign-up's mailed link, Continue with Google, a local QA sign-up) has
 * passed the game's checks first (Turnstile, the proven email, Google's token, the name rules) and goes
 * through Accounts::registerQuietly, which runs the registration_errors filter like any registration
 * (AccountFlows::vetRegistration). On live, Breakwater (the fleet's spam mu-plugin) refused every one
 * there with its "breakwater" error. Breakwater asks breakwater_skip before it judges a submission
 * (Guard::evaluate: apply_filters('breakwater_skip', self::trusted(), $s)); true skips it.
 *
 * run() hooks breakwater_skip, through the engine's own hook table (Runtime::hooks(), the one Minn maps
 * add_filter and apply_filters onto; the extension calls no WordPress functions), just before the
 * game's registration and takes it off right after, whatever happens, so nothing else on the site (the
 * site's own wp-login.php registration, comments, forms) is ever let past Breakwater by it.
 */
final class OwnRegistration
{
    /** Breakwater's filter: true skips its check of the submission in hand. */
    public const SKIP = 'breakwater_skip';

    /** Last of everything hooked there, so another plugin's own skip filter can't turn ours back off. */
    public const PRIORITY = PHP_INT_MAX;

    /**
     * Runs one of the game's own registrations with Breakwater told to skip it, and gives back what it gave.
     *
     * @template T
     * @param Closure(): T $register
     * @return T
     */
    public static function run(Closure $register): mixed
    {
        $hooks = Runtime::hooks();
        $skip = static fn (mixed $skip = false): bool => true;
        $hooks->add(self::SKIP, $skip, self::PRIORITY, 1);
        try {
            return $register();
        } finally {
            $hooks->remove(self::SKIP, $skip, self::PRIORITY);
        }
    }
}
