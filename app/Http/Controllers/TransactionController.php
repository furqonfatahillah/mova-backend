<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Transaction;
use App\Models\StockMovement;
use App\Models\ModifierOption;
use App\Models\TransactionModifier;
use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Helper to resolve modifier options from item input.
     */
    private function resolveModifierOptions(array $itemInput)
    {
        $optionIds = [];
        if (!empty($itemInput['modifier_option_ids']) && is_array($itemInput['modifier_option_ids'])) {
            $optionIds = array_merge($optionIds, $itemInput['modifier_option_ids']);
        }
        if (!empty($itemInput['modifiers']) && is_array($itemInput['modifiers'])) {
            foreach ($itemInput['modifiers'] as $m) {
                if (is_numeric($m)) {
                    $optionIds[] = (int)$m;
                } elseif (is_array($m)) {
                    if (!empty($m['id'])) {
                        $optionIds[] = (int)$m['id'];
                    } elseif (!empty($m['modifier_option_id'])) {
                        $optionIds[] = (int)$m['modifier_option_id'];
                    }
                }
            }
        }
        $optionIds = array_unique(array_filter($optionIds));
        if (empty($optionIds)) {
            return collect();
        }

        return ModifierOption::with(['group', 'ingredient'])->whereIn('id', $optionIds)->get();
    }

    /**
     * Helper to resolve discount calculation & metadata for an order.
     */
    private function resolveDiscountDetails(array $data, float $grossSubtotal, ?int $outletId, string $date, int $businessId): array
    {
        $discount = null;
        if (!empty($data['discount_id'])) {
            $discount = Discount::where('business_id', $businessId)->find($data['discount_id']);
        } elseif (!empty($data['discount_code'])) {
            $code = strtoupper(trim($data['discount_code']));
            $discount = Discount::where('business_id', $businessId)->where('code', $code)->first();
        }

        if ($discount) {
            $check = $discount->validateForOrder($grossSubtotal, $outletId, $date);
            if ($check['valid']) {
                $amount = $discount->calculateDiscountAmount($grossSubtotal);
                return [
                    'model'           => $discount,
                    'discount_id'     => $discount->id,
                    'discount_name'   => $discount->name,
                    'discount_type'   => $discount->type,
                    'discount_rate'   => (float)$discount->value,
                    'discount_amount' => $amount,
                ];
            }
        }

        // Custom / manual cashier discount
        if (!empty($data['discount_amount']) && (float)$data['discount_amount'] > 0) {
            $amt = min((float)$data['discount_amount'], $grossSubtotal);
            return [
                'model'           => null,
                'discount_id'     => null,
                'discount_name'   => !empty($data['discount_name']) ? trim($data['discount_name']) : 'Diskon Kasir',
                'discount_type'   => !empty($data['discount_type']) ? $data['discount_type'] : 'CUSTOM',
                'discount_rate'   => (float)($data['discount_rate'] ?? $amt),
                'discount_amount' => $amt,
            ];
        }

        return [
            'model'           => null,
            'discount_id'     => null,
            'discount_name'   => null,
            'discount_type'   => null,
            'discount_rate'   => 0.0,
            'discount_amount' => 0.0,
        ];
    }

    /**
     * Generate the next unique order number for today: e.g. TRX-20260906-0001
     * Only inspects parent orders to prevent collisions with sub-order splits.
     */
    private function generateOrderNumber(string $date): string
    {
        $datePrefix = date('Ymd', strtotime($date));
        $existing = Transaction::where('order_number', 'like', "TRX-{$datePrefix}-%")
            ->orWhere('parent_order_number', 'like', "TRX-{$datePrefix}-%")
            ->get(['order_number', 'parent_order_number']);

        $maxSeq = 0;
        foreach ($existing as $t) {
            foreach ([$t->order_number, $t->parent_order_number] as $str) {
                if ($str && preg_match("/TRX-{$datePrefix}-(\d{4})/", $str, $matches)) {
                    $seq = (int)$matches[1];
                    if ($seq > $maxSeq) {
                        $maxSeq = $seq;
                    }
                }
            }
        }

        $nextSeq = $maxSeq + 1;
        return sprintf("TRX-%s-%04d", $datePrefix, $nextSeq);
    }

    public function index(Request $request)
    {
        $query = Transaction::with(['menu', 'user', 'outlet', 'shift', 'creator', 'updater', 'modifiers.ingredient', 'discount'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($request->from)         $query->where('date', '>=', $request->from);
        if ($request->to)           $query->where('date', '<=', $request->to);
        if ($request->menu_id)      $query->where('menu_id', $request->menu_id);
        if ($request->order_number) $query->where('order_number', $request->order_number);
        if ($request->shift_id)     $query->where('shift_id', $request->shift_id);
        if ($request->outlet_id)    $query->where('outlet_id', $request->outlet_id);
        if ($request->status)       $query->where('status', $request->status);

        return response()->json($query->limit(200)->get());
    }

    public function store(Request $request)
    {
        $isMultiItem = $request->has('items') && is_array($request->input('items'));

        // ==========================================
        // 1. MULTI-ITEM ORDER (KERANJANG BELANJA)
        // ==========================================
        if ($isMultiItem) {
            $data = $request->validate([
                'items'                        => 'required|array|min:1',
                'items.*.menu_id'              => 'required|exists:menus,id',
                'items.*.qty'                  => 'required|integer|min:1',
                'items.*.notes'                => 'nullable|string|max:255',
                'items.*.modifier_option_ids'  => 'nullable|array',
                'items.*.modifier_option_ids.*'=> 'exists:modifier_options,id',
                'items.*.modifiers'            => 'nullable|array',
                'status'                       => 'nullable|string|in:PAID,HOLD',
                'date'                         => 'required|date',
                'shift_id'                     => 'nullable|exists:shifts,id',
                'outlet_id'                    => 'nullable|exists:outlets,id',
                'customer_name'                => 'nullable|string|max:100',
                'order_type'                   => 'nullable|string|in:DINE_IN,TAKEAWAY,DELIVERY',
                'table_number'                 => 'nullable|string|max:50',
                'payment_method'               => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT',
                'amount_paid'                  => 'nullable|numeric|min:0',
                'change_amount'                => 'nullable|numeric|min:0',
                'notes'                        => 'nullable|string|max:500',
                'discount_id'                  => 'nullable|exists:discounts,id',
                'discount_code'                => 'nullable|string|max:50',
                'discount_amount'              => 'nullable|numeric|min:0',
                'discount_name'                => 'nullable|string|max:100',
                'discount_type'                => 'nullable|string|max:30',
                'discount_rate'                => 'nullable|numeric|min:0',
            ]);

            $orderStatus = $data['status'] ?? 'PAID';

            // Resolve Active Shift
            $shiftId = $data['shift_id'] ?? null;
            if (!$shiftId) {
                $activeShift = \App\Models\Shift::where('status', 'OPEN')->orderByDesc('opened_at')->first();
                $shiftId = $activeShift?->id;
            }

            // Resolve Outlet ID
            $outletId = $data['outlet_id'] ?? $request->user()->outlet_id ?? null;

            // Generate Unique Order Number: e.g. TRX-20260906-0001
            $orderNumber = $this->generateOrderNumber($data['date']);

            // Pre-validate recipes & resolve modifiers for all items
            $preparedItems = [];
            $orderTotal = 0;

            foreach ($data['items'] as $it) {
                $menu = Menu::findOrFail($it['menu_id']);
                $recipe = $menu->activeRecipe($data['date']);

                $options = $this->resolveModifierOptions($it);
                $modifierUnitPrice = (float)$options->sum('price');
                $itemUnitPrice = (float)$menu->price + $modifierUnitPrice;
                $itemTotal = $itemUnitPrice * (int)$it['qty'];
                $orderTotal += $itemTotal;

                $preparedItems[] = [
                    'menu'           => $menu,
                    'recipe'         => $recipe,
                    'qty'            => (int)$it['qty'],
                    'item_notes'     => $it['notes'] ?? null,
                    'item_total'     => $itemTotal,
                    'options'        => $options,
                    'modifier_extra' => $modifierUnitPrice,
                ];
            }

            // Calculate Gross Subtotal & Resolve Discount
            $orderGrossSubtotal = $orderTotal;
            $discInfo = $this->resolveDiscountDetails(
                $data,
                $orderGrossSubtotal,
                $outletId,
                $data['date'],
                (int)($request->user()->business_id ?? 1)
            );
            $totalDiscountAmount = $discInfo['discount_amount'];
            $orderNetTotal = max(0, $orderGrossSubtotal - $totalDiscountAmount);

            // Distribute discount proportionally across prepared items
            $accumulatedDisc = 0;
            $itemsCount = count($preparedItems);
            foreach ($preparedItems as $idx => &$prep) {
                if ($orderGrossSubtotal > 0 && $totalDiscountAmount > 0) {
                    if ($idx === $itemsCount - 1) {
                        $itemDisc = round($totalDiscountAmount - $accumulatedDisc, 2);
                    } else {
                        $itemDisc = round($totalDiscountAmount * ($prep['item_total'] / $orderGrossSubtotal), 2);
                        $accumulatedDisc += $itemDisc;
                    }
                } else {
                    $itemDisc = 0.0;
                }
                $prep['subtotal'] = $prep['item_total'];
                $prep['discount_amount'] = $itemDisc;
                $prep['net_total'] = max(0, $prep['item_total'] - $itemDisc);
            }
            unset($prep);

            $createdTransactions = DB::transaction(function () use (
                $preparedItems, $data, $orderNumber, $orderStatus, $shiftId, $outletId, $request, $discInfo
            ) {
                $results = [];

                foreach ($preparedItems as $prep) {
                    $menu   = $prep['menu'];
                    $recipe = $prep['recipe'];
                    $qty    = $prep['qty'];

                    $trx = Transaction::create([
                        'order_number'    => $orderNumber,
                        'status'          => $orderStatus,
                        'date'            => $data['date'],
                        'menu_id'         => $menu->id,
                        'qty'             => $qty,
                        'recipe_version'  => $recipe?->version ?? null,
                        'total_price'     => $prep['net_total'],
                        'subtotal'        => $prep['subtotal'],
                        'discount_id'     => $discInfo['discount_id'],
                        'discount_amount' => $prep['discount_amount'],
                        'discount_name'   => $discInfo['discount_name'],
                        'discount_type'   => $discInfo['discount_type'],
                        'discount_rate'   => $discInfo['discount_rate'],
                        'amount_paid'     => $orderStatus === 'HOLD' ? 0 : ($data['amount_paid'] ?? null),
                        'change_amount'   => $orderStatus === 'HOLD' ? 0 : ($data['change_amount'] ?? null),
                        'customer_name'   => $data['customer_name'] ?? null,
                        'order_type'      => $data['order_type'] ?? 'DINE_IN',
                        'table_number'    => $data['table_number'] ?? null,
                        'payment_method'  => $data['payment_method'] ?? 'CASH',
                        'notes'           => $prep['item_notes'] ?: ($data['notes'] ?? null),
                        'user_id'         => $request->user()->id,
                        'created_by'      => $request->user()->id,
                        'shift_id'        => $shiftId,
                        'outlet_id'       => $outletId,
                    ]);

                    // Save selected modifiers snapshot
                    foreach ($prep['options'] as $opt) {
                        TransactionModifier::create([
                            'transaction_id'     => $trx->id,
                            'modifier_option_id' => $opt->id,
                            'group_name'         => $opt->group?->name ?? 'Varian',
                            'name'               => $opt->name,
                            'price'              => (float)$opt->price,
                            'ingredient_id'      => $opt->ingredient_id,
                            'qty'                => (float)($opt->qty ?? 0),
                            'unit'               => $opt->unit,
                            'total_price'        => (float)($opt->price * $qty),
                        ]);

                        // Stock deduction for modifier ingredient if NOT in shift and PAID
                        if ($orderStatus === 'PAID' && !$shiftId && $opt->ingredient_id && $opt->qty > 0) {
                            StockMovement::create([
                                'date'           => $data['date'],
                                'ingredient_id'  => $opt->ingredient_id,
                                'type'           => 'SALE_USAGE',
                                'qty'            => (float)($opt->qty * $qty),
                                'note'           => "{$orderNumber} – Modifier {$opt->name} ({$menu->name} x{$qty})",
                                'transaction_id' => $trx->id,
                                'outlet_id'      => $outletId,
                                'user_id'        => $request->user()->id,
                                'created_by'     => $request->user()->id,
                            ]);
                        }
                    }

                    // Deduct stock for DIRECT retail items
                    if ($orderStatus === 'PAID') {
                        if ($menu->item_type === 'DIRECT' && $menu->track_stock) {
                            $menu->decrement('stock', $qty);
                            if ($outletId) {
                                $om = \App\Models\OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                                if ($om) {
                                    $om->decrement('stock', $qty);
                                }
                            }
                        }
                    }

                    // If NOT in a shift and PAID, deduct recipe ingredients immediately via StockMovement
                    // (If in shift, ingredients are aggregated at shift closing; if HOLD, not deducted yet)
                    if ($orderStatus === 'PAID' && !$shiftId && $recipe) {
                        foreach ($recipe->items as $item) {
                            StockMovement::create([
                                'date'           => $data['date'],
                                'ingredient_id'  => $item->ingredient_id,
                                'type'           => 'SALE_USAGE',
                                'qty'            => $item->qty * $qty,
                                'note'           => "{$orderNumber} – {$menu->name} (x{$qty})",
                                'transaction_id' => $trx->id,
                                'outlet_id'      => $outletId,
                                'user_id'        => $request->user()->id,
                                'created_by'     => $request->user()->id,
                            ]);
                        }
                    }

                    $trx->load(['menu', 'modifiers.ingredient', 'discount']);
                    $results[] = $trx;
                }

                // Increment discount usage if PAID and valid discount model
                if ($orderStatus === 'PAID' && $discInfo['model']) {
                    $discInfo['model']->increment('used_count');
                }

                return $results;
            });

            return response()->json([
                'order_number'    => $orderNumber,
                'status'          => $orderStatus,
                'date'            => $data['date'],
                'customer_name'   => $data['customer_name'] ?? null,
                'order_type'      => $data['order_type'] ?? 'DINE_IN',
                'table_number'    => $data['table_number'] ?? null,
                'payment_method'  => $data['payment_method'] ?? 'CASH',
                'subtotal'        => $orderGrossSubtotal,
                'discount_id'     => $discInfo['discount_id'],
                'discount_name'   => $discInfo['discount_name'],
                'discount_type'   => $discInfo['discount_type'],
                'discount_rate'   => $discInfo['discount_rate'],
                'discount_amount' => $totalDiscountAmount,
                'total_price'     => $orderNetTotal,
                'amount_paid'     => (float)($data['amount_paid'] ?? ($orderStatus === 'HOLD' ? 0 : $orderNetTotal)),
                'change_amount'   => (float)($data['change_amount'] ?? 0),
                'items_count'     => count($createdTransactions),
                'items'           => array_map(fn($t) => [
                    'id'              => $t->id,
                    'menu_id'         => $t->menu_id,
                    'menu_name'       => $t->menu?->name,
                    'price'           => (float)($t->menu?->price ?? 0),
                    'qty'             => $t->qty,
                    'subtotal'        => (float)($t->subtotal ?? $t->total_price),
                    'discount_amount' => (float)($t->discount_amount ?? 0),
                    'total_price'     => $t->total_price,
                    'notes'           => $t->notes,
                    'modifiers'       => $t->modifiers->map(fn($m) => [
                        'id'          => $m->id,
                        'name'        => $m->name,
                        'group_name'  => $m->group_name,
                        'price'       => (float)$m->price,
                        'qty'         => (float)$m->qty,
                        'unit'        => $m->unit,
                        'total_price' => (float)$m->total_price,
                    ]),
                ], $createdTransactions),
                'cashier'        => [
                    'id'   => $request->user()->id,
                    'name' => $request->user()->name,
                ],
                'shift_id'       => $shiftId,
                'created_at'     => now()->toDateTimeString(),
            ], 201);
        }

        // ==========================================
        // 2. SINGLE-ITEM ORDER (BACKWARD COMPATIBLE)
        // ==========================================
        $data = $request->validate([
            'menu_id'                     => 'required|exists:menus,id',
            'qty'                         => 'required|integer|min:1',
            'status'                      => 'nullable|string|in:PAID,HOLD',
            'date'                        => 'required|date',
            'shift_id'                    => 'nullable|exists:shifts,id',
            'outlet_id'                   => 'nullable|exists:outlets,id',
            'customer_name'               => 'nullable|string|max:100',
            'order_type'                  => 'nullable|string|in:DINE_IN,TAKEAWAY,DELIVERY',
            'table_number'                => 'nullable|string|max:50',
            'payment_method'              => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT',
            'amount_paid'                 => 'nullable|numeric|min:0',
            'change_amount'               => 'nullable|numeric|min:0',
            'notes'                       => 'nullable|string|max:500',
            'modifier_option_ids'         => 'nullable|array',
            'modifier_option_ids.*'       => 'exists:modifier_options,id',
            'modifiers'                   => 'nullable|array',
        ]);

        $orderStatus = $data['status'] ?? 'PAID';
        $menu   = Menu::findOrFail($data['menu_id']);
        $recipe = $menu->activeRecipe($data['date']);

        // Find active shift if not explicitly provided
        $shiftId = $data['shift_id'] ?? null;
        if (!$shiftId) {
            $activeShift = \App\Models\Shift::where('status', 'OPEN')->orderByDesc('opened_at')->first();
            $shiftId = $activeShift?->id;
        }

        $outletId = $data['outlet_id'] ?? $request->user()->outlet_id ?? null;

        // Generate Unique Order Number: e.g. TRX-20260906-0001
        $orderNumber = $this->generateOrderNumber($data['date']);

        $options = $this->resolveModifierOptions($data);
        $modifierUnitPrice = (float)$options->sum('price');
        $itemUnitPrice = (float)$menu->price + $modifierUnitPrice;
        $totalItemPrice = $itemUnitPrice * (int)$data['qty'];

        $trx = DB::transaction(function () use ($data, $menu, $recipe, $options, $totalItemPrice, $request, $shiftId, $outletId, $orderNumber, $orderStatus) {
            $qty = (int)$data['qty'];
            $trx = Transaction::create([
                'order_number'   => $orderNumber,
                'status'         => $orderStatus,
                'date'           => $data['date'],
                'menu_id'        => $menu->id,
                'qty'            => $qty,
                'recipe_version' => $recipe?->version ?? null,
                'total_price'    => $totalItemPrice,
                'amount_paid'    => $orderStatus === 'HOLD' ? 0 : ($data['amount_paid'] ?? null),
                'change_amount'  => $orderStatus === 'HOLD' ? 0 : ($data['change_amount'] ?? null),
                'customer_name'  => $data['customer_name'] ?? null,
                'order_type'     => $data['order_type'] ?? 'DINE_IN',
                'table_number'   => $data['table_number'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'CASH',
                'notes'          => $data['notes'] ?? null,
                'user_id'        => $request->user()->id,
                'created_by'     => $request->user()->id,
                'shift_id'       => $shiftId,
                'outlet_id'      => $outletId,
            ]);

            // Save selected modifiers
            foreach ($options as $opt) {
                TransactionModifier::create([
                    'transaction_id'     => $trx->id,
                    'modifier_option_id' => $opt->id,
                    'group_name'         => $opt->group?->name ?? 'Varian',
                    'name'               => $opt->name,
                    'price'              => (float)$opt->price,
                    'ingredient_id'      => $opt->ingredient_id,
                    'qty'                => (float)($opt->qty ?? 0),
                    'unit'               => $opt->unit,
                    'total_price'        => (float)($opt->price * $qty),
                ]);

                // Stock deduction for modifier ingredient if NOT in shift and PAID
                if ($orderStatus === 'PAID' && !$shiftId && $opt->ingredient_id && $opt->qty > 0) {
                    StockMovement::create([
                        'date'           => $data['date'],
                        'ingredient_id'  => $opt->ingredient_id,
                        'type'           => 'SALE_USAGE',
                        'qty'            => (float)($opt->qty * $qty),
                        'note'           => "{$orderNumber} – Modifier {$opt->name} ({$menu->name} x{$qty})",
                        'transaction_id' => $trx->id,
                        'outlet_id'      => $outletId,
                        'user_id'        => $request->user()->id,
                        'created_by'     => $request->user()->id,
                    ]);
                }
            }

            // Deduct stock for DIRECT retail items
            if ($orderStatus === 'PAID') {
                if ($menu->item_type === 'DIRECT' && $menu->track_stock) {
                    $menu->decrement('stock', $qty);
                    if ($outletId) {
                        $om = \App\Models\OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                        if ($om) {
                            $om->decrement('stock', $qty);
                        }
                    }
                }
            }

            // Only create per-transaction stock movement if NOT in a shift and PAID
            if ($orderStatus === 'PAID' && !$shiftId && $recipe) {
                foreach ($recipe->items as $item) {
                    StockMovement::create([
                        'date'           => $data['date'],
                        'ingredient_id'  => $item->ingredient_id,
                        'type'           => 'SALE_USAGE',
                        'qty'            => $item->qty * $qty,
                        'note'           => "{$orderNumber} – {$menu->name} (x{$qty})",
                        'transaction_id' => $trx->id,
                        'outlet_id'      => $outletId,
                        'user_id'        => $request->user()->id,
                        'created_by'     => $request->user()->id,
                    ]);
                }
            }

            return $trx;
        });

        $trx->load(['menu', 'modifiers.ingredient']);
        return response()->json($trx, 201);
    }

    /**
     * Get all active Open Bills / Hold Orders.
     */
    public function openBills(Request $request)
    {
        $outletId = $request->outlet_id ?? $request->user()?->outlet_id;
        $query = Transaction::with(['menu', 'user', 'outlet', 'modifiers.ingredient'])
            ->where('status', 'HOLD')
            ->orderBy('created_at', 'asc');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        $transactions = $query->get();

        // Group by order_number
        $grouped = [];
        foreach ($transactions as $t) {
            $ord = $t->order_number;
            if (!isset($grouped[$ord])) {
                $grouped[$ord] = [
                    'order_number'    => $ord,
                    'status'          => 'HOLD',
                    'date'            => $t->date,
                    'customer_name'   => $t->customer_name,
                    'order_type'      => $t->order_type,
                    'table_number'    => $t->table_number,
                    'notes'           => $t->notes,
                    'outlet_id'       => $t->outlet_id,
                    'outlet_name'     => $t->outlet?->name ?? 'Outlet',
                    'shift_id'        => $t->shift_id,
                    'subtotal'        => 0.0,
                    'discount_amount' => 0.0,
                    'discount_name'   => $t->discount_name ?? null,
                    'cashier'         => [
                        'id'   => $t->user_id,
                        'name' => $t->user?->name ?? 'Kasir',
                    ],
                    'created_at'      => $t->created_at?->toDateTimeString(),
                    'duration_mins'   => $t->created_at ? max(1, round(abs(now()->diffInMinutes($t->created_at)))) : 1,
                    'total_price'     => 0.0,
                    'total_items'     => 0,
                    'items'           => [],
                ];
            }

            $price = (float)($t->menu?->price ?? 0);
            $itemSubtotal = (float)($t->subtotal ?: $t->total_price);
            $itemDiscount = (float)($t->discount_amount ?? 0);

            $grouped[$ord]['subtotal'] += $itemSubtotal;
            $grouped[$ord]['discount_amount'] += $itemDiscount;
            if ($t->discount_name && empty($grouped[$ord]['discount_name'])) {
                $grouped[$ord]['discount_name'] = $t->discount_name;
            }
            $grouped[$ord]['total_price'] += (float)$t->total_price;
            $grouped[$ord]['total_items'] += (int)$t->qty;
            $grouped[$ord]['items'][] = [
                'id'              => $t->id,
                'menu_id'         => $t->menu_id,
                'menu_name'       => $t->menu?->name ?? 'Menu',
                'price'           => $price,
                'qty'             => (int)$t->qty,
                'subtotal'        => $itemSubtotal,
                'discount_amount' => $itemDiscount,
                'total_price'     => (float)$t->total_price,
                'notes'           => $t->notes,
                'created_at'      => $t->created_at?->toDateTimeString(),
                'modifiers'       => $t->modifiers->map(fn($m) => [
                    'id'          => $m->id,
                    'name'        => $m->name,
                    'group_name'  => $m->group_name,
                    'price'       => (float)$m->price,
                    'qty'         => (float)$m->qty,
                    'unit'        => $m->unit,
                    'total_price' => (float)$m->total_price,
                ]),
            ];
        }

        // Enhance each open bill with equal split payment progress if any
        foreach ($grouped as $ord => &$bill) {
            $paidSplits = Transaction::where('parent_order_number', $ord)
                ->where('split_type', 'EQUAL')
                ->where('status', 'PAID')
                ->get();

            $paidSplitsTotal = (float)$paidSplits->sum('total_price');
            $paidSplitsCount = $paidSplits->count();
            $paidSplitIndices = $paidSplits->pluck('split_index')->map(fn($i) => (int)$i)->all();
            $firstSplit = $paidSplits->first();

            $bill['paid_splits_total']   = $paidSplitsTotal;
            $bill['paid_splits_count']   = $paidSplitsCount;
            $bill['paid_split_indices']  = $paidSplitIndices;
            $bill['split_total']         = $firstSplit?->split_total ?? 0;
            $bill['remaining_balance']   = max(0, $bill['total_price'] - $paidSplitsTotal);
        }
        unset($bill);

        return response()->json(array_values($grouped));
    }

    /**
     * Pay and complete an open bill.
     */
    public function payOpenBill(Request $request, $orderNumber)
    {
        $data = $request->validate([
            'payment_method'  => 'required|string|in:CASH,QRIS,TRANSFER,DEBIT',
            'amount_paid'     => 'required|numeric|min:0',
            'change_amount'   => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
            'discount_id'     => 'nullable|exists:discounts,id',
            'discount_code'   => 'nullable|string|max:50',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_name'   => 'nullable|string|max:100',
            'discount_type'   => 'nullable|string|max:30',
            'discount_rate'   => 'nullable|numeric|min:0',
        ]);

        $transactions = Transaction::with(['menu.recipes.items', 'modifiers.ingredient', 'outlet'])
            ->where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Tagihan terbuka dengan nomor '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        $first = $transactions->first();
        $grossSubtotal = (float)$transactions->sum(fn($t) => $t->subtotal ?: $t->total_price);
        $hasDiscountInput = !empty($data['discount_id']) || !empty($data['discount_code']) || (!empty($data['discount_amount']) && (float)$data['discount_amount'] > 0);

        $discInfo = null;
        if ($hasDiscountInput) {
            $discInfo = $this->resolveDiscountDetails(
                $data,
                $grossSubtotal,
                $first->outlet_id,
                $first->date,
                (int)($request->user()->business_id ?? 1)
            );
        }

        $totalOrder = $discInfo ? max(0, $grossSubtotal - $discInfo['discount_amount']) : (float)$transactions->sum('total_price');
        $amountPaid = (float)$data['amount_paid'];
        if ($amountPaid < $totalOrder && $data['payment_method'] === 'CASH') {
            return response()->json([
                'message' => "Jumlah pembayaran kurang dari total tagihan."
            ], 422);
        }

        $changeAmount = (float)($data['change_amount'] ?? max(0, $amountPaid - $totalOrder));

        DB::transaction(function () use ($transactions, $data, $request, $amountPaid, $changeAmount, $orderNumber, $discInfo, $grossSubtotal) {
            $first = $transactions->first();
            $shiftId = $first->shift_id;
            $outletId = $first->outlet_id;
            $date = $first->date;

            $accumulatedDisc = 0;
            $itemsCount = $transactions->count();

            foreach ($transactions as $idx => $t) {
                $itemGross = (float)($t->subtotal ?: $t->total_price);

                if ($discInfo && $discInfo['discount_amount'] > 0 && $grossSubtotal > 0) {
                    if ($idx === $itemsCount - 1) {
                        $itemDisc = round($discInfo['discount_amount'] - $accumulatedDisc, 2);
                    } else {
                        $itemDisc = round($discInfo['discount_amount'] * ($itemGross / $grossSubtotal), 2);
                        $accumulatedDisc += $itemDisc;
                    }
                    $itemNet = max(0, $itemGross - $itemDisc);
                    $t->subtotal = $itemGross;
                    $t->discount_id = $discInfo['discount_id'];
                    $t->discount_amount = $itemDisc;
                    $t->discount_name = $discInfo['discount_name'];
                    $t->discount_type = $discInfo['discount_type'];
                    $t->discount_rate = $discInfo['discount_rate'];
                    $t->total_price = $itemNet;
                }

                $t->status = 'PAID';
                $t->payment_method = $data['payment_method'];
                $t->amount_paid = $amountPaid;
                $t->change_amount = $changeAmount;
                $t->notes = !empty($data['notes']) ? $data['notes'] : $t->notes;
                $t->updated_by = $request->user()->id;
                $t->save();

                // Deduct stock for DIRECT retail items
                $menu = $t->menu;
                if ($menu && $menu->item_type === 'DIRECT' && $menu->track_stock) {
                    $menu->decrement('stock', $t->qty);
                    if ($outletId) {
                        $om = \App\Models\OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                        if ($om) {
                            $om->decrement('stock', $t->qty);
                        }
                    }
                }

                // If not in shift, deduct ingredients immediately via StockMovement
                if (!$shiftId) {
                    $menu = $t->menu;
                    $recipe = $menu?->activeRecipe($date);
                    if ($recipe) {
                        foreach ($recipe->items as $item) {
                            StockMovement::create([
                                'date'           => $date,
                                'ingredient_id'  => $item->ingredient_id,
                                'type'           => 'SALE_USAGE',
                                'qty'            => $item->qty * $t->qty,
                                'note'           => "{$orderNumber} (Pelunasan Open Bill) – {$menu->name} (x{$t->qty})",
                                'transaction_id' => $t->id,
                                'outlet_id'      => $outletId,
                                'user_id'        => $request->user()->id,
                                'created_by'     => $request->user()->id,
                            ]);
                        }
                    }

                    // Also deduct modifier ingredients
                    foreach ($t->modifiers as $mod) {
                        if ($mod->ingredient_id && $mod->qty > 0) {
                            StockMovement::create([
                                'date'           => $date,
                                'ingredient_id'  => $mod->ingredient_id,
                                'type'           => 'SALE_USAGE',
                                'qty'            => (float)($mod->qty * $t->qty),
                                'note'           => "{$orderNumber} (Pelunasan Open Bill) – Modifier {$mod->name} ({$t->menu?->name} x{$t->qty})",
                                'transaction_id' => $t->id,
                                'outlet_id'      => $outletId,
                                'user_id'        => $request->user()->id,
                                'created_by'     => $request->user()->id,
                            ]);
                        }
                    }
                }
            }

            if ($discInfo && $discInfo['model']) {
                $discInfo['model']->increment('used_count');
            }
        });

        $first = $transactions->first();
        return response()->json([
            'message'        => 'Pembayaran tagihan berhasil diselesaikan.',
            'order_number'   => $orderNumber,
            'status'         => 'PAID',
            'table_number'   => $first->table_number,
            'customer_name'  => $first->customer_name,
            'payment_method' => $data['payment_method'],
            'subtotal'       => $grossSubtotal,
            'discount_amount'=> $discInfo ? $discInfo['discount_amount'] : (float)$transactions->sum('discount_amount'),
            'discount_name'  => $discInfo ? $discInfo['discount_name'] : $first->discount_name,
            'amount_paid'    => $amountPaid,
            'change_amount'  => $changeAmount,
            'total_price'    => $totalOrder,
            'items'          => array_map(fn($t) => [
                'id'          => $t->id,
                'menu_id'     => $t->menu_id,
                'menu_name'   => $t->menu?->name,
                'price'       => (float)($t->menu?->price ?? 0),
                'qty'         => $t->qty,
                'total_price' => $t->total_price,
                'notes'       => $t->notes,
                'modifiers'   => $t->modifiers->map(fn($m) => [
                    'id'          => $m->id,
                    'name'        => $m->name,
                    'group_name'  => $m->group_name,
                    'price'       => (float)$m->price,
                    'qty'         => (float)$m->qty,
                    'unit'        => $m->unit,
                    'total_price' => (float)$m->total_price,
                ]),
            ], $transactions->all()),
            'paid_at'        => now()->toDateTimeString(),
        ]);
    }

    /**
     * Add new items to an existing open bill.
     */
    public function addItemsToOpenBill(Request $request, $orderNumber)
    {
        $data = $request->validate([
            'items'                        => 'required|array|min:1',
            'items.*.menu_id'              => 'required|exists:menus,id',
            'items.*.qty'                  => 'required|integer|min:1',
            'items.*.notes'                => 'nullable|string|max:255',
            'items.*.modifier_option_ids'  => 'nullable|array',
            'items.*.modifier_option_ids.*'=> 'exists:modifier_options,id',
            'items.*.modifiers'            => 'nullable|array',
        ]);

        $existing = Transaction::where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->first();

        if (!$existing) {
            return response()->json([
                'message' => "Tagihan terbuka '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        $date = $existing->date;
        $shiftId = $existing->shift_id;
        $outletId = $existing->outlet_id;
        $tableNumber = $existing->table_number;
        $customerName = $existing->customer_name;
        $orderType = $existing->order_type;

        $addedTransactions = DB::transaction(function () use (
            $data, $orderNumber, $date, $shiftId, $outletId, $tableNumber, $customerName, $orderType, $request
        ) {
            $created = [];
            foreach ($data['items'] as $it) {
                $menu = Menu::findOrFail($it['menu_id']);
                $recipe = $menu->activeRecipe($date);
                $qty = (int)$it['qty'];

                $options = $this->resolveModifierOptions($it);
                $modifierUnitPrice = (float)$options->sum('price');
                $price = (float)$menu->price + $modifierUnitPrice;

                $trx = Transaction::create([
                    'order_number'   => $orderNumber,
                    'status'         => 'HOLD',
                    'date'           => $date,
                    'menu_id'        => $menu->id,
                    'qty'            => $qty,
                    'recipe_version' => $recipe?->version ?? null,
                    'total_price'    => $price * $qty,
                    'customer_name'  => $customerName,
                    'order_type'     => $orderType,
                    'table_number'   => $tableNumber,
                    'payment_method' => 'CASH',
                    'notes'          => $it['notes'] ?? null,
                    'user_id'        => $request->user()->id,
                    'created_by'     => $request->user()->id,
                    'shift_id'       => $shiftId,
                    'outlet_id'      => $outletId,
                ]);

                // Save selected modifiers
                foreach ($options as $opt) {
                    TransactionModifier::create([
                        'transaction_id'     => $trx->id,
                        'modifier_option_id' => $opt->id,
                        'group_name'         => $opt->group?->name ?? 'Varian',
                        'name'               => $opt->name,
                        'price'              => (float)$opt->price,
                        'ingredient_id'      => $opt->ingredient_id,
                        'qty'                => (float)($opt->qty ?? 0),
                        'unit'               => $opt->unit,
                        'total_price'        => (float)($opt->price * $qty),
                    ]);
                }

                $trx->load(['menu', 'modifiers.ingredient']);
                $created[] = $trx;
            }
            return $created;
        });

        return response()->json([
            'message'      => 'Item tambahan berhasil ditambahkan ke tagihan terbuka.',
            'order_number' => $orderNumber,
            'table_number' => $tableNumber,
            'added_items'  => array_map(fn($t) => [
                'id'          => $t->id,
                'menu_id'     => $t->menu_id,
                'menu_name'   => $t->menu?->name,
                'price'       => (float)($t->menu?->price ?? 0),
                'qty'         => $t->qty,
                'total_price' => $t->total_price,
                'notes'       => $t->notes,
                'modifiers'   => $t->modifiers->map(fn($m) => [
                    'id'          => $m->id,
                    'name'        => $m->name,
                    'group_name'  => $m->group_name,
                    'price'       => (float)$m->price,
                    'qty'         => (float)$m->qty,
                    'unit'        => $m->unit,
                    'total_price' => (float)$m->total_price,
                ]),
            ], $addedTransactions),
        ]);
    }

    /**
     * Cancel an open bill.
     */
    public function cancelOpenBill(Request $request, $orderNumber)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $transactions = Transaction::where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Tagihan terbuka '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        DB::transaction(function () use ($transactions, $data, $request) {
            $reasonText = $data['reason'] ? " [Dibatalkan: {$data['reason']}]" : " [Dibatalkan]";
            foreach ($transactions as $t) {
                $t->update([
                    'status'     => 'CANCELLED',
                    'notes'      => ($t->notes ?? '') . $reasonText,
                    'updated_by' => $request->user()->id,
                ]);
            }
        });

        return response()->json([
            'message' => "Tagihan terbuka '{$orderNumber}' berhasil dibatalkan."
        ]);
    }

    /**
     * Deduct stock for transaction items when outside of regular shift reconciliation.
     */
    private function deductStockForTransaction(Transaction $t, string $orderRef, int $userId)
    {
        $menu = $t->menu;
        $outletId = $t->outlet_id;

        // Deduct direct stock for DIRECT retail items
        if ($menu && $menu->item_type === 'DIRECT' && $menu->track_stock) {
            $menu->decrement('stock', $t->qty);
            if ($outletId) {
                $om = \App\Models\OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                if ($om) {
                    $om->decrement('stock', $t->qty);
                }
            }
        }

        if ($t->shift_id) return;

        $date = $t->date;
        $recipe = $menu?->activeRecipe($date);

        if ($recipe) {
            foreach ($recipe->items as $item) {
                StockMovement::create([
                    'date'           => $date,
                    'ingredient_id'  => $item->ingredient_id,
                    'type'           => 'SALE_USAGE',
                    'qty'            => $item->qty * $t->qty,
                    'note'           => "{$orderRef} (Split Bill) – {$menu->name} (x{$t->qty})",
                    'transaction_id' => $t->id,
                    'outlet_id'      => $outletId,
                    'user_id'        => $userId,
                    'created_by'     => $userId,
                ]);
            }
        }

        foreach ($t->modifiers as $mod) {
            if ($mod->ingredient_id && $mod->qty > 0) {
                StockMovement::create([
                    'date'           => $date,
                    'ingredient_id'  => $mod->ingredient_id,
                    'type'           => 'SALE_USAGE',
                    'qty'            => (float)($mod->qty * $t->qty),
                    'note'           => "{$orderRef} (Split Bill) – Modifier {$mod->name} ({$t->menu?->name} x{$t->qty})",
                    'transaction_id' => $t->id,
                    'outlet_id'      => $outletId,
                    'user_id'        => $userId,
                    'created_by'     => $userId,
                ]);
            }
        }
    }

    /**
     * Settle a partial bill by selecting specific items/portions (Split by Item).
     */
    public function paySplitByItem(Request $request, $orderNumber)
    {
        $data = $request->validate([
            'items'           => 'required|array|min:1',
            'items.*.id'      => 'required|integer|exists:transactions,id',
            'items.*.qty'     => 'required|integer|min:1',
            'payment_method'  => 'required|string|in:CASH,QRIS,TRANSFER,DEBIT',
            'amount_paid'     => 'required|numeric|min:0',
            'change_amount'   => 'nullable|numeric|min:0',
            'customer_name'   => 'nullable|string|max:100',
            'notes'           => 'nullable|string|max:500',
        ]);

        $transactions = Transaction::with(['menu.recipes.items', 'modifiers.ingredient', 'outlet'])
            ->where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Tagihan terbuka '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        $trxMap = $transactions->keyBy('id');

        // Validate that all items exist in this order and requested qty <= current qty
        $requestedItems = [];
        $splitGrossSubtotal = 0.0;
        $splitDiscountAmount = 0.0;
        $splitNetTotal = 0.0;

        foreach ($data['items'] as $itemInput) {
            $id = $itemInput['id'];
            $reqQty = (int)$itemInput['qty'];

            if (!isset($trxMap[$id])) {
                return response()->json([
                    'message' => "Item ID #{$id} tidak ditemukan pada tagihan terbuka '{$orderNumber}'."
                ], 422);
            }

            $orig = $trxMap[$id];
            if ($reqQty > $orig->qty) {
                return response()->json([
                    'message' => "Jumlah porsi {$orig->menu?->name} yang diminta ({$reqQty}) melebihi jumlah tersisa ({$orig->qty})."
                ], 422);
            }

            $unitSubtotal = ($orig->subtotal ?: $orig->total_price) / $orig->qty;
            $unitDiscount = ($orig->discount_amount ?: 0) / $orig->qty;
            $unitTotal    = $orig->total_price / $orig->qty;

            $itemSubtotal = round($unitSubtotal * $reqQty, 2);
            $itemDiscount = round($unitDiscount * $reqQty, 2);
            $itemTotal    = round($unitTotal * $reqQty, 2);

            $splitGrossSubtotal += $itemSubtotal;
            $splitDiscountAmount += $itemDiscount;
            $splitNetTotal += $itemTotal;

            $requestedItems[] = [
                'transaction' => $orig,
                'req_qty'     => $reqQty,
                'subtotal'    => $itemSubtotal,
                'discount'    => $itemDiscount,
                'total_price' => $itemTotal,
            ];
        }

        $amountPaid = (float)$data['amount_paid'];
        if ($amountPaid < $splitNetTotal && $data['payment_method'] === 'CASH') {
            return response()->json([
                'message' => "Jumlah pembayaran tunai kurang dari total sub-tagihan (" . number_format($splitNetTotal) . ")."
            ], 422);
        }

        $changeAmount = (float)($data['change_amount'] ?? max(0, $amountPaid - $splitNetTotal));

        // Determine split index
        $lastSplit = Transaction::where('parent_order_number', $orderNumber)
            ->max('split_index');
        $splitIndex = ($lastSplit ? (int)$lastSplit : 0) + 1;
        $subOrderNumber = sprintf("%s-S%d", $orderNumber, $splitIndex);

        $first = $transactions->first();
        $date = $first->date;
        $outletId = $first->outlet_id;
        $shiftId = $first->shift_id;
        $tableNumber = $first->table_number;
        $customerName = !empty($data['customer_name']) ? trim($data['customer_name']) : ($first->customer_name ? "{$first->customer_name} (Bagian {$splitIndex})" : "Tamu {$splitIndex}");

        $paidResult = DB::transaction(function () use (
            $requestedItems, $orderNumber, $subOrderNumber, $splitIndex, $data, $amountPaid, $changeAmount,
            $date, $outletId, $shiftId, $tableNumber, $customerName, $request
        ) {
            $paidRows = [];

            foreach ($requestedItems as $req) {
                /** @var Transaction $orig */
                $orig = $req['transaction'];
                $reqQty = $req['req_qty'];

                if ($reqQty === $orig->qty) {
                    // Entire line is paid in this split!
                    $orig->update([
                        'order_number'        => $subOrderNumber,
                        'parent_order_number' => $orderNumber,
                        'split_index'         => $splitIndex,
                        'split_type'          => 'BY_ITEM',
                        'status'              => 'PAID',
                        'payment_method'      => $data['payment_method'],
                        'amount_paid'         => $amountPaid,
                        'change_amount'       => $changeAmount,
                        'customer_name'       => $customerName,
                        'notes'               => !empty($data['notes']) ? $data['notes'] : $orig->notes,
                        'updated_by'          => $request->user()->id,
                    ]);

                    $paidRows[] = $orig->fresh(['menu', 'modifiers.ingredient']);
                    $this->deductStockForTransaction($orig, $subOrderNumber, $request->user()->id);
                } else {
                    // Partial line: reduce remaining in HOLD, create new PAID transaction
                    $remainingQty = $orig->qty - $reqQty;
                    $origUnitSubtotal = ($orig->subtotal ?: $orig->total_price) / $orig->qty;
                    $origUnitDiscount = ($orig->discount_amount ?: 0) / $orig->qty;
                    $origUnitNet = $orig->total_price / $orig->qty;

                    $orig->update([
                        'qty'             => $remainingQty,
                        'subtotal'        => round($origUnitSubtotal * $remainingQty, 2),
                        'discount_amount' => round($origUnitDiscount * $remainingQty, 2),
                        'total_price'     => round($origUnitNet * $remainingQty, 2),
                        'updated_by'      => $request->user()->id,
                    ]);

                    // Create sub-bill paid transaction
                    $newTrx = Transaction::create([
                        'order_number'        => $subOrderNumber,
                        'parent_order_number' => $orderNumber,
                        'split_index'         => $splitIndex,
                        'split_type'          => 'BY_ITEM',
                        'status'              => 'PAID',
                        'business_id'         => $orig->business_id,
                        'date'                => $date,
                        'menu_id'             => $orig->menu_id,
                        'qty'                 => $reqQty,
                        'recipe_version'      => $orig->recipe_version,
                        'subtotal'            => $req['subtotal'],
                        'discount_id'         => $orig->discount_id,
                        'discount_amount'     => $req['discount'],
                        'discount_name'       => $orig->discount_name,
                        'discount_type'       => $orig->discount_type,
                        'discount_rate'       => $orig->discount_rate,
                        'total_price'         => $req['total_price'],
                        'amount_paid'         => $amountPaid,
                        'change_amount'       => $changeAmount,
                        'customer_name'       => $customerName,
                        'order_type'          => $orig->order_type,
                        'table_number'        => $orig->table_number,
                        'payment_method'      => $data['payment_method'],
                        'notes'               => !empty($data['notes']) ? $data['notes'] : $orig->notes,
                        'user_id'             => $request->user()->id,
                        'shift_id'            => $shiftId,
                        'outlet_id'           => $outletId,
                        'created_by'          => $request->user()->id,
                    ]);

                    // Copy modifiers to new transaction
                    foreach ($orig->modifiers as $mod) {
                        TransactionModifier::create([
                            'transaction_id'     => $newTrx->id,
                            'modifier_option_id' => $mod->modifier_option_id,
                            'group_name'         => $mod->group_name,
                            'name'               => $mod->name,
                            'price'              => (float)$mod->price,
                            'ingredient_id'      => $mod->ingredient_id,
                            'qty'                => (float)$mod->qty,
                            'unit'               => $mod->unit,
                            'total_price'        => (float)($mod->price * $reqQty),
                        ]);
                    }

                    $paidRows[] = $newTrx->fresh(['menu', 'modifiers.ingredient']);
                    $this->deductStockForTransaction($newTrx, $subOrderNumber, $request->user()->id);
                }
            }

            return $paidRows;
        });

        // Check remaining items on the table
        $remainingHold = Transaction::where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        $isTableClosed = $remainingHold->isEmpty();
        $remainingTotal = (float)$remainingHold->sum('total_price');
        $remainingItemsCount = (int)$remainingHold->sum('qty');

        return response()->json([
            'message'              => "Pembayaran Split Bill Bagian {$splitIndex} ({$subOrderNumber}) berhasil!",
            'order_number'         => $subOrderNumber,
            'parent_order_number'  => $orderNumber,
            'split_index'          => $splitIndex,
            'split_type'           => 'BY_ITEM',
            'table_number'         => $tableNumber,
            'customer_name'        => $customerName,
            'date'                 => $date,
            'payment_method'       => $data['payment_method'],
            'amount_paid'          => $amountPaid,
            'change_amount'        => $changeAmount,
            'subtotal'             => $splitGrossSubtotal,
            'discount_amount'      => $splitDiscountAmount,
            'total_price'          => $splitNetTotal,
            'is_table_closed'      => $isTableClosed,
            'remaining_total'      => $remainingTotal,
            'remaining_items_count'=> $remainingItemsCount,
            'items'                => array_map(fn($t) => [
                'id'          => $t->id,
                'menu_id'     => $t->menu_id,
                'menu_name'   => $t->menu?->name ?? 'Menu',
                'price'       => (float)($t->menu?->price ?? ($t->subtotal / $t->qty)),
                'qty'         => $t->qty,
                'subtotal'    => (float)($t->subtotal ?: $t->total_price),
                'discount'    => (float)($t->discount_amount ?? 0),
                'total_price' => (float)$t->total_price,
                'notes'       => $t->notes,
                'modifiers'   => $t->modifiers->map(fn($m) => [
                    'id'          => $m->id,
                    'name'        => $m->name,
                    'group_name'  => $m->group_name,
                    'price'       => (float)$m->price,
                    'qty'         => (float)$m->qty,
                    'unit'        => $m->unit,
                    'total_price' => (float)$m->total_price,
                ]),
            ], $paidResult),
            'paid_at'              => now()->toDateTimeString(),
        ]);
    }

    /**
     * Settle a portion of an open bill by dividing the total evenly (Split Evenly).
     */
    public function paySplitEvenly(Request $request, $orderNumber)
    {
        $data = $request->validate([
            'total_splits'   => 'required|integer|min:2|max:20',
            'split_index'    => 'required|integer|min:1',
            'split_amount'   => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:CASH,QRIS,TRANSFER,DEBIT',
            'amount_paid'    => 'required|numeric|min:0',
            'change_amount'  => 'nullable|numeric|min:0',
            'customer_name'  => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:500',
        ]);

        $transactions = Transaction::with(['menu.recipes.items', 'modifiers.ingredient', 'outlet'])
            ->where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Tagihan terbuka '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        $first = $transactions->first();
        $tableTotal = (float)$transactions->sum('total_price');
        $splitAmount = (float)$data['split_amount'];
        $amountPaid = (float)$data['amount_paid'];
        $totalSplits = (int)$data['total_splits'];
        $splitIndex = (int)$data['split_index'];

        if ($amountPaid < $splitAmount && $data['payment_method'] === 'CASH') {
            return response()->json([
                'message' => "Jumlah pembayaran tunai kurang dari nominal bagian ini (" . number_format($splitAmount) . ")."
            ], 422);
        }

        $changeAmount = (float)($data['change_amount'] ?? max(0, $amountPaid - $splitAmount));
        $subOrderNumber = sprintf("%s-E%d", $orderNumber, $splitIndex);
        $customerName = !empty($data['customer_name']) ? trim($data['customer_name']) : "Tamu {$splitIndex}/{$totalSplits}";

        // Check already paid equal splits for this parent order
        $previousPaidSplits = Transaction::where('parent_order_number', $orderNumber)
            ->where('split_type', 'EQUAL')
            ->where('status', 'PAID')
            ->count();
        $newPaidSplitsCount = $previousPaidSplits + 1;
        $isTableClosed = ($newPaidSplitsCount >= $totalSplits);

        DB::transaction(function () use (
            $transactions, $first, $orderNumber, $subOrderNumber, $splitIndex, $totalSplits, $splitAmount,
            $amountPaid, $changeAmount, $data, $customerName, $isTableClosed, $request
        ) {
            // Create a payment record for this equal split installment
            Transaction::create([
                'order_number'        => $subOrderNumber,
                'parent_order_number' => $orderNumber,
                'split_index'         => $splitIndex,
                'split_total'         => $totalSplits,
                'split_type'          => 'EQUAL',
                'status'              => 'PAID',
                'business_id'         => $first->business_id,
                'date'                => $first->date,
                'menu_id'             => $first->menu_id,
                'qty'                 => 1,
                'recipe_version'      => $first->recipe_version,
                'subtotal'            => $splitAmount,
                'total_price'         => $splitAmount,
                'amount_paid'         => $amountPaid,
                'change_amount'       => $changeAmount,
                'customer_name'       => $customerName,
                'order_type'          => $first->order_type,
                'table_number'        => $first->table_number,
                'payment_method'      => $data['payment_method'],
                'notes'               => "Split Evenly ({$splitIndex}/{$totalSplits})" . (!empty($data['notes']) ? " - {$data['notes']}" : ""),
                'user_id'             => $request->user()->id,
                'shift_id'            => $first->shift_id,
                'outlet_id'           => $first->outlet_id,
                'created_by'          => $request->user()->id,
            ]);

            // If this is the final split, mark the original HOLD transactions as SPLIT_CLOSED to close the table
            if ($isTableClosed) {
                foreach ($transactions as $t) {
                    $t->update([
                        'status'     => 'SPLIT_CLOSED',
                        'updated_by' => $request->user()->id,
                    ]);
                    $this->deductStockForTransaction($t, $orderNumber, $request->user()->id);
                }
            }
        });

        $remainingTotal = max(0, $tableTotal - ($splitAmount * $newPaidSplitsCount));

        return response()->json([
            'message'              => "Pembayaran Patungan Bagian {$splitIndex}/{$totalSplits} berhasil!",
            'order_number'         => $subOrderNumber,
            'parent_order_number'  => $orderNumber,
            'split_index'          => $splitIndex,
            'split_total'          => $totalSplits,
            'split_type'           => 'EQUAL',
            'table_number'         => $first->table_number,
            'customer_name'        => $customerName,
            'date'                 => $first->date,
            'payment_method'       => $data['payment_method'],
            'amount_paid'          => $amountPaid,
            'change_amount'        => $changeAmount,
            'subtotal'             => $splitAmount,
            'discount_amount'      => 0,
            'total_price'          => $splitAmount,
            'is_table_closed'      => $isTableClosed,
            'remaining_total'      => $remainingTotal,
            'paid_splits_count'    => $newPaidSplitsCount,
            'items'                => [
                [
                    'menu_name'   => "Patungan Meja {$first->table_number} ({$splitIndex}/{$totalSplits})",
                    'qty'         => 1,
                    'price'       => $splitAmount,
                    'total_price' => $splitAmount,
                    'notes'       => "Nominal bagian per orang dari total Rp " . number_format($tableTotal),
                    'modifiers'   => [],
                ]
            ],
            'paid_at'              => now()->toDateTimeString(),
        ]);
    }

    public function destroy(Transaction $transaction)
    {
        DB::transaction(function () use ($transaction) {
            $transaction->stockMovements()->delete();
            $transaction->delete();
        });

        return response()->json(['message' => 'Transaksi dibatalkan']);
    }
}
