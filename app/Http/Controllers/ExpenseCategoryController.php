<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = ExpenseCategory::orderBy('name', 'asc')->get();
        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'  => 'required|string|max:50',
            'name'  => 'required|string|max:100',
            'color' => 'nullable|string|max:20',
            'icon'  => 'nullable|string|max:50',
        ]);

        $data['business_id'] = $request->user()?->business_id;
        $data['code'] = strtoupper(trim($data['code']));

        $category = ExpenseCategory::create($data);
        return response()->json($category, 201);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $data = $request->validate([
            'name'  => 'sometimes|string|max:100',
            'color' => 'nullable|string|max:20',
            'icon'  => 'nullable|string|max:50',
        ]);

        $expenseCategory->update($data);
        return response()->json($expenseCategory);
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        $expenseCategory->delete();
        return response()->json(['message' => 'Pos biaya beban berhasil dihapus']);
    }
}
