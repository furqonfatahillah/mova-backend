<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\PaymentGatewaySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentConfigurationController extends Controller
{
    // ==========================================
    // 1. REKENING BANK & QRIS TOKO
    // ==========================================

    public function indexBankAccounts(Request $request)
    {
        $businessId = $request->user()->business_id;
        $query = BankAccount::with(['outlet'])
            ->where('business_id', $businessId)
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where(function ($q) use ($request) {
                $q->where('outlet_id', $request->outlet_id)
                  ->orWhereNull('outlet_id');
            });
        }

        return response()->json([
            'status' => 'success',
            'data'   => $query->get(),
        ]);
    }

    public function storeBankAccount(Request $request)
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'bank_name'      => 'required|string|max:100',
            'bank_code'      => 'nullable|string|max:20',
            'account_number' => 'required|string|max:50',
            'account_holder' => 'required|string|max:255',
            'branch'         => 'nullable|string|max:255',
            'outlet_id'      => 'nullable|exists:outlets,id',
            'qr_image_url'   => 'nullable|string',
            'is_primary'     => 'nullable|boolean',
            'is_active'      => 'nullable|boolean',
            'notes'          => 'nullable|string|max:500',
        ]);

        $validated['business_id'] = $businessId;
        $validated['created_by']  = $request->user()->id;

        // If this is set as primary, unset other accounts in the same business
        if (!empty($validated['is_primary'])) {
            BankAccount::where('business_id', $businessId)->update(['is_primary' => false]);
        } else {
            // If it's the very first account, make it primary automatically
            $existingCount = BankAccount::where('business_id', $businessId)->count();
            if ($existingCount === 0) {
                $validated['is_primary'] = true;
            }
        }

        $account = BankAccount::create($validated);
        $account->load('outlet');

        return response()->json([
            'status'  => 'success',
            'message' => 'Nomor rekening berhasil didaftarkan',
            'data'    => $account,
        ], 201);
    }

    public function updateBankAccount(Request $request, $id)
    {
        $businessId = $request->user()->business_id;
        $account = BankAccount::where('business_id', $businessId)->findOrFail($id);

        $validated = $request->validate([
            'bank_name'      => 'sometimes|required|string|max:100',
            'bank_code'      => 'nullable|string|max:20',
            'account_number' => 'sometimes|required|string|max:50',
            'account_holder' => 'sometimes|required|string|max:255',
            'branch'         => 'nullable|string|max:255',
            'outlet_id'      => 'nullable|exists:outlets,id',
            'qr_image_url'   => 'nullable|string',
            'is_primary'     => 'nullable|boolean',
            'is_active'      => 'nullable|boolean',
            'notes'          => 'nullable|string|max:500',
        ]);

        $validated['updated_by'] = $request->user()->id;

        if (!empty($validated['is_primary']) && !$account->is_primary) {
            BankAccount::where('business_id', $businessId)->update(['is_primary' => false]);
        }

        $account->update($validated);
        $account->load('outlet');

        return response()->json([
            'status'  => 'success',
            'message' => 'Data rekening berhasil diperbarui',
            'data'    => $account,
        ]);
    }

    public function destroyBankAccount(Request $request, $id)
    {
        $businessId = $request->user()->business_id;
        $account = BankAccount::where('business_id', $businessId)->findOrFail($id);
        $account->delete();

        // If the deleted account was primary, set another account as primary
        $next = BankAccount::where('business_id', $businessId)->first();
        if ($next && $account->is_primary) {
            $next->update(['is_primary' => true]);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Nomor rekening berhasil dihapus',
        ]);
    }

    public function setPrimaryBankAccount(Request $request, $id)
    {
        $businessId = $request->user()->business_id;
        BankAccount::where('business_id', $businessId)->update(['is_primary' => false]);

        $account = BankAccount::where('business_id', $businessId)->findOrFail($id);
        $account->update(['is_primary' => true, 'is_active' => true]);

        return response()->json([
            'status'  => 'success',
            'message' => "Rekening {$account->bank_name} - {$account->account_number} dijadikan sebagai rekening utama",
            'data'    => $account,
        ]);
    }

    public function toggleActiveBankAccount(Request $request, $id)
    {
        $businessId = $request->user()->business_id;
        $account = BankAccount::where('business_id', $businessId)->findOrFail($id);
        $account->update(['is_active' => !$account->is_active]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Status aktif rekening berhasil diubah',
            'data'    => $account,
        ]);
    }

    // ==========================================
    // 2. PAYMENT GATEWAY (MIDTRANS & XENDIT)
    // ==========================================

    public function getGatewayConfig(Request $request)
    {
        $businessId = $request->user()->business_id;
        $config = PaymentGatewaySetting::where('business_id', $businessId)->first();

        if (!$config) {
            $config = PaymentGatewaySetting::create([
                'business_id'    => $businessId,
                'active_gateway' => 'none',
                'environment'    => 'sandbox',
                'enable_qris'    => true,
                'enable_va'      => true,
            ]);
        }

        // Mask secret keys for security when sent to frontend
        $safeConfig = $config->toArray();
        if (!empty($safeConfig['midtrans_server_key'])) {
            $safeConfig['midtrans_server_key_masked'] = $this->maskSecret($safeConfig['midtrans_server_key']);
        }
        if (!empty($safeConfig['xendit_secret_key'])) {
            $safeConfig['xendit_secret_key_masked'] = $this->maskSecret($safeConfig['xendit_secret_key']);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $safeConfig,
        ]);
    }

    public function saveGatewayConfig(Request $request)
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'active_gateway'        => 'required|string|in:none,midtrans,xendit',
            'environment'           => 'required|string|in:sandbox,production',
            'enable_qris'           => 'nullable|boolean',
            'enable_va'             => 'nullable|boolean',
            'midtrans_server_key'   => 'nullable|string',
            'midtrans_client_key'   => 'nullable|string',
            'midtrans_merchant_id'  => 'nullable|string|max:100',
            'xendit_secret_key'     => 'nullable|string',
            'xendit_public_key'     => 'nullable|string',
            'xendit_webhook_token'  => 'nullable|string',
            'qris_fee_absorbed_by'  => 'nullable|string|in:merchant,customer',
            'va_fee_absorbed_by'    => 'nullable|string|in:merchant,customer',
            'auto_settlement'       => 'nullable|boolean',
        ]);

        $config = PaymentGatewaySetting::firstOrNew(['business_id' => $businessId]);

        // Only update credentials if not masked/blank
        if (isset($validated['midtrans_server_key']) && !str_contains($validated['midtrans_server_key'], '****')) {
            $config->midtrans_server_key = trim($validated['midtrans_server_key']);
        }
        if (isset($validated['midtrans_client_key'])) {
            $config->midtrans_client_key = trim($validated['midtrans_client_key']);
        }
        if (isset($validated['midtrans_merchant_id'])) {
            $config->midtrans_merchant_id = trim($validated['midtrans_merchant_id']);
        }
        if (isset($validated['xendit_secret_key']) && !str_contains($validated['xendit_secret_key'], '****')) {
            $config->xendit_secret_key = trim($validated['xendit_secret_key']);
        }
        if (isset($validated['xendit_public_key'])) {
            $config->xendit_public_key = trim($validated['xendit_public_key']);
        }
        if (isset($validated['xendit_webhook_token'])) {
            $config->xendit_webhook_token = trim($validated['xendit_webhook_token']);
        }

        $config->active_gateway       = $validated['active_gateway'];
        $config->environment          = $validated['environment'];
        $config->enable_qris          = $validated['enable_qris'] ?? true;
        $config->enable_va            = $validated['enable_va'] ?? true;
        $config->qris_fee_absorbed_by = $validated['qris_fee_absorbed_by'] ?? 'merchant';
        $config->va_fee_absorbed_by   = $validated['va_fee_absorbed_by'] ?? 'customer';
        $config->auto_settlement      = $validated['auto_settlement'] ?? true;
        $config->updated_by           = $request->user()->id;
        $config->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Konfigurasi payment gateway berhasil disimpan',
            'data'    => $config,
        ]);
    }

    public function testGatewayConnection(Request $request)
    {
        $businessId = $request->user()->business_id;
        $gateway    = $request->input('gateway', 'midtrans');
        $env        = $request->input('environment', 'sandbox');

        $config = PaymentGatewaySetting::where('business_id', $businessId)->first();

        // Allow passing raw keys from input for testing before saving
        $midtransServerKey = $request->filled('midtrans_server_key') && !str_contains($request->midtrans_server_key, '****')
            ? trim($request->midtrans_server_key)
            : ($config?->midtrans_server_key ?: '');

        $xenditSecretKey = $request->filled('xendit_secret_key') && !str_contains($request->xendit_secret_key, '****')
            ? trim($request->xendit_secret_key)
            : ($config?->xendit_secret_key ?: '');

        $isSuccess = false;
        $message   = '';

        try {
            if ($gateway === 'midtrans') {
                if (empty($midtransServerKey)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Server Key Midtrans belum diisi. Masukkan Server Key terlebih dahulu.',
                    ], 422);
                }

                $baseUrl = $env === 'production'
                    ? 'https://api.midtrans.com/v2/ping'
                    : 'https://api.sandbox.midtrans.com/v2/ping';

                $authHeader = 'Basic ' . base64_encode($midtransServerKey . ':');

                $response = Http::withHeaders([
                    'Authorization' => $authHeader,
                    'Accept'        => 'application/json',
                ])->timeout(8)->get($baseUrl);

                if ($response->successful() || str_contains($response->body(), 'pong') || $response->status() === 200) {
                    $isSuccess = true;
                    $message = "Koneksi ke Midtrans (" . strtoupper($env) . ") Berhasil! API Key aktif dan siap menerima pembayaran.";
                } else {
                    $errorData = $response->json();
                    $msg = $errorData['status_message'] ?? ($errorData['message'] ?? 'Kode status HTTP ' . $response->status());
                    $message = "Gagal terhubung ke Midtrans: {$msg}. Periksa kembali Server Key dan mode Environment (" . strtoupper($env) . ").";
                }

            } elseif ($gateway === 'xendit') {
                if (empty($xenditSecretKey)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Secret Key Xendit belum diisi. Masukkan Secret Key terlebih dahulu.',
                    ], 422);
                }

                $authHeader = 'Basic ' . base64_encode($xenditSecretKey . ':');

                $response = Http::withHeaders([
                    'Authorization' => $authHeader,
                    'Accept'        => 'application/json',
                ])->timeout(8)->get('https://api.xendit.co/balance');

                if ($response->successful()) {
                    $balanceData = $response->json();
                    $bal = number_format($balanceData['balance'] ?? 0, 0, ',', '.');
                    $isSuccess = true;
                    $message = "Koneksi ke Xendit (" . strtoupper($env) . ") Berhasil! Saldo kas terdeteksi: Rp {$bal}. API Key aktif dan siap digunakan.";
                } else {
                    $errorData = $response->json();
                    $msg = $errorData['message'] ?? ('HTTP ' . $response->status());
                    $message = "Gagal terhubung ke Xendit: {$msg}. Pastikan Secret Key yang dimasukkan benar dan memiliki permission Read/Write.";
                }
            } else {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Pilih gateway Midtrans atau Xendit untuk pengujian.',
                ], 422);
            }

            // Save test status to record
            if ($config) {
                $config->update([
                    'last_tested_at'    => now(),
                    'last_test_status'  => $isSuccess ? 'CONNECTED' : 'FAILED',
                    'last_test_message' => $message,
                ]);
            }

            return response()->json([
                'status'    => $isSuccess ? 'success' : 'error',
                'connected' => $isSuccess,
                'message'   => $message,
                'gateway'   => $gateway,
                'env'       => $env,
            ]);

        } catch (\Throwable $e) {
            Log::error("Gateway connection test failed: " . $e->getMessage());
            return response()->json([
                'status'    => 'error',
                'connected' => false,
                'message'   => "Gagal menghubungi server {$gateway}: " . $e->getMessage(),
            ], 500);
        }
    }

    private function maskSecret(?string $key): string
    {
        if (empty($key) || strlen($key) < 8) return '****';
        return substr($key, 0, 4) . '****' . substr($key, -4);
    }
}
