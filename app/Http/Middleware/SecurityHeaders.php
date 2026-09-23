<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that close off whole categories of attack.
 *
 * The console had none, which made it framable: a page elsewhere could load
 * /admin in an invisible iframe and trick a signed-in moderator into clicking
 * a destructive bulk action they never saw. That is the one that mattered here,
 * because the console's buttons are permanent.
 */
class SecurityHeaders
{
    /**
     * A deliberately narrow policy.
     *
     * `script-src` is absent on purpose. Livewire and Alpine both run inline
     * script, so a script policy worth having needs per-request nonces
     * threaded through every layout — worth doing, but as its own change with
     * its own testing, not smuggled in behind a header middleware. What is
     * here costs nothing and breaks nothing:
     *
     *   frame-ancestors  the clickjacking fix, and the modern form of the
     *                    X-Frame-Options header sent alongside it for older
     *                    browsers
     *   base-uri         stops an injected <base> silently repointing every
     *                    relative URL on the page
     *   form-action      stops an injected form posting credentials elsewhere
     *   object-src       no plugins, ever
     */
    private const CONTENT_SECURITY_POLICY = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            // Send the full URL within our own site and only the origin
            // elsewhere: a member profile URL should not travel to whatever a
            // member links to.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Nothing here needs a camera, a microphone or a location beyond
            // what the member types in.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];

        // Only over HTTPS. Sending HSTS from a plain-HTTP development server
        // would teach the browser to refuse http://localhost afterwards.
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            // Never clobber a header a response set deliberately.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
