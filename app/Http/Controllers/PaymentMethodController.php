<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentMethod::orderBy('sort_order', 'asc')->orderBy('name', 'asc');

        if ($request->boolean('active_only', true)) {
            $query->where('active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'       => 'required|string|max:50',
            'name'       => 'required|string|max:100',
            'type'       => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,OTHER',
            'active'     => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $data['business_id'] = $request->user()?->business_id;
        $data['code'] = strtoupper(trim($data['code']));

        $paymentMethod = PaymentMethod::create($data);
        return response()->json($paymentMethod, 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $data = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'type'       => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,OTHER',
            'active'     => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $paymentMethod->update($data);
        return response()->json($paymentMethod);
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        $paymentMethod->delete();
        return response()->json(['message' => 'Metode pembayaran berhasil dihapus']);
    }
}
