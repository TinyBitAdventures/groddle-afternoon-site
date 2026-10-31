<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Extension\Seams;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\RestError;

/**
 * The level builder's one gate (the game's docs/level-builder/spec.md 8.4, 13). Every builder
 * route is declared Access::Public and calls this first, because a builder key is not a Minn
 * sign-in: the router's policy would refuse a keyed call before the handler ran, and a key must
 * never count as one anywhere else. So:
 *
 * - the builder off (groddle_builder_enabled unset): 404 builder_disabled, before anything else;
 * - a request carrying X-Groddle-Builder is judged by its key alone (BuilderKeys::verify: the
 *   signature, the 60 s window, the nonce, the expiry, the scope the route needs, the rate);
 * - otherwise it is the console's session: the cookie with its REST nonce, and manage_options
 *   (401 signed out, 403 without the capability), as Policy(Access::Cap, 'manage_options') was.
 *
 * Routes that take the session only (keys, the play-test bundle, purge) pass $scope null: a key
 * there is refused with 403 builder_key_scope. Nothing outside the builder's routes reads the
 * header, so a key reaches no other admin route (admin/give answers it as a signed-out caller).
 */
final readonly class BuilderAuth
{
    public function __construct(private Seams $minn)
    {
    }

    /**
     * @param string|null $scope the scope a key needs; null for session-only routes
     * @param string $kind the rate bucket a keyed call counts in (BuilderKeys::RATES; read is free)
     */
    public function gate(Request $request, ?string $scope, string $kind = 'read'): BuilderActor
    {
        if (!AdminBuilder::enabled($this->minn)) {
            throw new RestError('builder_disabled', 'The level builder is not turned on for this site.', 404);
        }
        $header = $request->header(BuilderKeys::HEADER);
        if ($header !== null) {
            if ($scope === null) {
                throw new RestError('builder_key_scope', 'This route takes an administrator\'s session, never a builder key.', 403, ['scope' => null]);
            }
            $key = (new BuilderKeys($this->minn->db))->verify(
                self::master($this->minn),
                $header,
                $request->method->value,
                BuilderKeys::routeOf($request->path, $request->query('rest_route')),
                $request->query,
                $request->body,
                $scope,
                $kind,
            );
            return BuilderActor::key($key);
        }
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        if (!Capabilities::fromDb($this->minn->db)->can($user->id, 'manage_options')) {
            throw new RestError('rest_forbidden', 'Sorry, you are not allowed to do that.', 403);
        }
        return BuilderActor::session($user->id);
    }

    /** The master secret this site's keys come from, or null. */
    public static function master(Seams $minn): ?string
    {
        $named = $minn->site->option(BuilderKeys::SECRET_OPTION);
        return BuilderKeys::master(is_string($named) ? $named : null);
    }

    /** A refusal as the site shapes it, with Retry-After when it is a rate limit (routes that can answer it themselves). */
    public static function refusal(RestError $e): Response
    {
        $r = Response::json($e->payload(), $e->status)->withHeader('Cache-Control', 'no-store');
        $wait = $e->extra['retryAfter'] ?? null;
        return is_int($wait) ? $r->withHeader('Retry-After', (string) $wait) : $r;
    }
}
