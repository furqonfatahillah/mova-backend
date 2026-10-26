<?php

namespace App\Http\Controllers;

use App\Mail\RegisterOtpMail;
use App\Mail\ResetPasswordOtpMail;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Kirim kode OTP 6-digit ke email calon pendaftar bisnis
     */
    public function sendRegisterOtp(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:users,email',
            'password'      => 'required|string|min:8|confirmed',
            'business_name' => 'required|string|max:255',
            'referral_code' => 'nullable|string|max:50',
        ], [
            'name.required'          => 'Nama pemilik wajib diisi.',
            'email.required'         => 'Alamat email wajib diisi.',
            'email.email'            => 'Format email tidak valid.',
            'email.unique'           => 'Alamat email ini sudah terdaftar. Silakan login atau gunakan email lain.',
            'password.required'      => 'Kata sandi wajib diisi.',
            'password.min'           => 'Kata sandi minimal 8 karakter.',
            'password.confirmed'     => 'Konfirmasi kata sandi tidak cocok.',
            'business_name.required' => 'Nama usaha/bisnis wajib diisi.',
        ]);

        // Validasi kode referral jika diisi
        if (!empty($data['referral_code'])) {
            $cleanReferralCode = strtoupper(trim($data['referral_code']));
            $refCodeObj = ReferralCode::where('code', $cleanReferralCode)->first();
            if ($refCodeObj) {
                if (!$refCodeObj->is_active) {
                    throw ValidationException::withMessages([
                        'referral_code' => ["Kode referral '{$cleanReferralCode}' saat ini dinonaktifkan."],
                    ]);
                }
                if ($refCodeObj->expires_at && $refCodeObj->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        'referral_code' => ["Kode referral '{$cleanReferralCode}' telah kedaluwarsa."],
                    ]);
                }
                if ($refCodeObj->max_uses !== null && $refCodeObj->times_used >= $refCodeObj->max_uses) {
                    throw ValidationException::withMessages([
                        'referral_code' => ["Kuota penggunaan kode referral '{$cleanReferralCode}' telah habis."],
                    ]);
                }
            } else {
                $referrerUser = User::where('referral_code', $cleanReferralCode)->first();
                if (!$referrerUser) {
                    throw ValidationException::withMessages([
                        'referral_code' => ["Kode referral '{$cleanReferralCode}' tidak ditemukan atau tidak valid."],
                    ]);
                }
            }
        }

        $email = strtolower(trim($data['email']));
        $name = trim($data['name']);

        // Generate 6-digit numeric OTP code
        $otp = (string) random_int(100000, 999999);

        // Store to email_verification_codes table (update or insert)
        DB::table('email_verification_codes')->updateOrInsert(
            ['email' => $email],
            [
                'code'       => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        try {
            Mail::to($email)->send(
                new RegisterOtpMail($name, $otp, 15)
            );
        } catch (\Throwable $e) {
            Log::error("Failed sending register verification email to {$email}: " . $e->getMessage());
            DB::table('email_verification_codes')->where('email', $email)->delete();
            $errSnippet = Str::limit(strip_tags($e->getMessage()), 120);
            throw ValidationException::withMessages([
                'email' => ["Gagal mengirimkan email verifikasi ke {$email} ({$errSnippet}). Pastikan konfigurasi SMTP di file .env server sudah sesuai."],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Kode verifikasi 6-digit telah dikirim ke {$email}. Silakan periksa kotak masuk atau folder spam email Anda.",
        ]);
    }

    public function register(Request $request)
    {
        $regType = $request->input('registration_type', 'business');

        // Skenario: Pendaftaran Bisnis Baru (Penyewa / Owner Bisnis)
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
                'verification_code' => 'required|string|size:6',
            ], [
                'email.unique'               => 'Alamat email ini sudah terdaftar. Silakan login atau gunakan email lain.',
                'business_name.required'     => 'Nama usaha/bisnis wajib diisi.',
                'verification_code.required' => 'Kode verifikasi email (OTP) wajib diisi.',
                'verification_code.size'     => 'Kode verifikasi email harus 6 digit angka.',
            ]);

            $email = strtolower(trim($data['email']));
            $code = trim($data['verification_code']);

            // Verifikasi Kode OTP dari email_verification_codes
            $otpRecord = DB::table('email_verification_codes')->where('email', $email)->first();

            if (!$otpRecord) {
                throw ValidationException::withMessages([
                    'verification_code' => ['Tidak ada permintaan kode verifikasi yang aktif untuk email ini. Silakan klik kirim ulang kode.'],
                ]);
            }

            if (Carbon::parse($otpRecord->created_at)->addMinutes(15)->isPast()) {
                DB::table('email_verification_codes')->where('email', $email)->delete();
                throw ValidationException::withMessages([
                    'verification_code' => ['Kode verifikasi email telah kedaluwarsa (lebih dari 15 menit). Silakan minta kode baru.'],
                ]);
            }

            if (!Hash::check($code, $otpRecord->code)) {
                throw ValidationException::withMessages([
                    'verification_code' => ['Kode verifikasi email yang Anda masukkan salah atau tidak sesuai.'],
                ]);
            }

            // Validate referral code if provided
            $refCodeObj = null;
            $referrerUser = null;
            $cleanReferralCode = null;
            if (!empty($data['referral_code'])) {
                $cleanReferralCode = strtoupper(trim($data['referral_code']));

                // 1. Check in Superadmin generated referral_codes table
                $refCodeObj = ReferralCode::where('code', $cleanReferralCode)->first();
                if ($refCodeObj) {
                    if (!$refCodeObj->is_active) {
                        throw ValidationException::withMessages([
                            'referral_code' => ["Kode referral '{$cleanReferralCode}' saat ini dinonaktifkan."],
                        ]);
                    }
                    if ($refCodeObj->expires_at && $refCodeObj->expires_at->isPast()) {
                        throw ValidationException::withMessages([
                            'referral_code' => ["Kode referral '{$cleanReferralCode}' telah kedaluwarsa."],
                        ]);
                    }
                    if ($refCodeObj->max_uses !== null && $refCodeObj->times_used >= $refCodeObj->max_uses) {
                        throw ValidationException::withMessages([
                            'referral_code' => ["Kuota penggunaan kode referral '{$cleanReferralCode}' telah habis."],
                        ]);
                    }
                } else {
                    // 2. Check legacy user referral code for backward compatibility
                    $referrerUser = User::where('referral_code', $cleanReferralCode)->first();
                    if (!$referrerUser) {
                        throw ValidationException::withMessages([
                            'referral_code' => ["Kode referral '{$cleanReferralCode}' tidak ditemukan atau tidak valid."],
                        ]);
                    }
                }
            }

            $ipAddress = $request->ip();

            $result = DB::transaction(function () use ($data, $refCodeObj, $referrerUser, $cleanReferralCode, $ipAddress, $email) {
                $slug = Str::slug($data['business_name']);
                $origSlug = $slug;
                $c = 1;
                while (Business::where('slug', $slug)->exists()) {
                    $slug = "{$origSlug}-{$c}";
                    $c++;
                }

                // 1. Buat Bisnis / Tenant (Langsung Aktif dengan Mode Unlimited Gratis)
                $business = Business::create([
                    'name'                  => $data['business_name'],
                    'slug'                  => $slug,
                    'owner_name'            => $data['name'],
                    'email'                 => $data['email'],
                    'phone'                 => $data['business_phone'] ?? null,
                    'address'               => $data['business_address'] ?? null,
                    'referred_by_id'        => $refCodeObj?->created_by ?? $referrerUser?->id,
                    'referral_code_id'      => $refCodeObj?->id,
                    'referral_code_used'    => $cleanReferralCode,
                    'package_type'          => 'unlimited',
                    'max_outlets'           => 10,
                    'status'                => 'active', // Langsung Aktif tanpa perlu approval
                    'is_unlimited_coins'    => true,     // Mode Unlimited Gratis
                    'coins_per_transaction' => 0,        // Bebas potong koin
                    'coin_balance'          => 0.00,
                    'expires_at'            => now()->addMonth(), // Default masa aktif 1 bulan dari tanggal pendaftaran
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

                // 3. Buat User Owner Bisnis (Langsung Aktif & Email Terverifikasi)
                $user = User::create([
                    'business_id'       => $business->id,
                    'outlet_id'         => $outlet->id,
                    'name'              => $data['name'],
                    'email'             => $data['email'],
                    'email_verified_at' => now(),
                    'password'          => Hash::make($data['password']),
                    'role'              => 'owner_bisnis',
                    'status'            => 'active', // Langsung Aktif
                    'referred_by_id'    => $refCodeObj?->created_by ?? $referrerUser?->id,
                    'approved_at'       => now(),
                ]);

                $business->update(['created_by' => $user->id]);
                $outlet->update(['created_by' => $user->id]);

                // 4. Inisialisasi Chart of Accounts (COA) untuk bisnis baru
                \App\Models\Account::seedForBusiness((int)$business->id);

                // 5. Catat penggunaan kode referral dan increment counter
                if ($refCodeObj) {
                    $refCodeObj->increment('times_used');

                    ReferralUsage::create([
                        'referral_code_id' => $refCodeObj->id,
                        'business_id'      => $business->id,
                        'user_id'          => $user->id,
                        'registered_at'    => now(),
                        'ip_address'       => $ipAddress,
                        'notes'            => "Pendaftaran bisnis {$business->name} oleh {$user->name}",
                    ]);
                }

                // 6. Hapus kode verifikasi email yang sudah berhasil digunakan
                DB::table('email_verification_codes')->where('email', $email)->delete();

                // 7. Generate Sanctum Token untuk Langsung Login
                $token = $user->createToken('pos_auth_token')->plainTextToken;

                return [
                    'token'    => $token,
                    'user'     => $user->load(['outlet', 'business', 'referredBy']),
                    'business' => $business->load(['referredBy', 'referralCode']),
                    'outlet'   => $outlet,
                ];
            });

            return response()->json([
                'message'  => "Pendaftaran bisnis '{$result['business']->name}' berhasil! Email Anda telah diverifikasi dan akun aktif secara otomatis.",
                'status'   => 'active',
                'token'    => $result['token'],
                'user'     => $result['user'],
                'business' => $result['business'],
                'outlet'   => $result['outlet'],
            ], 201);
        }

        // Pegawai dan Manager tidak bisa membuat akun sendiri secara publik; akun harus dibuatkan oleh Owner Bisnis
        return response()->json([
            'message' => 'Pendaftaran mandiri untuk akun pegawai dan manajer tidak diizinkan. Akun staf harus dibuatkan langsung oleh Owner Bisnis melalui menu Kelola Pengguna di dalam aplikasi.',
        ], 403);
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

        // Auto-aktivasi akun jika masih pending agar bisa langsung login tanpa perlu persetujuan
        if ($user->status === 'pending') {
            $user->update([
                'status'      => 'active',
                'approved_at' => now(),
            ]);
            $user->status = 'active';
            $user->approved_at = now();
        }

        if ($user->status === 'rejected') {
            $isBizOwner = $user->role === 'owner_bisnis';
            $msg = $isBizOwner
                ? 'Pendaftaran akun bisnis Anda telah ditolak oleh Superadmin Platform. Silakan hubungi pengelola platform.'
                : 'Pendaftaran akun Anda ditolak oleh Owner Bisnis. Silakan hubungi admin.';
            throw ValidationException::withMessages([
                'email' => [$msg],
            ]);
        }

        if ($user->status === 'suspended') {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda telah dinonaktifkan (suspended). Silakan hubungi admin.'],
            ]);
        }

        // Cek status bisnis (SaaS subscription)
        if ($user->business && !$user->isSuperadminPlatform()) {
            // Auto-aktivasi bisnis jika masih berstatus pending ke Mode Unlimited Gratis
            if ($user->business->status === 'pending') {
                $user->business->update([
                    'status'                => 'active',
                    'package_type'          => 'unlimited',
                    'is_unlimited_coins'    => true,
                    'coins_per_transaction' => 0,
                    'expires_at'            => null,
                ]);
                $user->business->refresh();
            }

            if ($user->business->status === 'rejected') {
                throw ValidationException::withMessages([
                    'email' => ['Pendaftaran bisnis Anda telah ditolak oleh Superadmin Platform. Silakan hubungi pengelola platform.'],
                ]);
            }
            if ($user->business->status === 'suspended') {
                throw ValidationException::withMessages([
                    'email' => ['Akses bisnis Anda telah dinonaktifkan sementara oleh pengelola platform. Silakan hubungi pengelola.'],
                ]);
            }

            // Cek masa aktif bisnis / paket langganan (Termasuk Mode Unlimited yang masa aktifnya telah habis)
            if ($user->business->status === 'expired' || ($user->business->expires_at && $user->business->expires_at->isPast())) {
                throw ValidationException::withMessages([
                    'email' => ['Masa aktif langganan bisnis Anda telah berakhir. Silakan hubungi pengelola platform untuk perpanjangan sewa.'],
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

        try {
            Mail::to($user->email)->send(
                new ResetPasswordOtpMail($user->name, $otp, 15)
            );
        } catch (\Throwable $e) {
            Log::error("Failed sending reset password email to {$email}: " . $e->getMessage());
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            $errSnippet = Str::limit(strip_tags($e->getMessage()), 120);
            throw ValidationException::withMessages([
                'email' => ["Gagal mengirimkan email reset password ke {$email} ({$errSnippet}). Pastikan konfigurasi SMTP di file .env server sudah sesuai."],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Kode verifikasi 6-digit telah dikirim ke {$email}. Silakan periksa kotak masuk atau folder spam email Anda.",
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
