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
                'referral_code'     => 'nullable|string|max:50',
            ], [
                'business_name.required' => 'Nama usaha/bisnis wajib diisi.',
            ]);

            // Validate referral code if provided
            $referrer = null;
            $cleanReferralCode = null;
            if (!empty($data['referral_code'])) {
                $cleanReferralCode = strtoupper(trim($data['referral_code']));
                $referrer = User::where('referral_code', $cleanReferralCode)->first();
                if (!$referrer) {
                    throw ValidationException::withMessages([
                        'referral_code' => ["Kode referral '{$cleanReferralCode}' tidak ditemukan atau tidak valid."],
                    ]);
                }
            }

            $result = DB::transaction(function () use ($data, $referrer, $cleanReferralCode) {
                $slug = Str::slug($data['business_name']);
                $origSlug = $slug;
                $c = 1;
                while (Business::where('slug', $slug)->exists()) {
                    $slug = "{$origSlug}-{$c}";
                    $c++;
                }

                // 1. Buat Bisnis / Tenant
                $business = Business::create([
                    'name'               => $data['business_name'],
                    'slug'               => $slug,
                    'owner_name'         => $data['name'],
                    'email'              => $data['email'],
                    'phone'              => $data['business_phone'] ?? null,
                    'address'            => $data['business_address'] ?? null,
                    'referred_by_id'     => $referrer?->id,
                    'referral_code_used' => $cleanReferralCode,
                    'package_type'       => 'pro',
                    'max_outlets'        => 5,
                    'status'             => 'active',
                    'expires_at'         => now()->addMonths(1), // Trial 30 hari
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
                    'business_id'    => $business->id,
                    'outlet_id'      => $outlet->id,
                    'name'           => $data['name'],
                    'email'          => $data['email'],
                    'password'       => Hash::make($data['password']),
                    'role'           => 'owner_bisnis',
                    'status'         => 'active',
                    'referred_by_id' => $referrer?->id,
                    'approved_at'    => now(),
                ]);

                $business->update(['created_by' => $user->id]);
                $outlet->update(['created_by' => $user->id]);

                $token = $user->createToken('pos-token')->plainTextToken;

                return [
                    'user'     => $user->load(['outlet', 'business', 'referredBy']),
                    'business' => $business->load(['referredBy']),
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

    /**
     * Minta kode OTP 6-digit untuk Reset Password
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Alamat email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
        ]);

        $email = strtolower(trim($request->email));
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Alamat email tidak terdaftar dalam sistem MOVA POS.'],
            ]);
        }

        if ($user->status === 'suspended') {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda sedang dinonaktifkan (suspended). Silakan hubungi pengelola.'],
            ]);
        }

        // Generate 6-digit numeric OTP code
        $otp = (string) random_int(100000, 999999);

        // Store to password_reset_tokens table (replace existing for this email)
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token'      => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        $mailSent = false;
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\ResetPasswordOtpMail($user->name, $otp, 15)
            );
            $mailSent = true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed sending reset password email to {$email}: " . $e->getMessage());
        }

        return response()->json([
            'success'   => true,
            'message'   => "Kode verifikasi 6-digit telah dikirim ke {$email}. Silakan periksa kotak masuk atau spam email Anda.",
            'mail_sent' => $mailSent,
            'debug_otp' => (config('app.debug') && !$mailSent) ? $otp : null,
        ]);
    }

    /**
     * Verifikasi kode OTP 6-digit
     */
    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ], [
            'code.required' => 'Kode verifikasi wajib diisi.',
            'code.size'     => 'Kode verifikasi harus 6 digit angka.',
        ]);

        $email = strtolower(trim($request->email));
        $code = trim($request->code);

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record) {
            throw ValidationException::withMessages([
                'code' => ['Tidak ada permintaan reset password yang aktif untuk email ini.'],
            ]);
        }

        // Check 15-minute expiration
        if (\Carbon\Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw ValidationException::withMessages([
                'code' => ['Kode verifikasi telah kedaluwarsa (lebih dari 15 menit). Silakan minta kode baru.'],
            ]);
        }

        if (!Hash::check($code, $record->token)) {
            throw ValidationException::withMessages([
                'code' => ['Kode verifikasi yang Anda masukkan salah atau tidak sesuai.'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kode verifikasi valid. Silakan buat kata sandi baru.',
        ]);
    }

    /**
     * Simpan kata sandi baru
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'code'     => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.required'  => 'Password baru wajib diisi.',
            'password.min'       => 'Password baru minimal harus 8 karakter.',
            'password.confirmed' => 'Konfirmasi password baru tidak cocok.',
        ]);

        $email = strtolower(trim($request->email));
        $code = trim($request->code);

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record) {
            throw ValidationException::withMessages([
                'code' => ['Permintaan reset password tidak valid atau telah selesai digunakan.'],
            ]);
        }

        if (\Carbon\Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw ValidationException::withMessages([
                'code' => ['Kode verifikasi telah kedaluwarsa. Silakan minta kode baru.'],
            ]);
        }

        if (!Hash::check($code, $record->token)) {
            throw ValidationException::withMessages([
                'code' => ['Kode verifikasi salah.'],
            ]);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Pengguna tidak ditemukan.'],
            ]);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete used token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Revoke active login tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diperbarui! Silakan login dengan password baru Anda.',
        ]);
    }
}
