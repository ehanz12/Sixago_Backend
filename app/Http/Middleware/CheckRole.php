<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Tolak dengan 403 bila role user tidak termasuk yang diizinkan.
     * Super Admin selalu lolos. Contoh: ->middleware('role:Finance Approver,Super Admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || (! $user->isSuperAdmin() && ! $user->hasRole(...$roles))) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        return $next($request);
    }
}
