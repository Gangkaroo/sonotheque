<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLocalOrAdminToken
{
    private const ADMIN_TOKEN_HEADER = 'X-Music-Library-Admin-Token';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldProtect($request) || $request->isMethod('OPTIONS') || $this->isLocalRequest($request)) {
            return $next($request);
        }

        if ($this->hasValidAdminToken($request)) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'This settings operation is only available locally or with a configured admin token.',
        ], Response::HTTP_FORBIDDEN);
    }

    private function shouldProtect(Request $request): bool
    {
        return collect(config('music-library.lan.protected_paths', []))
            ->contains(fn (string $path): bool => $request->is($path));
    }

    private function isLocalRequest(Request $request): bool
    {
        return in_array($request->ip(), [
            '127.0.0.1',
            '::1',
            '::ffff:127.0.0.1',
        ], true);
    }

    private function hasValidAdminToken(Request $request): bool
    {
        if (! config('music-library.lan.enabled')) {
            return false;
        }

        $configuredToken = (string) config('music-library.lan.admin_token', '');
        $requestToken = (string) $request->headers->get(self::ADMIN_TOKEN_HEADER, '');

        return $configuredToken !== ''
            && $requestToken !== ''
            && hash_equals($configuredToken, $requestToken);
    }
}
