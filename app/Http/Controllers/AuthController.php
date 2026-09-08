<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $regType = $request->input('registration_type', 'staff');

        // Skenario A: Pendaftaran Bisnis Baru (Penyewa / Owner Bisnis)
        if ($regType === 'business' || $request->filled('business_name')) {
            $data = $request->validate([
                'name'              => 'required|string|max:255',
                'email'             => 'required|email|unique:users,email',
                'password'          => 'required|string|min:8|confirmed',
                'business_name'     => 'required|string|max:255',
                'business_phone'    => 'nullable|string|max:50',
                'business_address'  => 'nullable|string|max:500',
                'first_outlet_name' => 'nullable|string|max:255',
            ], [
                'business_name.required' => 'Nama usaha/bisnis wajib diisi.',
            ]);

            $result = DB::transaction(function () use ($data) {
                $slug = Str::slug($data['business_name']);
                $origSlug = $slug;
                $c = 1;
                while (Business::where('slug', $slug)->exists()) {
                    $slug = "{$origSlug}-{$c}";
                    $c++;
                }

                // 1. Buat Bisnis / Tenant
                $business = Business::create([
                    'name'         => $data['business_name'],
                    'slug'         => $slug,
                    'owner_name'   => $data['name'],
                    'email'        => $data['email'],
                    'phone'        => $data['business_phone'] ?? null,
                    'address'      => $data['business_address'] ?? null,
                    'package_type' => 'pro',
                    'max_outlets'  => 5,
                    'status'       => 'active',
                    'expires_at'   => now()->addMonths(1), // Trial 30 hari
                ]);

                // 2. Buat Cabang Pertama untuk Bisnis ini
                $outletName = !empty($data['first_outlet_name'])
                    ? $data['first_outlet_name']
                    : "Outlet Pusat - {$data['business_name']}";

                $outlet = Outlet::create([
                    'business_id' => $business->id,
                    'code'        => 'OUT-001',
                    'name'        => $outletName,
                    'address'     => $data['business_address'] ?? null,
                    'phone'       => $data['business_phone'] ?? null,
                    'pic_name'    => $data['name'],
                    'is_main'     => true,
                    'active'      => true,
                ]);

                // 3. Buat User Owner Bisnis
                $user = User::create([
                    'business_id' => $business->id,
                    'outlet_id'   => $outlet->id,
                    'name'        => $data['name'],
                    'email'       => $data['email'],
                    'password'    => Hash::make($data['password']),
                    'role'        => 'owner_bisnis',
                    'status'      => 'active',
                    'approved_at' => now(),
                ]);

                $business->update(['created_by' => $user->id]);
                $outlet->update(['created_by' => $user->id]);

                $token = $user->createToken('pos-token')->plainTextToken;

                return [
                    'user'     => $user->load(['outlet', 'business']),
                    'business' => $business,
                    'outlet'   => $outlet,
                    'token'    => $token,
                ];
            });

            return response()->json([
                'message'  => "Pendaftaran bisnis '{$result['business']->name}' berhasil! Anda telah masuk sebagai Owner Bisnis.",
                'status'   => 'active',
                'user'     => $result['user'],
                'token'    => $result['token'],
                'business' => $result['business'],
            ], 201);
        }

        // Skenario B: Pendaftaran Pegawai / Manager di Bisnis yang Sudah Ada
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:users,email',
            'password'    => 'required|string|min:8|confirmed',
            'role'        => 'required|in:pegawai,owner_outlet,kasir,manager',
            'business_id' => 'required|exists:businesses,id',
            'outlet_id'   => 'required|exists:outlets,id',
        ], [
            'business_id.required' => 'Pilih usaha tempat Anda bertugas.',
            'outlet_id.required'   => 'Pilih cabang outlet tempat Anda bertugas.',
        ]);

        $role = $data['role'];
        if ($role === 'kasir' || $role === 'manager') {
            $role = 'pegawai';
        }

        $user = User::create([
            'business_id' => $data['business_id'],
            'outlet_id'   => $data['outlet_id'],
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => Hash::make($data['password']),
            'role'        => $role,
            'status'      => 'pending',
        ]);

        $approverRoleText = 'Owner Bisnis';

        return response()->json([
            'message' => "Pendaftaran berhasil! Akun Anda sedang menunggu persetujuan (approval) dari {$approverRoleText} sebelum dapat digunakan untuk masuk.",
            'status'  => 'pending',
            'user'    => $user->load(['outlet', 'business']),
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::with(['outlet', 'business'])->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if ($user->status === 'pending') {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda sedang menunggu persetujuan (approval) dari Owner Bisnis / Admin. Silakan hubungi admin untuk aktivasi akun.'],
            ]);
        }

        if ($user->status === 'rejected') {
            throw ValidationException::withMessages([
                'email' => ['Pendaftaran akun Anda ditolak oleh Owner Bisnis. Silakan hubungi admin.'],
            ]);
        }

        if ($user->status === 'suspended') {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda telah dinonaktifkan (suspended). Silakan hubungi admin.'],
            ]);
        }

        // Cek status bisnis (SaaS subscription)
        if ($user->business && !$user->isSuperadminPlatform()) {
            if ($user->business->status === 'suspended') {
                throw ValidationException::withMessages([
                    'email' => ['Akses bisnis Anda telah dinonaktifkan sementara oleh pengelola platform. Silakan hubungi pengelola.'],
                ]);
            }
            if ($user->business->status === 'expired' || ($user->business->expires_at && $user->business->expires_at->isPast())) {
                throw ValidationException::withMessages([
                    'email' => ['Masa aktif langganan bisnis Anda telah berakhir. Silakan hubungi pengelola untuk perpanjangan sewa.'],
                ]);
            }
        }

        $token = $user->createToken('pos-token')->plainTextToken;
        $user->load(['outlet', 'business', 'approver']);

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load(['outlet', 'business']));
    }
}
