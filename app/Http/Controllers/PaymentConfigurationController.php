<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\PaymentGatewaySetting;
use App\Models\Transaction;
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
        $user = $request->user();
        $isPlatformAdmin = in_array($user->role, ['superadmin_platform', 'superadmin', 'owner_website']) || (bool)($user->is_superadmin_platform ?? false);

        $query = BankAccount::with(['outlet', 'business'])
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'desc');

        if ($isPlatformAdmin && ($request->boolean('all_tenants') || $request->filled('business_id'))) {
            if ($request->filled('business_id') && $request->business_id !== 'ALL' && $request->business_id !== 'all') {
                $query->where('business_id', $request->business_id);
            }
        } else {
            $query->where('business_id', $user->business_id);
        }

        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where(function ($q) use ($request) {
                $q->where('outlet_id', $request->outlet_id)
                  ->orWhereNull('outlet_id');
            });
        }

        if ($request->filled('account_type') && in_array(strtoupper($request->account_type), ['BANK', 'EWALLET'])) {
            $query->where('account_type', strtoupper($request->account_type));
        }

        return response()->json([
            'status' => 'success',
            'data'   => $query->get(),
        ]);
    }

    public function storeBankAccount(Request $request)
    {
        $user = $request->user();
        $isPlatformAdmin = in_array($user->role, ['superadmin_platform', 'superadmin', 'owner_website']) || (bool)($user->is_superadmin_platform ?? false);

        $validated = $request->validate([
            'business_id'         => 'nullable|exists:businesses,id',
            'account_type'        => 'nullable|string|in:BANK,EWALLET',
            'bank_name'           => 'required|string|max:100',
            'bank_code'           => 'nullable|string|max:20',
            'account_number'      => 'required|string|max:50',
            'account_holder'      => 'required|string|max:255',
            'branch'              => 'nullable|string|max:255',
            'outlet_id'           => 'nullable|exists:outlets,id',
            'qr_image_url'        => 'nullable|string',
            'is_primary'          => 'nullable|boolean',
            'is_active'           => 'nullable|boolean',
            'verification_status' => 'nullable|string|in:VERIFIED,PENDING,REJECTED',
            'payout_schedule'     => 'nullable|string|in:INSTANT,DAILY,MANUAL',
            'notes'               => 'nullable|string|max:500',
        ]);

        $targetBusinessId = ($isPlatformAdmin && !empty($validated['business_id']))
            ? $validated['business_id']
            : $user->business_id;

        $validated['business_id']         = $targetBusinessId;
        $validated['account_type']        = $validated['account_type'] ?? 'BANK';
        $validated['verification_status'] = $validated['verification_status'] ?? 'VERIFIED';
        $validated['payout_schedule']     = $validated['payout_schedule'] ?? 'DAILY';
        $validated['created_by']          = $user->id;

        // If this is set as primary, unset other accounts in the same business & account_type
        if (!empty($validated['is_primary'])) {
            BankAccount::where('business_id', $targetBusinessId)->update(['is_primary' => false]);
        } else {
            // If it's the very first account, make it primary automatically
            $existingCount = BankAccount::where('business_id', $targetBusinessId)->count();
            if ($existingCount === 0) {
                $validated['is_primary'] = true;
            }
        }

        $account = BankAccount::create($validated);
        $account->load(['outlet', 'business']);

        return response()->json([
            'status'  => 'success',
            'message' => ($account->account_type === 'EWALLET' ? 'E-Wallet' : 'Nomor rekening') . ' berhasil didaftarkan',
            'data'    => $account,
        ], 201);
    }

    public function updateBankAccount(Request $request, $id)
    {
        $user = $request->user();
        $isPlatformAdmin = in_array($user->role, ['superadmin_platform', 'superadmin', 'owner_website']) || (bool)($user->is_superadmin_platform ?? false);

        $query = BankAccount::query();
        if (!$isPlatformAdmin) {
            $query->where('business_id', $user->business_id);
        }
        $account = $query->findOrFail($id);

        $validated = $request->validate([
            'account_type'        => 'sometimes|string|in:BANK,EWALLET',
            'bank_name'           => 'sometimes|required|string|max:100',
            'bank_code'           => 'nullable|string|max:20',
            'account_number'      => 'sometimes|required|string|max:50',
            'account_holder'      => 'sometimes|required|string|max:255',
            'branch'              => 'nullable|string|max:255',
            'outlet_id'           => 'nullable|exists:outlets,id',
            'qr_image_url'        => 'nullable|string',
            'is_primary'          => 'nullable|boolean',
            'is_active'           => 'nullable|boolean',
            'verification_status' => 'nullable|string|in:VERIFIED,PENDING,REJECTED',
            'payout_schedule'     => 'nullable|string|in:INSTANT,DAILY,MANUAL',
            'notes'               => 'nullable|string|max:500',
        ]);

        $validated['updated_by'] = $user->id;

        if (!empty($validated['is_primary']) && !$account->is_primary) {
            BankAccount::where('business_id', $account->business_id)->update(['is_primary' => false]);
        }

        $account->update($validated);
        $account->load(['outlet', 'business']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Data rekening / e-wallet berhasil diperbarui',
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

    // ==========================================
    // 3. MIDTRANS TRANSACTION CHARGE & STATUS
    // ==========================================

    public function chargeMidtransQris(Request $request)
    {
        $businessId = $request->user()->business_id;
        $config = PaymentGatewaySetting::where('business_id', $businessId)->first();

        if (!$config || empty($config->midtrans_server_key)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Kredensial Midtrans belum diatur. Silakan masukkan Server Key di menu Master Bisnis > Rekening & Payment Gateway.',
            ], 422);
        }

        $grossAmount = (int) round($request->input('gross_amount', 0));
        if ($grossAmount < 1000) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Nominal pembayaran QRIS minimal Rp 1.000.',
            ], 422);
        }

        $orderId = $request->input('order_id') ?: ('MOVA-' . date('YmdHis') . '-' . rand(100, 999));
        $customerName = $request->input('customer_name') ?: 'Pelanggan POS';

        $baseUrl = ($config->environment === 'production')
            ? 'https://api.midtrans.com/v2/charge'
            : 'https://api.sandbox.midtrans.com/v2/charge';

        $payload = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $grossAmount,
            ],
            'customer_details' => [
                'first_name' => $customerName,
            ],
            'qris' => [
                'acquirer' => 'gopay',
            ],
        ];

        try {
            $response = Http::withBasicAuth($config->midtrans_server_key, '')
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(12)
                ->post($baseUrl, $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['status_code']) && in_array($data['status_code'], ['200', '201'])) {
                // Find QR Code URL
                $qrImageUrl = null;
                if (!empty($data['actions'])) {
                    foreach ($data['actions'] as $act) {
                        if (($act['name'] ?? '') === 'generate-qr-code') {
                            $qrImageUrl = $act['url'] ?? null;
                            break;
                        }
                    }
                }

                return response()->json([
                    'status'        => 'success',
                    'order_id'      => $orderId,
                    'gross_amount'  => $grossAmount,
                    'qr_string'     => $data['qr_string'] ?? null,
                    'qr_image_url'  => $qrImageUrl,
                    'expiry_time'   => $data['expiry_time'] ?? null,
                    'environment'   => $config->environment,
                    'midtrans_data' => $data,
                ]);
            }

            $errMsg = $data['status_message'] ?? ($data['message'] ?? 'Gagal menghubungi Midtrans');
            return response()->json([
                'status'  => 'error',
                'message' => "Midtrans error: {$errMsg}",
                'details' => $data,
            ], 400);

        } catch (\Throwable $e) {
            Log::error("Midtrans QRIS Charge error: " . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan sistem saat memproses QRIS: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function checkMidtransStatus(Request $request, string $orderId)
    {
        $businessId = $request->user()->business_id;
        $config = PaymentGatewaySetting::where('business_id', $businessId)->first();

        if (!$config || empty($config->midtrans_server_key)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Kredensial Midtrans belum diatur.',
            ], 422);
        }

        $baseUrl = ($config->environment === 'production')
            ? "https://api.midtrans.com/v2/{$orderId}/status"
            : "https://api.sandbox.midtrans.com/v2/{$orderId}/status";

        try {
            $response = Http::withBasicAuth($config->midtrans_server_key, '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(8)
                ->get($baseUrl);

            $data = $response->json();
            $transactionStatus = $data['transaction_status'] ?? 'unknown';
            $isPaid = in_array($transactionStatus, ['settlement', 'capture']);

            if ($isPaid) {
                // Automatically update transaction status in database if records exist
                Transaction::where('business_id', $businessId)
                    ->where('order_number', $orderId)
                    ->where('status', '!=', 'PAID')
                    ->update([
                        'status'         => 'PAID',
                        'payment_method' => 'QRIS',
                    ]);
            }

            return response()->json([
                'status'             => 'success',
                'order_id'           => $orderId,
                'is_paid'            => $isPaid,
                'transaction_status' => $transactionStatus,
                'gross_amount'       => $data['gross_amount'] ?? null,
                'payment_type'       => $data['payment_type'] ?? 'qris',
                'settlement_time'    => $data['settlement_time'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error("Midtrans status check error: " . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengecek status pembayaran: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function handleMidtransWebhook(Request $request)
    {
        $payload = $request->all();
        $orderId = $payload['order_id'] ?? null;
        $statusCode = $payload['status_code'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;
        $signatureKey = $payload['signature_key'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;

        if (!$orderId || !$statusCode || !$grossAmount || !$signatureKey) {
            return response()->json(['message' => 'Invalid webhook payload'], 400);
        }

        // Find transaction to know which business owns this order
        $trx = Transaction::where('order_number', $orderId)->first();
        $businessId = $trx?->business_id;

        $config = null;
        if ($businessId) {
            $config = PaymentGatewaySetting::where('business_id', $businessId)->first();
        } else {
            $config = PaymentGatewaySetting::whereNotNull('midtrans_server_key')->first();
        }

        if (!$config || empty($config->midtrans_server_key)) {
            Log::warning("Midtrans Webhook: Server key not found for order {$orderId}");
            return response()->json(['message' => 'Configuration not found'], 404);
        }

        // Verify SHA512 Signature: sha512(order_id + status_code + gross_amount + ServerKey)
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $config->midtrans_server_key);
        if ($signatureKey !== $expectedSignature) {
            Log::warning("Midtrans Webhook: Invalid signature for order {$orderId}");
            return response()->json(['message' => 'Invalid signature key'], 403);
        }

        if (in_array($transactionStatus, ['settlement', 'capture'])) {
            Transaction::where('order_number', $orderId)
                ->update([
                    'status'         => 'PAID',
                    'payment_method' => 'QRIS',
                ]);
            Log::info("Midtrans Webhook: Order {$orderId} successfully marked as PAID");
        } elseif (in_array($transactionStatus, ['cancel', 'expire', 'deny'])) {
            Transaction::where('order_number', $orderId)
                ->where('status', 'HOLD')
                ->update(['status' => 'CANCELLED']);
            Log::info("Midtrans Webhook: Order {$orderId} marked as {$transactionStatus}");
        }

        return response()->json(['status' => 'success', 'message' => 'Webhook processed successfully']);
    }
}
