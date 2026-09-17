<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->last_activity_at || $user->last_activity_at->lt(now()->subMinutes(5)))) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
