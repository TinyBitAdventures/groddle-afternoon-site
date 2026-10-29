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
 * /privacy and /privacy/ go to the privacy policy, a Minn page at /privacy-policy/ (the footers, the
 * start card and the sign-up card link there).
 */
final readonly class PrivacyLinks
{
    public const PATH = '/privacy-policy/';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/privacy', policy: new Policy(Access::Public))]
    public function bare(Request $request): Response
    {
        return Response::redirect(self::PATH, 301);
    }

    #[Route(Method::Get, '/privacy/', policy: new Policy(Access::Public))]
    public function slash(Request $request): Response
    {
        return Response::redirect(self::PATH, 301);
    }
}
