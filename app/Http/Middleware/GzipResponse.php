<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GzipResponse
{
    /**
     * Handle an incoming request and gzip compress responses above 1KB.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Skip streamed or file downloads
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        // Check if browser accepts gzip and zlib is available
        $acceptEncoding = $request->header('Accept-Encoding', '');
        if (function_exists('gzencode') && str_contains($acceptEncoding, 'gzip')) {
            $content = $response->getContent();
            // Only compress if content length is above 1024 bytes (avoids overhead for tiny responses)
            if ($content && strlen($content) > 1024 && !$response->headers->has('Content-Encoding')) {
                $compressed = gzencode($content, 5); // Level 5 gives optimal speed & 70-85% compression
                if ($compressed !== false) {
                    $response->setContent($compressed);
                    $response->headers->set('Content-Encoding', 'gzip');
                    $response->headers->set('Content-Length', (string)strlen($compressed));
                    $response->headers->set('Vary', 'Accept-Encoding');
                }
            }
        }

        return $response;
    }
}
