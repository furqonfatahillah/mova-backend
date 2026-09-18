<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $units = Unit::orderBy('name', 'asc')->get();
        return response()->json($units);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:50',
            'symbol'       => 'required|string|max:20',
            'is_base_unit' => 'nullable|boolean',
        ]);

        $data['business_id'] = $request->user()?->business_id;
        $data['symbol'] = strtolower(trim($data['symbol']));

        $unit = Unit::create($data);
        return response()->json($unit, 201);
    }

    public function show(Unit $unit)
    {
        return response()->json($unit);
    }

    public function update(Request $request, Unit $unit)
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|max:50',
            'symbol'       => 'sometimes|string|max:20',
            'is_base_unit' => 'nullable|boolean',
        ]);

        if (!empty($data['symbol'])) {
            $data['symbol'] = strtolower(trim($data['symbol']));
        }

        $unit->update($data);
        return response()->json($unit);
    }

    public function destroy(Unit $unit)
    {
        $unit->delete();
        return response()->json(['message' => 'Satuan unit berhasil dihapus']);
    }
}
