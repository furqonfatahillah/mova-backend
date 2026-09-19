<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceOutletScope
{
    /**
     * Handle an incoming request.
     * Enforces strict outlet-level isolation for employee and outlet-level users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Enforce Real-time SaaS Subscription Status
        if ($user && $user->business && !$user->isSuperadminPlatform()) {
            $path = $request->path();
            $isExempt = str_contains($path, 'logout') || str_contains($path, 'me') || str_contains($path, 'my-business');

            if (!$isExempt) {
                if ($user->business->status === 'suspended') {
                    return response()->json([
                        'message' => 'Akses bisnis Anda telah dinonaktifkan sementara oleh pengelola platform. Silakan hubungi admin pengelola.'
                    ], 403);
                }
                if ($user->business->status === 'expired' || ($user->business->expires_at && $user->business->expires_at->isPast())) {
                    return response()->json([
                        'message' => 'Masa aktif langganan bisnis Anda telah berakhir. Silakan hubungi pengelola platform untuk perpanjangan sewa.'
                    ], 403);
                }
            }
        }

        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $userOutletId = (int) $user->outlet_id;

            // 1. Force X-Outlet-Id header to user's assigned outlet
            $request->headers->set('X-Outlet-Id', (string) $userOutletId);

            // 2. If the request has an outlet_id in body or query, override it to user's assigned outlet
            if ($request->has('outlet_id') || $request->filled('outlet_id')) {
                $request->merge(['outlet_id' => $userOutletId]);
            }

            // 3. For GET requests where outlet_id is not explicitly specified, auto-inject user's outlet_id
            //    for operational endpoints that query transactions, shifts, inventory, etc.
            if ($request->isMethod('GET') && !$request->has('outlet_id')) {
                $path = $request->path();
                if (
                    str_contains($path, 'transactions') ||
                    str_contains($path, 'shifts') ||
                    str_contains($path, 'movements') ||
                    str_contains($path, 'stock-card') ||
                    str_contains($path, 'urgent-notes') ||
                    str_contains($path, 'opnames') ||
                    str_contains($path, 'waste-logs') ||
                    str_contains($path, 'batch-preps') ||
                    str_contains($path, 'expenses') ||
                    str_contains($path, 'cash-transactions') ||
                    str_contains($path, 'reports') ||
                    str_contains($path, 'discounts')
                ) {
                    $request->merge(['outlet_id' => $userOutletId]);
                }
            }
        }

        return $next($request);
    }
}
