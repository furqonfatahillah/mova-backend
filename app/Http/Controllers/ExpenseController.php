<?php

namespace App\Http\Controllers;

use App\Models\OperatingExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    /**
     * Display a listing of operating expenses with filters.
     */
    public function index(Request $request)
    {
        $query = OperatingExpense::with(['outlet', 'user'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        if ($request->filled('category') && $request->category !== 'ALL' && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->filled('payment_method') && $request->payment_method !== 'ALL') {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('expense_no', 'like', $search)
                  ->orWhere('notes', 'like', $search);
            });
        }

        $limit = $request->input('limit', 500);
        return response()->json($query->limit($limit)->get());
    }

    /**
     * Get aggregate analytics & categories for OPEX.
     */
    public function summary(Request $request)
    {
        $query = OperatingExpense::query();

        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        $expenses = $query->get();

        $totalAmount = (float)$expenses->sum('amount');
        $totalCount  = $expenses->count();

        // Breakdown by category
        $categoryConfig = OperatingExpense::categories();
        $byCategory = [];
        foreach ($categoryConfig as $catKey => $catLabel) {
            $catItems = $expenses->where('category', $catKey);
            $catSum = (float)$catItems->sum('amount');
            $catCount = $catItems->count();
            if ($catSum > 0 || $catCount > 0) {
                $byCategory[] = [
                    'category'   => $catKey,
                    'label'      => $catLabel,
                    'total'      => $catSum,
                    'count'      => $catCount,
                    'percentage' => $totalAmount > 0 ? round(($catSum / $totalAmount) * 100, 1) : 0,
                ];
            }
        }

        // Sort descending by total
        usort($byCategory, fn($a, $b) => $b['total'] <=> $a['total']);

        // Breakdown by payment method
        $methodConfig = OperatingExpense::paymentMethods();
        $byPaymentMethod = [];
        foreach ($methodConfig as $mKey => $mLabel) {
            $mItems = $expenses->where('payment_method', $mKey);
            $mSum = (float)$mItems->sum('amount');
            if ($mSum > 0) {
                $byPaymentMethod[] = [
                    'method'     => $mKey,
                    'label'      => $mLabel,
                    'total'      => $mSum,
                    'count'      => $mItems->count(),
                    'percentage' => $totalAmount > 0 ? round(($mSum / $totalAmount) * 100, 1) : 0,
                ];
            }
        }

        return response()->json([
            'total_opex'        => $totalAmount,
            'total_entries'     => $totalCount,
            'by_category'       => $byCategory,
            'by_payment_method' => $byPaymentMethod,
            'all_categories'    => $categoryConfig,
            'all_methods'       => $methodConfig,
        ]);
    }

    /**
     * Store a new operating expense.
     */
    public function store(Request $request)
    {
        $validCategories = array_keys(OperatingExpense::categories());
        $validMethods = array_keys(OperatingExpense::paymentMethods());

        $data = $request->validate([
            'date'           => 'required|date',
            'category'       => 'required|string|in:' . implode(',', $validCategories),
            'name'           => 'required|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|in:' . implode(',', $validMethods),
            'outlet_id'      => 'nullable|exists:outlets,id',
            'notes'          => 'nullable|string|max:1000',
            'receipt_img'    => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $businessId = $user->business_id ?? null;
        $paymentMethod = $data['payment_method'] ?? 'CASH';

        $expenseNo = OperatingExpense::generateExpenseNo($businessId, $data['date']);

        $expense = OperatingExpense::create([
            'business_id'    => $businessId,
            'outlet_id'      => $data['outlet_id'] ?? null,
            'expense_no'     => $expenseNo,
            'date'           => $data['date'],
            'category'       => $data['category'],
            'name'           => $data['name'],
            'amount'         => (float)$data['amount'],
            'payment_method' => $paymentMethod,
            'notes'          => $data['notes'] ?? null,
            'receipt_img'    => $data['receipt_img'] ?? null,
            'user_id'        => $user->id,
            'created_by'     => $user->id,
        ]);

        return response()->json([
            'message' => 'Biaya operasional berhasil dicatat',
            'data'    => $expense->fresh(['outlet', 'user']),
        ], 201);
    }

    /**
     * Display a specific expense.
     */
    public function show($id)
    {
        $expense = OperatingExpense::with(['outlet', 'user'])->findOrFail($id);
        return response()->json($expense);
    }

    /**
     * Update an existing expense.
     */
    public function update(Request $request, $id)
    {
        $expense = OperatingExpense::findOrFail($id);

        $validCategories = array_keys(OperatingExpense::categories());
        $validMethods = array_keys(OperatingExpense::paymentMethods());

        $data = $request->validate([
            'date'           => 'sometimes|required|date',
            'category'       => 'sometimes|required|string|in:' . implode(',', $validCategories),
            'name'           => 'sometimes|required|string|max:255',
            'amount'         => 'sometimes|required|numeric|min:0.01',
            'payment_method' => 'nullable|string|in:' . implode(',', $validMethods),
            'outlet_id'      => 'nullable|exists:outlets,id',
            'notes'          => 'nullable|string|max:1000',
            'receipt_img'    => 'nullable|string|max:255',
        ]);

        $expense->update([
            'date'           => $data['date'] ?? $expense->date,
            'category'       => $data['category'] ?? $expense->category,
            'name'           => $data['name'] ?? $expense->name,
            'amount'         => isset($data['amount']) ? (float)$data['amount'] : $expense->amount,
            'payment_method' => $data['payment_method'] ?? $expense->payment_method,
            'outlet_id'      => array_key_exists('outlet_id', $data) ? $data['outlet_id'] : $expense->outlet_id,
            'notes'          => array_key_exists('notes', $data) ? $data['notes'] : $expense->notes,
            'receipt_img'    => array_key_exists('receipt_img', $data) ? $data['receipt_img'] : $expense->receipt_img,
            'updated_by'     => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Data biaya operasional berhasil diperbarui',
            'data'    => $expense->fresh(['outlet', 'user']),
        ]);
    }

    /**
     * Delete an expense.
     */
    public function destroy($id)
    {
        $expense = OperatingExpense::findOrFail($id);
        $expense->delete();

        return response()->json([
            'message' => 'Catatan biaya operasional berhasil dihapus',
        ]);
    }
}
