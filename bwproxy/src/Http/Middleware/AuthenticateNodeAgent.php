<?php

namespace BWProject\BWProxy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNodeAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $nodeId = (string) $request->route('nodeId');
        $tokens = config('bwproxy.node_tokens', []);
        $expected = is_array($tokens) ? ($tokens[$nodeId] ?? null) : null;
        $provided = (string) $request->bearerToken();

        if (! is_string($expected) || $expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthenticated node agent'], 401);
        }

        return $next($request);
    }
}
