<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

class TrustConfiguredHosts extends TrustHosts
{
    /** @return list<string> */
    public function hosts(): array
    {
        return config('sonotheque.lan.trusted_hosts', []);
    }

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }

    public function handle(Request $request, $next)
    {
        Request::setTrustedHosts(array_filter($this->hosts()));
        $request->getHost();

        return $next($request);
    }
}
