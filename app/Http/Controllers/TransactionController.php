<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Transaction;
use App\Models\StockMovement;
use App\Models\ModifierOption;
use App\Models\TransactionModifier;
use App\Models\Discount;
use App\Models\UrgentNote;
use App\Models\OutletMenu;
use App\Models\Outlet;
use App\Models\Customer;
use App\Models\PointRedemption;
use App\Services\CoinService;
use App\Models\User;
use App\Models\WasteLog;
use App\Services\AccountingService;
use App\Services\OrderNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TransactionController extends Controller
{
    /**
     * Helper to process ingredient stock deduction with Urgent Note support.
     * Delegates to StockDeductionService for clean domain separation.
     */
    private function processIngredientStockDeduction(
        int $businessId,
        int $outletId,
        Transaction $trx,
        string $orderNumber,
        string $date,
        \App\Models\Ingredient $ing,
        float $requiredQty,
        string $itemName,
        string $itemType,
        ?int $menuId,
        bool $isShiftActive,
        bool $isPaid,
        int $userId,
        ?string $unit = null
    ): array {
        return \App\Services\StockDeductionService::processIngredientStockDeduction(
            $businessId,
            $outletId,
            $trx,
            $orderNumber,
            $date,
            $ing,
            $requiredQty,
            $itemName,
            $itemType,
            $menuId,
            $isShiftActive,
            $isPaid,
            $userId,
            $unit
        );
    }

    /**
     * Helper to process direct retail product stock deduction with Urgent Note support.
     * Delegates to StockDeductionService for clean domain separation.
     */
    private function processDirectProductStockDeduction(
        int $businessId,
        int $outletId,
        Transaction $trx,
        string $orderNumber,
        \App\Models\Menu $menu,
        int $qty,
        int $userId
    ): void {
        \App\Services\StockDeductionService::processDirectProductStockDeduction(
            $businessId,
            $outletId,
            $trx,
            $orderNumber,
            $menu,
            $qty,
            $userId
        );
    }

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
    private function resolveDiscountDetails(array $data, float $grossSubtotal, ?int $outletId, string $date, int $businessId, ?int $customerId = null): array
    {
        $discount = null;
        if (!empty($data['discount_id'])) {
            $discount = Discount::with('rewardMenu')->where('business_id', $businessId)->find($data['discount_id']);
        } elseif (!empty($data['discount_code'])) {
            $code = strtoupper(trim($data['discount_code']));
            $discount = Discount::with('rewardMenu')->where('business_id', $businessId)->where('code', $code)->first();
        }

        if ($discount) {
            $customer = $customerId ? Customer::find($customerId) : null;
            $check = $discount->validateForOrder($grossSubtotal, $outletId, $date, $customer);
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
     * Utilizes thread-safe atomic sequence generator with tenant isolation.
     */
    private function generateOrderNumber(string $date, ?int $businessId = null): string
    {
        $bizId = $businessId ?: (int)(request()->user()?->business_id ?: 1);
        return OrderNumberService::generateNextOrderNumber($bizId, $date);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        $query = Transaction::with(['menu', 'user', 'outlet', 'shift', 'creator', 'updater', 'cancelledByUser', 'voidRequestedByUser', 'voidApprovedByUser', 'voidRejectedByUser', 'modifiers.ingredient', 'discount', 'urgentNotes.ingredient', 'customer', 'receivable'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($request->from)         $query->where('date', '>=', $request->from);
        if ($request->to)           $query->where('date', '<=', $request->to);
        if ($request->menu_id)      $query->where('menu_id', $request->menu_id);
        if ($request->order_number) $query->where('order_number', $request->order_number);

        // Shift filter (single, array, or comma-separated list)
        if ($request->filled('shift_id') && strtoupper($request->shift_id) !== 'ALL') {
            $shiftInput = $request->shift_id;
            if (is_array($shiftInput)) {
                $shiftIds = array_values(array_filter(array_map('intval', $shiftInput)));
                if (count($shiftIds) > 0) {
                    $query->whereIn('shift_id', $shiftIds);
                }
            } else {
                $shiftIds = array_values(array_filter(array_map('intval', explode(',', $shiftInput))));
                if (count($shiftIds) > 0) {
                    $query->whereIn('shift_id', $shiftIds);
                }
            }
        }

        // Payment method filter (supports presets, comma-separated lists, and individual methods)
        if ($request->filled('payment_method') && strtoupper($request->payment_method) !== 'ALL') {
            $rawPm = $request->payment_method;
            $pmList = is_array($rawPm) ? $rawPm : explode(',', $rawPm);
            $pmList = array_map(fn($v) => strtoupper(trim($v)), array_filter($pmList));

            if (count($pmList) > 0 && !in_array('ALL', $pmList)) {
                $query->where(function($q) use ($pmList) {
                    foreach ($pmList as $idx => $pm) {
                        $clause = function($subQ) use ($pm) {
                            if ($pm === 'NON_CASH' || $pm === 'ALL_NON_CASH') {
                                $subQ->where(function($inner) {
                                    $inner->where('payment_method', 'like', '%QRIS%')
                                          ->orWhere('payment_method', 'like', '%GRAB%')
                                          ->orWhere('payment_method', 'like', '%GOFOOD%')
                                          ->orWhere('payment_method', 'like', '%SHOPEE%')
                                          ->orWhere('payment_method', 'like', '%TIKTOK%')
                                          ->orWhere('payment_method', 'like', '%TRANSFER%')
                                          ->orWhere('payment_method', 'like', '%DEBIT%')
                                          ->orWhere('payment_method', 'like', '%EDC%')
                                          ->orWhere('payment_method', 'like', '%CREDIT%')
                                          ->orWhere('payment_method', 'like', '%ECOMMERCE%');
                                });
                            } elseif ($pm === 'CASH_ALL' || $pm === 'CASH_AND_PETTY') {
                                $subQ->where(function($inner) {
                                    $inner->whereIn('payment_method', ['CASH', 'TUNAI', 'PETTY_CASH'])
                                          ->orWhere('payment_method', 'like', '%TUNAI%')
                                          ->orWhere('payment_method', 'like', '%CASH%');
                                });
                            } elseif ($pm === 'CASH' || $pm === 'TUNAI') {
                                $subQ->where(function($inner) {
                                    $inner->whereIn('payment_method', ['CASH', 'TUNAI'])
                                          ->orWhere('payment_method', 'like', '%TUNAI%')
                                          ->orWhere('payment_method', 'like', '%CASH%');
                                });
                            } elseif ($pm === 'QRIS') {
                                $subQ->where('payment_method', 'like', '%QRIS%');
                            } elseif ($pm === 'GRAB' || $pm === 'ECOMMERCE' || $pm === 'ECOMMERCE_ALL') {
                                $subQ->where(function($inner) {
                                    $inner->where('payment_method', 'like', '%GRAB%')
                                          ->orWhere('payment_method', 'like', '%GOFOOD%')
                                          ->orWhere('payment_method', 'like', '%SHOPEE%')
                                          ->orWhere('payment_method', 'like', '%TIKTOK%')
                                          ->orWhere('payment_method', 'like', '%ECOMMERCE%');
                                });
                            } elseif ($pm === 'TRANSFER') {
                                $subQ->where('payment_method', 'like', '%TRANSFER%');
                            } elseif ($pm === 'DEBIT' || $pm === 'EDC') {
                                $subQ->where(function($inner) {
                                    $inner->where('payment_method', 'like', '%DEBIT%')
                                          ->orWhere('payment_method', 'like', '%EDC%')
                                          ->orWhere('payment_method', 'like', '%CREDIT%');
                                });
                            } else {
                                $subQ->where('payment_method', $pm);
                            }
                        };

                        if ($idx === 0) {
                            $q->where($clause);
                        } else {
                            $q->orWhere($clause);
                        }
                    }
                });
            }
        }

        if ($request->boolean('exclude_kasbon')) {
            $query->whereNotIn(DB::raw('UPPER(payment_method)'), ['KASBON', 'PIUTANG']);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                  ->orWhere('customer_name', 'like', "%{$s}%")
                  ->orWhere('table_number', 'like', "%{$s}%")
                  ->orWhere('notes', 'like', "%{$s}%")
                  ->orWhereHas('menu', fn($mq) => $mq->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$s}%"));
            });
        }
        
        if ($isOutletBounded) {
            $query->where('outlet_id', (int)$user->outlet_id);
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->status && $request->status !== 'ALL' && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('is_urgent_note')) {
            $query->where('is_urgent_note', $request->boolean('is_urgent_note'));
        }

        if ($request->boolean('has_discount')) {
            $query->where('discount_amount', '>', 0);
        }

        $limit = $request->integer('limit', 50);
        if ($limit <= 0 || $limit > 2000) {
            $limit = 50;
        }

        return response()->json($query->limit($limit)->get());
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
                'items.*.price'                => 'nullable|numeric|min:0',
                'items.*.notes'                => 'nullable|string|max:255',
                'items.*.is_urgent'            => 'nullable|boolean',
                'items.*.modifier_option_ids'  => 'nullable|array',
                'items.*.modifier_option_ids.*'=> 'exists:modifier_options,id',
                'items.*.modifiers'            => 'nullable|array',
                'is_urgent_note'               => 'nullable|boolean',
                'status'                       => 'nullable|string|in:PAID,HOLD',
                'date'                         => 'required|date',
                'shift_id'                     => 'nullable',
                'outlet_id'                    => 'nullable|exists:outlets,id',
                'customer_name'                => 'nullable|string|max:100',
                'customer_id'                  => 'nullable|exists:customers,id',
                'order_type'                   => 'nullable|string|in:DINE_IN,TAKEAWAY,DELIVERY',
                'table_number'                 => 'nullable|string|max:50',
                'payment_method'               => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,KASBON,PIUTANG,OTHER,APLIKASI',
                'dp_payment_method'            => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,OTHER',
                'dp_reference_no'              => 'nullable|string|max:100',
                'amount_paid'                  => 'nullable|numeric|min:0',
                'change_amount'                => 'nullable|numeric|min:0',
                'notes'                        => 'nullable|string|max:500',
                'discount_id'                  => 'nullable|exists:discounts,id',
                'discount_code'                => 'nullable|string|max:50',
                'discount_amount'              => 'nullable|numeric|min:0',
                'discount_name'                => 'nullable|string|max:100',
                'discount_type'                => 'nullable|string|max:30',
                'discount_rate'                => 'nullable|numeric|min:0',
                'client_order_id'              => 'nullable|string|max:100',
            ]);

            $orderStatus = $data['status'] ?? 'PAID';
            $isUrgentOrder = !empty($data['is_urgent_note']);

            // Resolve Outlet ID & Business ID
            $user = $request->user();
            if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
                $outletId = (int)$user->outlet_id;
            } else {
                $outletId = $data['outlet_id'] ?? $user?->outlet_id ?? null;
            }
            $businessId = (int)($user?->business_id ?: ($outletId ? \App\Models\Outlet::find($outletId)?->business_id : 1) ?: 1);

            if (!$outletId || $outletId === 'ALL' || $outletId === 'all') {
                $outletId = \App\Models\Outlet::where('business_id', $businessId)->where('is_main', true)->value('id')
                         ?? \App\Models\Outlet::where('business_id', $businessId)->value('id')
                         ?? 1;
            }

            // =========================================================================
            // IDEMPOTENCY PROTECTION (Pencegahan Duplikasi Order Offline / Retries)
            // =========================================================================
            $clientOrderId = $data['client_order_id'] ?? $request->input('client_order_id');
            if (!$clientOrderId && !empty($data['notes'])) {
                if (preg_match('/\[Offline ID:\s*([^\]]+)\]/i', $data['notes'], $matches)) {
                    $clientOrderId = trim($matches[1]);
                }
            }

            if (!empty($clientOrderId)) {
                $existingTx = Transaction::where('business_id', $businessId)
                    ->where(function ($q) use ($clientOrderId) {
                        $q->where('client_order_id', $clientOrderId)
                          ->orWhere('notes', 'like', "%[Offline ID: {$clientOrderId}]%");
                    })
                    ->first();

                if ($existingTx) {
                    $existingOrderNo = $existingTx->order_number;
                    $existingRows = Transaction::where('business_id', $businessId)
                        ->where('order_number', $existingOrderNo)
                        ->with(['menu', 'user', 'customer'])
                        ->get();

                    return response()->json([
                        'message'            => "Transaksi offline [{$clientOrderId}] sudah tercatat sebelumnya (idempotent).",
                        'order_number'       => $existingOrderNo,
                        'is_idempotent_sync' => true,
                        'transaction'        => $existingTx,
                        'items'              => $existingRows,
                    ], 200);
                }
            }

            // Pre-check SaaS coin balance if order is finalized as PAID
            if ($orderStatus === 'PAID') {
                $coinCheck = CoinService::canTransact($businessId);
                if (!$coinCheck['allowed']) {
                    return response()->json([
                        'message'    => $coinCheck['message'],
                        'coin_error' => true,
                        'coin_data'  => $coinCheck,
                    ], 402);
                }
            }

            // Resolve Active Shift & Effective Operational Date
            $user = $request->user();
            $isOwner = $user && ($user->isOwnerBisnis() || $user->isOwnerWebsite() || $user->isPlatformAdmin() || $user->isOwnerOutlet());

            $shiftId = $data['shift_id'] ?? null;
            $activeShift = null;
            if ($shiftId) {
                if (is_numeric($shiftId)) {
                    $activeShift = \App\Models\Shift::find($shiftId);
                } else {
                    $activeShift = \App\Models\Shift::where('business_id', $businessId)
                        ->where('client_shift_id', $shiftId)
                        ->first();
                }
            }
            if (!$activeShift) {
                $activeShift = \App\Models\Shift::where('status', 'OPEN')
                    ->where('outlet_id', $outletId)
                    ->orderByDesc('opened_at')
                    ->first();
                if (!$activeShift) {
                    $activeShift = \App\Models\Shift::where('status', 'OPEN')
                        ->orderByDesc('opened_at')
                        ->first();
                }
            }
            $shiftId = $activeShift?->id;

            // Dilarang melakukan transaksi kalau belum buka shift (berlaku untuk semua pengguna termasuk Owner)
            if (!$shiftId || !$activeShift) {
                return response()->json([
                    'message' => 'Shift kasir belum dibuka untuk cabang ini. Silakan buka shift kasir terlebih dahulu sebelum memulai transaksi.',
                    'shift_error' => true,
                ], 422);
            }

            // Gunakan tanggal real saat transaksi terjadi (real calendar date)
            $shiftDate = (!empty($clientOrderId) && !empty($data['date'])) ? $data['date'] : now()->toDateString();

            // Pre-validate recipes & resolve modifiers for all items
            $preparedItems = [];
            $orderTotal = 0;

            $paymentMethod = strtoupper($data['payment_method'] ?? 'CASH');
            $isOnlinePayment = in_array($paymentMethod, ['GRAB', 'GRABFOOD', 'GOFOOD', 'GOJEK', 'SHOPEEFOOD', 'SHOPEE', 'APLIKASI']);

            foreach ($data['items'] as $it) {
                $menu = Menu::findOrFail($it['menu_id']);
                $recipe = $menu->activeRecipe($shiftDate);

                $options = $this->resolveModifierOptions($it);
                $modifierUnitPrice = (float)$options->sum('price');

                $basePrice = (float)$menu->price;
                if ($isOnlinePayment) {
                    $appPrice = null;
                    if (in_array($paymentMethod, ['GRAB', 'GRABFOOD'])) {
                        $appPrice = $menu->price_grab;
                    } elseif (in_array($paymentMethod, ['GOFOOD', 'GOJEK'])) {
                        $appPrice = $menu->price_gofood;
                    } elseif (in_array($paymentMethod, ['SHOPEEFOOD', 'SHOPEE'])) {
                        $appPrice = $menu->price_shopeefood;
                    }
                    if ($appPrice !== null && (float)$appPrice > 0) {
                        $basePrice = (float)$appPrice;
                    } elseif ($menu->price_online !== null && (float)$menu->price_online > 0) {
                        $basePrice = (float)$menu->price_online;
                    }
                }

                $om = OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                if ($om) {
                    if ($isOnlinePayment) {
                        $omAppPrice = null;
                        if (in_array($paymentMethod, ['GRAB', 'GRABFOOD'])) {
                            $omAppPrice = $om->price_grab;
                        } elseif (in_array($paymentMethod, ['GOFOOD', 'GOJEK'])) {
                            $omAppPrice = $om->price_gofood;
                        } elseif (in_array($paymentMethod, ['SHOPEEFOOD', 'SHOPEE'])) {
                            $omAppPrice = $om->price_shopeefood;
                        }
                        if ($omAppPrice !== null && (float)$omAppPrice > 0) {
                            $basePrice = (float)$omAppPrice;
                        } elseif ($om->price_online !== null && (float)$om->price_online > 0) {
                            $basePrice = (float)$om->price_online;
                        }
                    } elseif ($om->price !== null && (float)$om->price > 0) {
                        $basePrice = (float)$om->price;
                    }
                }

                if (isset($it['price']) && $it['price'] !== null && (float)$it['price'] > 0) {
                    $basePrice = (float)$it['price'];
                }

                $itemUnitPrice = $basePrice + $modifierUnitPrice;
                $reqQty = (int)$it['qty'];

                $isExplicitUrgent = isset($it['is_urgent']) ? (bool)$it['is_urgent'] : false;

                // Check stock for DIRECT products if not explicitly marked urgent
                $avail = null;
                if (!$isExplicitUrgent && $menu->item_type === 'DIRECT' && $menu->track_stock) {
                    $avail = (int)$menu->stockForOutlet($outletId);
                }

                if ($avail !== null && $avail > 0 && $reqQty > $avail) {
                    // Auto-split: available portions are normal, excess portions are urgent
                    $normalQty = $avail;
                    $urgentQty = $reqQty - $avail;

                    // 1. Normal portion
                    $itemTotalNormal = $itemUnitPrice * $normalQty;
                    $orderTotal += $itemTotalNormal;
                    $preparedItems[] = [
                        'menu'           => $menu,
                        'recipe'         => $recipe,
                        'qty'            => $normalQty,
                        'item_notes'     => $it['notes'] ?? null,
                        'is_urgent'      => false,
                        'item_total'     => $itemTotalNormal,
                        'options'        => $options,
                        'modifier_extra' => $modifierUnitPrice,
                    ];

                    // 2. Urgent portion
                    $itemTotalUrgent = $itemUnitPrice * $urgentQty;
                    $orderTotal += $itemTotalUrgent;
                    $preparedItems[] = [
                        'menu'           => $menu,
                        'recipe'         => $recipe,
                        'qty'            => $urgentQty,
                        'item_notes'     => !empty($it['notes']) ? ($it['notes'] . ' (Urgent Defisit)') : 'Urgent Defisit',
                        'is_urgent'      => true,
                        'item_total'     => $itemTotalUrgent,
                        'options'        => $options,
                        'modifier_extra' => $modifierUnitPrice,
                    ];
                } else {
                    $itemTotal = $itemUnitPrice * $reqQty;
                    $orderTotal += $itemTotal;

                    $preparedItems[] = [
                        'menu'           => $menu,
                        'recipe'         => $recipe,
                        'qty'            => $reqQty,
                        'item_notes'     => $it['notes'] ?? null,
                        'is_urgent'      => isset($it['is_urgent']) ? (bool)$it['is_urgent'] : $isUrgentOrder,
                        'item_total'     => $itemTotal,
                        'options'        => $options,
                        'modifier_extra' => $modifierUnitPrice,
                    ];
                }
            }

            // Calculate Gross Subtotal & Resolve Discount
            $orderGrossSubtotal = $orderTotal;
            $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
            $discInfo = $this->resolveDiscountDetails(
                $data,
                $orderGrossSubtotal,
                $outletId,
                $shiftDate,
                (int)($request->user()->business_id ?: (Outlet::find($outletId)?->business_id ?: 1)),
                $customerId
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

            $orderNumber = null;
            $createdTransactions = DB::transaction(function () use (
                $preparedItems, $data, &$orderNumber, $orderStatus, $shiftId, $shiftDate, $outletId, $businessId, $request, $discInfo, $isUrgentOrder, $customerId, $orderNetTotal, $clientOrderId
            ) {
                // Thread-safe atomic order number generation inside active database transaction
                $orderNumber = OrderNumberService::generateNextOrderNumber($businessId, $shiftDate);
                $businessObj = \App\Models\Business::find($businessId);
                $isUnlimited = (bool)($businessObj && $businessObj->is_unlimited);
                $results = [];

                foreach ($preparedItems as $prep) {
                    $menu   = $prep['menu'];
                    $recipe = $prep['recipe'];
                    $qty    = $prep['qty'];

                    $trx = Transaction::create([
                        'business_id'       => $businessId,
                        'is_unlimited_mode' => $isUnlimited,
                        'order_number'      => $orderNumber,
                        'client_order_id'   => $clientOrderId,
                        'status'            => $orderStatus,
                        'date'              => $shiftDate,
                        'menu_id'           => $menu->id,
                        'qty'               => $qty,
                        'recipe_version'    => $recipe?->version ?? null,
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
                        'customer_id'     => $customerId,
                        'order_type'      => $data['order_type'] ?? 'DINE_IN',
                        'table_number'    => $data['table_number'] ?? null,
                        'is_open_bill'    => ($orderStatus === 'HOLD'),
                        'payment_method'  => $data['payment_method'] ?? 'CASH',
                        'dp_payment_method'=> (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($data['amount_paid'] ?? 0) > 0) ? ($data['dp_payment_method'] ?? 'CASH') : null,
                        'dp_reference_no'  => (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($data['amount_paid'] ?? 0) > 0) ? ($data['dp_reference_no'] ?? null) : null,
                        'is_urgent_note'  => !empty($prep['is_urgent']),
                        'urgent_status'   => !empty($prep['is_urgent']) ? 'PENDING' : 'NONE',
                        'notes'           => $prep['item_notes'] ?: ($data['notes'] ?? null),
                        'user_id'         => $request->user()->id,
                        'created_by'      => $request->user()->id,
                        'shift_id'        => $shiftId,
                        'outlet_id'       => $outletId,
                    ]);

                    // Save selected modifiers snapshot & deduct modifier ingredients
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

                        if ($opt->ingredient_id && $opt->qty > 0 && $opt->ingredient && !$isUnlimited) {
                            $requiredModQty = (float)($opt->qty * $qty);
                            $this->processIngredientStockDeduction(
                                $businessId,
                                $outletId,
                                $trx,
                                $orderNumber,
                                $shiftDate,
                                $opt->ingredient,
                                $requiredModQty,
                                "Modifier {$opt->name} ({$menu->name} x{$qty})",
                                'MODIFIER',
                                $menu->id,
                                !empty($shiftId),
                                in_array($orderStatus, ['PAID', 'HOLD']),
                                $request->user()->id,
                                $opt->unit
                            );
                        }
                    }

                    // Deduct stock for DIRECT retail items (both PAID and Open Bill HOLD)
                    if (in_array($orderStatus, ['PAID', 'HOLD'])) {
                        $this->processDirectProductStockDeduction(
                            $businessId,
                            $outletId,
                            $trx,
                            $orderNumber,
                            $menu,
                            $qty,
                            $request->user()->id
                        );
                    }

                    // Deduct recipe ingredients with Urgent Note support (both PAID and Open Bill HOLD)
                    // In Mode Unlimited: resep & gramasi dikunci sehingga bahan baku tidak dipotong untuk menu resep
                    if ($recipe && !$isUnlimited) {
                        foreach ($recipe->items as $item) {
                            if ($item->ingredient) {
                                $requiredIngQty = (float)($item->qty * $qty);
                                $this->processIngredientStockDeduction(
                                    $businessId,
                                    $outletId,
                                    $trx,
                                    $orderNumber,
                                    $shiftDate,
                                    $item->ingredient,
                                    $requiredIngQty,
                                    "{$menu->name} (x{$qty})",
                                    'RECIPE',
                                    $menu->id,
                                    !empty($shiftId),
                                    in_array($orderStatus, ['PAID', 'HOLD']),
                                    $request->user()->id,
                                    $item->unit
                                );
                            }
                        }
                    }

                    // Deduct stock for BUNDLE / Promo items (multi-component package / Buy 1 Get 1)
                    if ($menu->item_type === 'BUNDLE' || $menu->bundleItems->count() > 0) {
                        foreach ($menu->bundleItems as $bi) {
                            $bundledQty = (float)($bi->qty * $qty);

                            // 1. Bundled Menu (Recipe or Direct)
                            if ($bi->bundledMenu) {
                                $bm = $bi->bundledMenu;
                                if ($bm->item_type === 'DIRECT' && in_array($orderStatus, ['PAID', 'HOLD'])) {
                                    $this->processDirectProductStockDeduction(
                                        $businessId,
                                        $outletId,
                                        $trx,
                                        $orderNumber,
                                        $bm,
                                        (int)ceil($bundledQty),
                                        $request->user()->id
                                    );
                                }

                                $bmRecipe = $bm->activeRecipe($shiftDate);
                                if ($bmRecipe && !$isUnlimited) {
                                    foreach ($bmRecipe->items as $item) {
                                        if ($item->ingredient) {
                                            $this->processIngredientStockDeduction(
                                                $businessId,
                                                $outletId,
                                                $trx,
                                                $orderNumber,
                                                $shiftDate,
                                                $item->ingredient,
                                                (float)($item->qty * $bundledQty),
                                                "Bundling {$menu->name} (x{$qty}) → {$bm->name}",
                                                'BUNDLE',
                                                $bm->id,
                                                !empty($shiftId),
                                                in_array($orderStatus, ['PAID', 'HOLD']),
                                                $request->user()->id,
                                                $item->unit
                                            );
                                        }
                                    }
                                }
                            }

                            // 2. Direct Ingredient in Bundle
                            if ($bi->ingredient_id && $bi->ingredient && !$isUnlimited) {
                                $this->processIngredientStockDeduction(
                                    $businessId,
                                    $outletId,
                                    $trx,
                                    $orderNumber,
                                    $shiftDate,
                                    $bi->ingredient,
                                    (float)($bi->qty * $qty),
                                    "Bundling {$menu->name} (x{$qty}) → {$bi->ingredient->name}",
                                    'BUNDLE',
                                    $menu->id,
                                    !empty($shiftId),
                                    in_array($orderStatus, ['PAID', 'HOLD']),
                                    $request->user()->id,
                                    $bi->unit
                                );
                            }
                        }
                    }

                    $trx->load(['menu', 'modifiers.ingredient', 'discount', 'urgentNotes.ingredient']);
                    $results[] = $trx;
                }

                // Increment discount usage if PAID and valid discount model
                if ($orderStatus === 'PAID' && $discInfo['model']) {
                    $discInfo['model']->increment('used_count');
                }

                // Member Point System: Add +1 point per PAID transaction and handle point promo redemption
                if ($orderStatus === 'PAID' && $customerId) {
                    $customer = Customer::find($customerId);
                    if ($customer) {
                        $customer->recordVisit($orderNetTotal, 1);

                        // If promo requires member points, deduct points and log redemption
                        if (!empty($discInfo['model']) && (int)($discInfo['model']->requires_points ?? 0) > 0) {
                            $pts = (int)$discInfo['model']->requires_points;
                            $customer->usePoints($pts);
                            PointRedemption::create([
                                'business_id'  => $businessId,
                                'customer_id'  => $customer->id,
                                'discount_id'  => $discInfo['model']->id,
                                'order_number' => $orderNumber,
                                'points_used'  => $pts,
                                'description'  => "Penukaran {$pts} poin promo '{$discInfo['model']->name}' pada nota {$orderNumber}",
                                'created_by'   => $request->user()->id,
                            ]);
                        }
                    }
                }

                // Auto-create Kasbon / Receivable record if payment method is KASBON or PIUTANG
                if (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG'])) {
                    $custName = $data['customer_name'] ?? 'Pelanggan Kasbon';
                    $custPhone = null;
                    $custAddr = null;

                    if ($customerId) {
                        $cObj = \App\Models\Customer::find($customerId);
                        if ($cObj) {
                            $custName = $cObj->name;
                            $custPhone = $cObj->phone;
                            $custAddr = $cObj->address;
                        }
                    }

                    $dueDate = !empty($data['due_date']) ? $data['due_date'] : now()->addDays(7)->toDateString();
                    $amountPaidNow = min((float)($data['amount_paid'] ?? 0), $orderNetTotal);
                    $kasbonTotal = max(0, $orderNetTotal - $amountPaidNow);
                    $recStatus = ($kasbonTotal <= 0) ? 'PAID' : (($amountPaidNow > 0) ? 'PARTIAL' : 'UNPAID');

                    $rec = \App\Models\Receivable::create([
                        'receivable_no'    => \App\Models\Receivable::generateReceivableNo($businessId, $shiftDate),
                        'business_id'      => $businessId,
                        'outlet_id'        => $outletId,
                        'shift_id'         => $shiftId,
                        'transaction_id'   => $results[0]->id ?? null,
                        'customer_id'      => $customerId,
                        'order_number'     => $orderNumber,
                        'customer_name'    => $custName,
                        'customer_phone'   => $custPhone,
                        'customer_address' => $custAddr,
                        'issue_date'       => $shiftDate,
                        'due_date'         => $dueDate,
                        'total_amount'     => $orderNetTotal,
                        'paid_amount'      => $amountPaidNow,
                        'remaining_amount' => $kasbonTotal,
                        'status'           => $recStatus,
                        'notes'            => 'Kasbon POS Kasir — Order #' . $orderNumber,
                        'created_by'       => $request->user()->id,
                    ]);

                    if ($amountPaidNow > 0) {
                        $dpMethod = strtoupper(trim($data['dp_payment_method'] ?? 'CASH'));
                        $dpRefNo = $data['dp_reference_no'] ?? null;
                        $dpPay = \App\Models\ReceivablePayment::create([
                            'payment_no'     => \App\Models\ReceivablePayment::generatePaymentNo($businessId, $shiftDate),
                            'receivable_id'  => $rec->id,
                            'business_id'    => $businessId,
                            'outlet_id'      => $outletId,
                            'shift_id'       => $shiftId,
                            'payment_date'   => $shiftDate,
                            'amount'         => $amountPaidNow,
                            'payment_method' => $dpMethod,
                            'reference_no'   => $dpRefNo,
                            'notes'          => 'Uang Muka / DP Kasir Saat Checkout (' . $dpMethod . ($dpRefNo ? " - {$dpRefNo}" : '') . ')',
                            'received_by'    => $request->user()->id,
                        ]);
                        \App\Services\AccountingService::postReceivablePayment($dpPay);
                    }
                }

                // Auto-create AR Merchant (QRIS or E-commerce) record
                $pmUpper = strtoupper($data['payment_method'] ?? '');
                $isQris = str_contains($pmUpper, 'QRIS');
                $isEcommerce = in_array($pmUpper, ['GRAB', 'GRABFOOD', 'GOFOOD', 'SHOPEEFOOD', 'ECOMMERCE', 'TIKTOK', 'TOKOPEDIA', 'DELIVERY', 'ONLINE']) ||
                               str_contains($pmUpper, 'GRAB') ||
                               str_contains($pmUpper, 'GOFOOD') ||
                               str_contains($pmUpper, 'SHOPEE');

                if ($orderStatus === 'PAID' && ($isQris || $isEcommerce)) {
                    $arType = $isQris ? 'MERCHANT_QRIS' : 'MERCHANT_ECOMMERCE';
                    $channelName = $isQris ? 'QRIS' : ($pmUpper ?: 'E-COMMERCE');
                    $mdrRate = $isQris ? 0.7 : 20.0;
                    $mdrFee = round(($orderNetTotal * $mdrRate) / 100, 2);
                    $netReceivable = round($orderNetTotal - $mdrFee, 2);

                    \App\Models\Receivable::create([
                        'receivable_no'     => \App\Models\Receivable::generateReceivableNo($businessId, $shiftDate),
                        'business_id'       => $businessId,
                        'outlet_id'         => $outletId,
                        'transaction_id'    => $results[0]->id ?? null,
                        'customer_id'       => $customerId,
                        'ar_type'           => $arType,
                        'merchant_channel'  => $channelName,
                        'order_number'      => $orderNumber,
                        'customer_name'     => "AR Merchant - {$channelName}",
                        'customer_phone'    => null,
                        'customer_address'  => null,
                        'issue_date'        => $shiftDate,
                        'due_date'          => $shiftDate,
                        'total_amount'      => $orderNetTotal,
                        'paid_amount'       => 0,
                        'remaining_amount'  => $netReceivable,
                        'mdr_rate'          => $mdrRate,
                        'mdr_fee'           => $mdrFee,
                        'net_amount'        => $netReceivable,
                        'status'            => 'UNPAID',
                        'settlement_status' => 'UNSETTLED',
                        'notes'             => "AR Merchant {$channelName} POS — Order #{$orderNumber}",
                        'created_by'        => $request->user()->id,
                    ]);
                }

                // Deduct SaaS platform coins for completed nota
                if ($orderStatus === 'PAID') {
                    CoinService::deductForOrder($businessId, $orderNumber, $outletId, $request->user()->id);
                }

                // Auto-post double-entry journal ledger inside the database transaction
                foreach ($results as $cTrx) {
                    AccountingService::postTransaction($cTrx);
                }

                return $results;
            });

            $targetOutlet = Outlet::with('business')->find($outletId);

            return response()->json([
                'order_number'    => $orderNumber,
                'status'          => $orderStatus,
                'business_name'   => $targetOutlet?->business?->name ?? 'MOVA POS',
                'outlet_name'     => $targetOutlet?->name ?? 'Outlet',
                'outlet_address'  => $targetOutlet?->address,
                'outlet_phone'    => $targetOutlet?->phone,
                'date'            => $shiftDate,
                'customer_name'   => $data['customer_name'] ?? null,
                'customer_id'     => $customerId,
                'customer'        => $customerId ? Customer::find($customerId) : null,
                'order_type'      => $data['order_type'] ?? 'DINE_IN',
                'table_number'    => $data['table_number'] ?? null,
                'payment_method'  => $data['payment_method'] ?? 'CASH',
                'dp_payment_method'=> (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($data['amount_paid'] ?? 0) > 0) ? ($data['dp_payment_method'] ?? 'CASH') : null,
                'dp_reference_no'  => (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($data['amount_paid'] ?? 0) > 0) ? ($data['dp_reference_no'] ?? null) : null,
                'subtotal'        => $orderGrossSubtotal,
                'discount_id'     => $discInfo['discount_id'],
                'discount_name'   => $discInfo['discount_name'],
                'discount_type'   => $discInfo['discount_type'],
                'discount_rate'   => $discInfo['discount_rate'],
                'discount_amount' => $totalDiscountAmount,
                'total_price'     => $orderNetTotal,
                'amount_paid'     => (float)($data['amount_paid'] ?? ($orderStatus === 'HOLD' ? 0 : $orderNetTotal)),
                'change_amount'   => (float)($data['change_amount'] ?? 0),
                'is_urgent_note'  => (bool)($createdTransactions[0]?->is_urgent_note ?? false),
                'urgent_status'   => $createdTransactions[0]?->urgent_status ?? 'NONE',
                'urgent_notes'    => UrgentNote::where('order_number', $orderNumber)->get(),
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
                    'is_urgent_note'  => (bool)$t->is_urgent_note,
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
            'is_urgent_note'              => 'nullable|boolean',
            'date'                        => 'required|date',
            'shift_id'                    => 'nullable',
            'outlet_id'                   => 'nullable|exists:outlets,id',
            'customer_name'               => 'nullable|string|max:100',
            'customer_id'                 => 'nullable|exists:customers,id',
            'order_type'                  => 'nullable|string|in:DINE_IN,TAKEAWAY,DELIVERY',
            'table_number'                => 'nullable|string|max:50',
            'payment_method'              => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,KASBON,PIUTANG,OTHER,APLIKASI',
            'dp_payment_method'           => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,OTHER',
            'dp_reference_no'             => 'nullable|string|max:100',
            'amount_paid'                 => 'nullable|numeric|min:0',
            'change_amount'               => 'nullable|numeric|min:0',
            'notes'                       => 'nullable|string|max:500',
            'client_order_id'             => 'nullable|string|max:100',
            'modifier_option_ids'         => 'nullable|array',
            'modifier_option_ids.*'       => 'exists:modifier_options,id',
            'modifiers'                   => 'nullable|array',
        ]);

        $orderStatus = $data['status'] ?? 'PAID';
        $isUrgentOrder = !empty($data['is_urgent_note']);
        $menu   = Menu::findOrFail($data['menu_id']);
        $recipe = $menu->activeRecipe($data['date']);

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = $data['outlet_id'] ?? $user?->outlet_id ?? null;
        }
        $businessId = (int)($user?->business_id ?: ($outletId ? \App\Models\Outlet::find($outletId)?->business_id : 1) ?: 1);

        if (!$outletId || $outletId === 'ALL' || $outletId === 'all') {
            $outletId = \App\Models\Outlet::where('business_id', $businessId)->where('is_main', true)->value('id')
                     ?? \App\Models\Outlet::where('business_id', $businessId)->value('id')
                     ?? 1;
        }

        // Idempotency check for single-item order
        $clientOrderId = $data['client_order_id'] ?? $request->input('client_order_id');
        if (!$clientOrderId && !empty($data['notes'])) {
            if (preg_match('/\[Offline ID:\s*([^\]]+)\]/i', $data['notes'], $matches)) {
                $clientOrderId = trim($matches[1]);
            }
        }
        if (!empty($clientOrderId)) {
            $existingTx = Transaction::where('business_id', $businessId)
                ->where(function ($q) use ($clientOrderId) {
                    $q->where('client_order_id', $clientOrderId)
                      ->orWhere('notes', 'like', "%[Offline ID: {$clientOrderId}]%");
                })
                ->first();

            if ($existingTx) {
                return response()->json([
                    'message'            => "Transaksi offline [{$clientOrderId}] sudah tercatat sebelumnya (idempotent).",
                    'order_number'       => $existingTx->order_number,
                    'is_idempotent_sync' => true,
                    'transaction'        => $existingTx,
                ], 200);
            }
        }

        // Pre-check SaaS coin balance if order is finalized as PAID
        if ($orderStatus === 'PAID') {
            $coinCheck = CoinService::canTransact($businessId);
            if (!$coinCheck['allowed']) {
                return response()->json([
                    'message'    => $coinCheck['message'],
                    'coin_error' => true,
                    'coin_data'  => $coinCheck,
                ], 402);
            }
        }

        // Resolve Active Shift & Effective Operational Date
        $user = $request->user();
        $isOwner = $user && ($user->isOwnerBisnis() || $user->isOwnerWebsite() || $user->isPlatformAdmin() || $user->isOwnerOutlet());

        $shiftId = $data['shift_id'] ?? null;
        $activeShift = null;
        if ($shiftId) {
            if (is_numeric($shiftId)) {
                $activeShift = \App\Models\Shift::find($shiftId);
            } else {
                $activeShift = \App\Models\Shift::where('business_id', $businessId)
                    ->where('client_shift_id', $shiftId)
                    ->first();
            }
        }
        if (!$activeShift) {
            $activeShift = \App\Models\Shift::where('status', 'OPEN')->where('outlet_id', $outletId)->orderByDesc('opened_at')->first();
            if (!$activeShift) {
                $activeShift = \App\Models\Shift::where('status', 'OPEN')->orderByDesc('opened_at')->first();
            }
        }
        $shiftId = $activeShift?->id;

        // Dilarang melakukan transaksi kalau belum buka shift, KECUALI Owner
        if (!$isOwner && (!$shiftId || !$activeShift)) {
            return response()->json([
                'message' => 'Shift kasir belum dibuka untuk cabang ini. Silakan buka shift kasir terlebih dahulu sebelum memulai transaksi.',
                'shift_error' => true,
            ], 422);
        }

        // Gunakan tanggal real saat transaksi terjadi (real calendar date)
        $shiftDate = (!empty($clientOrderId) && !empty($data['date'])) ? $data['date'] : now()->toDateString();

        // Pre-resolve recipe and pricing
        $recipe = $menu->activeRecipe($shiftDate);

        $options = $this->resolveModifierOptions($data);
        $modifierUnitPrice = (float)$options->sum('price');
        $itemUnitPrice = (float)$menu->price + $modifierUnitPrice;
        $totalItemPrice = $itemUnitPrice * (int)$data['qty'];

        $trx = DB::transaction(function () use ($data, $menu, $recipe, $options, $totalItemPrice, $request, $shiftId, $shiftDate, $outletId, $businessId, $orderStatus, $isUrgentOrder, $clientOrderId) {
            // Thread-safe atomic order number generation inside active transaction
            $orderNumber = OrderNumberService::generateNextOrderNumber($businessId, $shiftDate);
            $businessObj = \App\Models\Business::find($businessId);
            $isUnlimited = (bool)($businessObj && $businessObj->is_unlimited);
            $qty = (int)$data['qty'];
            $trx = Transaction::create([
                'business_id'       => $businessId,
                'is_unlimited_mode' => $isUnlimited,
                'order_number'      => $orderNumber,
                'client_order_id'   => $clientOrderId,
                'status'            => $orderStatus,
                'date'              => $shiftDate,
                'menu_id'           => $menu->id,
                'qty'               => $qty,
                'recipe_version'    => $recipe?->version ?? null,
                'total_price'       => $totalItemPrice,
                'amount_paid'       => $orderStatus === 'HOLD' ? 0 : ($data['amount_paid'] ?? null),
                'change_amount'     => $orderStatus === 'HOLD' ? 0 : ($data['change_amount'] ?? null),
                'customer_name'     => $data['customer_name'] ?? null,
                'customer_id'       => $data['customer_id'] ?? null,
                'order_type'        => $data['order_type'] ?? 'DINE_IN',
                'table_number'      => $data['table_number'] ?? null,
                'payment_method'    => $data['payment_method'] ?? 'CASH',
                'dp_payment_method' => (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($data['amount_paid'] ?? 0) > 0) ? ($data['dp_payment_method'] ?? 'CASH') : null,
                'dp_reference_no'   => (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($data['amount_paid'] ?? 0) > 0) ? ($data['dp_reference_no'] ?? null) : null,
                'is_urgent_note'    => $isUrgentOrder,
                'urgent_status'     => $isUrgentOrder ? 'PENDING' : 'NONE',
                'notes'             => $data['notes'] ?? null,
                'user_id'           => $request->user()->id,
                'created_by'        => $request->user()->id,
                'shift_id'          => $shiftId,
                'outlet_id'         => $outletId,
            ]);

            // Save selected modifiers & deduct modifier stock
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

                if ($opt->ingredient_id && $opt->qty > 0 && $opt->ingredient && !$isUnlimited) {
                    $requiredModQty = (float)($opt->qty * $qty);
                    $this->processIngredientStockDeduction(
                        $businessId,
                        $outletId,
                        $trx,
                        $orderNumber,
                        $shiftDate,
                        $opt->ingredient,
                        $requiredModQty,
                        "Modifier {$opt->name} ({$menu->name} x{$qty})",
                        'MODIFIER',
                        $menu->id,
                        !empty($shiftId),
                        $orderStatus === 'PAID',
                        $request->user()->id,
                        $opt->unit
                    );
                }
            }

            // Deduct stock for DIRECT retail items
            if ($orderStatus === 'PAID') {
                $this->processDirectProductStockDeduction(
                    $businessId,
                    $outletId,
                    $trx,
                    $orderNumber,
                    $menu,
                    $qty,
                    $request->user()->id
                );
            }

            // Deduct recipe ingredients with Urgent Note support
            if ($recipe && !$isUnlimited) {
                foreach ($recipe->items as $item) {
                    if ($item->ingredient) {
                        $requiredIngQty = (float)($item->qty * $qty);
                        $this->processIngredientStockDeduction(
                            $businessId,
                            $outletId,
                            $trx,
                            $orderNumber,
                            $shiftDate,
                            $item->ingredient,
                            $requiredIngQty,
                            "{$menu->name} (x{$qty})",
                            'RECIPE',
                            $menu->id,
                            !empty($shiftId),
                            $orderStatus === 'PAID',
                            $request->user()->id,
                            $item->unit
                        );
                    }
                }
            }

            // Point system for single-item
            if ($orderStatus === 'PAID' && !empty($data['customer_id'])) {
                $customer = Customer::find($data['customer_id']);
                if ($customer) {
                    $customer->recordVisit($totalItemPrice, 1);
                }
            }

            // Deduct SaaS platform coins for completed nota
            if ($orderStatus === 'PAID') {
                CoinService::deductForOrder($businessId, $orderNumber, $outletId, $request->user()->id);
            }

            // Auto-post double-entry journal ledger inside the database transaction
            AccountingService::postTransaction($trx);

            return $trx;
        });

        $trx->load(['menu', 'modifiers.ingredient', 'urgentNotes.ingredient']);
        return response()->json($trx, 201);
    }

    /**
     * Get all active Open Bills / Hold Orders.
     */
    public function openBills(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }
        $query = Transaction::with(['menu', 'user', 'outlet', 'modifiers.ingredient', 'urgentNotes.ingredient'])
            ->where('status', 'HOLD')
            ->orderBy('created_at', 'asc');

        if ($user && $user->business_id) {
            $query->where('business_id', $user->business_id);
        }

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
            if ($t->is_urgent_note) {
                $grouped[$ord]['is_urgent_note'] = true;
            }
            $grouped[$ord]['items'][] = [
                'id'              => $t->id,
                'menu_id'         => $t->menu_id,
                'menu_name'       => $t->menu?->name ?? 'Menu',
                'price'           => $price,
                'qty'             => (int)$t->qty,
                'subtotal'        => $itemSubtotal,
                'discount_amount' => $itemDiscount,
                'total_price'     => (float)$t->total_price,
                'is_urgent_note'  => (bool)$t->is_urgent_note,
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
                'urgent_notes'    => $t->urgentNotes,
            ];
        }

        // Enhance each open bill with equal split payment progress if any (Single batch query)
        $orderNumbersList = array_keys($grouped);
        $allPaidSplitsGrouped = !empty($orderNumbersList)
            ? Transaction::whereIn('parent_order_number', $orderNumbersList)
                ->where('split_type', 'EQUAL')
                ->where('status', 'PAID')
                ->get()
                ->groupBy('parent_order_number')
            : collect();

        foreach ($grouped as $ord => &$bill) {
            $paidSplits = $allPaidSplitsGrouped->get($ord, collect());

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
            'payment_method'    => 'required|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,KASBON,PIUTANG,OTHER,APLIKASI',
            'dp_payment_method' => 'nullable|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,OTHER',
            'dp_reference_no'   => 'nullable|string|max:100',
            'amount_paid'       => 'required|numeric|min:0',
            'change_amount'   => 'nullable|numeric|min:0',
            'customer_id'     => 'nullable|exists:customers,id',
            'notes'           => 'nullable|string|max:500',
            'discount_id'     => 'nullable|exists:discounts,id',
            'discount_code'   => 'nullable|string|max:50',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_name'   => 'nullable|string|max:100',
            'discount_type'   => 'nullable|string|max:30',
            'discount_rate'   => 'nullable|numeric|min:0',
        ]);

        $transactions = Transaction::with(['menu.recipes.items.ingredient', 'modifiers.ingredient', 'outlet', 'urgentNotes', 'customer'])
            ->where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Tagihan terbuka dengan nomor '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $firstTrx = $transactions->first();
            if ($firstTrx && (int)$firstTrx->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk memproses tagihan di cabang outlet lain.'
                ], 403);
            }
        }

        $first = $transactions->first();
        $outletId = $first->outlet_id;
        $businessId = (int)($request->user()->business_id ?: ($first->business_id ?: ($first->outlet?->business_id ?: 1)));

        // Pre-check SaaS coin balance
        $coinCheck = CoinService::canTransact($businessId);
        if (!$coinCheck['allowed']) {
            return response()->json([
                'message'    => $coinCheck['message'],
                'coin_error' => true,
                'coin_data'  => $coinCheck,
            ], 402);
        }

        $user = $request->user();
        $isOwner = $user && ($user->isOwnerBisnis() || $user->isOwnerWebsite() || $user->isPlatformAdmin() || $user->isOwnerOutlet());

        $activeShift = \App\Models\Shift::where('status', 'OPEN')->where('outlet_id', $outletId)->orderByDesc('opened_at')->first();
        if (!$activeShift) {
            $activeShift = \App\Models\Shift::where('status', 'OPEN')->orderByDesc('opened_at')->first();
        }
        $shiftId = $activeShift?->id;

        // Dilarang melakukan transaksi kalau belum buka shift, KECUALI Owner
        if (!$isOwner && (!$shiftId || !$activeShift)) {
            return response()->json([
                'message' => 'Shift kasir belum dibuka untuk cabang ini. Silakan buka shift kasir terlebih dahulu sebelum memproses pembayaran.',
                'shift_error' => true,
            ], 422);
        }

        // Gunakan tanggal real saat transaksi diselesaikan
        $date = now()->toDateString();

        $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : ($first->customer_id ?? null);
        $grossSubtotal = (float)$transactions->sum(fn($t) => (float)($t->subtotal ?: $t->total_price));
        $discInfo = $this->resolveDiscountDetails($data, $grossSubtotal, $outletId, $date, $businessId, $customerId);
        $totalDiscount = $discInfo['discount_amount'];
        $totalOrder = max(0, $grossSubtotal - $totalDiscount);

        $amountPaid = (float)$data['amount_paid'];
        if ($amountPaid < $totalOrder && $data['payment_method'] === 'CASH') {
            return response()->json([
                'message' => "Jumlah pembayaran kurang dari total tagihan."
            ], 422);
        }

        $changeAmount = (float)($data['change_amount'] ?? max(0, $amountPaid - $totalOrder));

        DB::transaction(function () use ($transactions, $data, $shiftId, $discInfo, $grossSubtotal, $amountPaid, $changeAmount, $date, $outletId, $businessId, $orderNumber, $request, $customerId, $totalOrder) {
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

                $t->shift_id = $shiftId;
                $t->date = $date;
                $t->user_id = $request->user()->id;
                $t->is_open_bill = true;
                $t->status = 'PAID';
                $t->customer_id = $customerId;
                $t->payment_method = $data['payment_method'];
                $isKasbon = in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']);
                $t->dp_payment_method = ($isKasbon && floatval($amountPaid ?? 0) > 0) ? ($data['dp_payment_method'] ?? 'CASH') : null;
                $t->dp_reference_no   = ($isKasbon && floatval($amountPaid ?? 0) > 0) ? ($data['dp_reference_no'] ?? null) : null;
                $t->amount_paid = $amountPaid;
                $t->change_amount = $changeAmount;
                $t->notes = !empty($data['notes']) ? $data['notes'] : $t->notes;
                $t->updated_by = $request->user()->id;
                $t->save();

                // Only deduct stock if not already deducted at open bill creation (prevents double stock deduction)
                // Di Mode Unlimited: stok tidak dipotong
                $alreadyDeducted = StockMovement::where('transaction_id', $t->id)->exists();
                if (!$alreadyDeducted && !$t->is_unlimited_mode) {
                    // Deduct stock for DIRECT retail items
                    $menu = $t->menu;
                    if ($menu) {
                        $this->processDirectProductStockDeduction(
                            $businessId,
                            $outletId,
                            $t,
                            $orderNumber,
                            $menu,
                            $t->qty,
                            $request->user()->id
                        );
                    }

                    // Deduct recipe ingredients with Urgent Note support
                    $recipe = $menu?->activeRecipe($date);
                    if ($recipe) {
                        foreach ($recipe->items as $item) {
                            if ($item->ingredient) {
                                $requiredIngQty = (float)($item->qty * $t->qty);
                                $this->processIngredientStockDeduction(
                                    $businessId,
                                    $outletId,
                                    $t,
                                    $orderNumber,
                                    $date,
                                    $item->ingredient,
                                    $requiredIngQty,
                                    "{$menu->name} (x{$t->qty}) [Pelunasan Open Bill]",
                                    'RECIPE',
                                    $menu->id,
                                    !empty($shiftId),
                                    true,
                                    $request->user()->id,
                                    $item->unit
                                );
                            }
                        }
                    }

                    // Deduct modifier ingredients
                    foreach ($t->modifiers as $mod) {
                        if ($mod->ingredient_id && $mod->qty > 0 && $mod->ingredient) {
                            $requiredModQty = (float)($mod->qty * $t->qty);
                            $this->processIngredientStockDeduction(
                                $businessId,
                                $outletId,
                                $t,
                                $orderNumber,
                                $date,
                                $mod->ingredient,
                                $requiredModQty,
                                "Modifier {$mod->name} ({$menu->name} x{$t->qty}) [Pelunasan Open Bill]",
                                'MODIFIER',
                                $menu->id,
                                !empty($shiftId),
                                true,
                                $request->user()->id,
                                $mod->unit
                            );
                        }
                    }
                }
            }

            if ($discInfo && $discInfo['model']) {
                $discInfo['model']->increment('used_count');
            }

            // Member point earning and point redemption on open bill completion
            if ($customerId) {
                $customer = Customer::find($customerId);
                if ($customer) {
                    $customer->recordVisit($totalOrder, 1);
                    if ($discInfo && !empty($discInfo['model']) && (int)($discInfo['model']->requires_points ?? 0) > 0) {
                        $pts = (int)$discInfo['model']->requires_points;
                        $customer->usePoints($pts);
                        PointRedemption::create([
                            'business_id'  => $businessId,
                            'customer_id'  => $customer->id,
                            'discount_id'  => $discInfo['model']->id,
                            'order_number' => $orderNumber,
                            'points_used'  => $pts,
                            'description'  => "Penukaran {$pts} poin promo '{$discInfo['model']->name}' pada pelunasan tagihan {$orderNumber}",
                            'created_by'   => $request->user()->id,
                        ]);
                    }
                }
            }

            // Auto-create Kasbon / Receivable record if payment method is KASBON or PIUTANG
            if (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG'])) {
                $firstTrx = $transactions->first();
                $custName = $firstTrx->customer_name ?? 'Pelanggan Kasbon';
                $custPhone = null;
                $custAddr = null;

                if ($customerId) {
                    $cObj = \App\Models\Customer::find($customerId);
                    if ($cObj) {
                        $custName = $cObj->name;
                        $custPhone = $cObj->phone;
                        $custAddr = $cObj->address;
                    }
                }

                $dueDate = !empty($data['due_date']) ? $data['due_date'] : now()->addDays(7)->toDateString();
                $amountPaidNow = min((float)($data['amount_paid'] ?? 0), $totalOrder);
                $kasbonTotal = max(0, $totalOrder - $amountPaidNow);
                $recStatus = ($kasbonTotal <= 0) ? 'PAID' : (($amountPaidNow > 0) ? 'PARTIAL' : 'UNPAID');

                $rec = \App\Models\Receivable::create([
                    'receivable_no'    => \App\Models\Receivable::generateReceivableNo($businessId, $date),
                    'business_id'      => $businessId,
                    'outlet_id'        => $outletId,
                    'shift_id'         => $shiftId,
                    'transaction_id'   => $firstTrx->id ?? null,
                    'customer_id'      => $customerId,
                    'order_number'     => $orderNumber,
                    'customer_name'    => $custName,
                    'customer_phone'   => $custPhone,
                    'customer_address' => $custAddr,
                    'issue_date'       => $date,
                    'due_date'         => $dueDate,
                    'total_amount'     => $totalOrder,
                    'paid_amount'      => $amountPaidNow,
                    'remaining_amount' => $kasbonTotal,
                    'status'           => $recStatus,
                    'notes'            => 'Kasbon POS Kasir (Open Bill) — Order #' . $orderNumber,
                    'created_by'       => $request->user()->id,
                ]);

                if ($amountPaidNow > 0) {
                    $dpMethod = strtoupper(trim($data['dp_payment_method'] ?? 'CASH'));
                    $dpRefNo = $data['dp_reference_no'] ?? null;
                    $dpPay = \App\Models\ReceivablePayment::create([
                        'payment_no'     => \App\Models\ReceivablePayment::generatePaymentNo($businessId, $date),
                        'receivable_id'  => $rec->id,
                        'business_id'    => $businessId,
                        'outlet_id'      => $outletId,
                        'shift_id'       => $shiftId,
                        'payment_date'   => $date,
                        'amount'         => $amountPaidNow,
                        'payment_method' => $dpMethod,
                        'reference_no'   => $dpRefNo,
                        'notes'          => 'Uang Muka / DP Kasir Saat Checkout (' . $dpMethod . ($dpRefNo ? " - {$dpRefNo}" : '') . ')',
                        'received_by'    => $request->user()->id,
                    ]);
                    \App\Services\AccountingService::postReceivablePayment($dpPay);
                }
            }

            // Auto-create AR Merchant (QRIS or E-commerce) record
            $pmUpper = strtoupper($data['payment_method'] ?? '');
            $isQris = str_contains($pmUpper, 'QRIS');
            $isEcommerce = in_array($pmUpper, ['GRAB', 'GRABFOOD', 'GOFOOD', 'SHOPEEFOOD', 'ECOMMERCE', 'TIKTOK', 'TOKOPEDIA', 'DELIVERY', 'ONLINE']) ||
                           str_contains($pmUpper, 'GRAB') ||
                           str_contains($pmUpper, 'GOFOOD') ||
                           str_contains($pmUpper, 'SHOPEE');

            if ($isQris || $isEcommerce) {
                $firstTrx = $transactions->first();
                $arType = $isQris ? 'MERCHANT_QRIS' : 'MERCHANT_ECOMMERCE';
                $channelName = $isQris ? 'QRIS' : ($pmUpper ?: 'E-COMMERCE');
                $mdrRate = $isQris ? 0.7 : 20.0;
                $mdrFee = round(($totalOrder * $mdrRate) / 100, 2);
                $netReceivable = round($totalOrder - $mdrFee, 2);

                \App\Models\Receivable::create([
                    'receivable_no'     => \App\Models\Receivable::generateReceivableNo($businessId, $date),
                    'business_id'       => $businessId,
                    'outlet_id'         => $outletId,
                    'transaction_id'    => $firstTrx->id ?? null,
                    'customer_id'       => $customerId,
                    'ar_type'           => $arType,
                    'merchant_channel'  => $channelName,
                    'order_number'      => $orderNumber,
                    'customer_name'     => "AR Merchant - {$channelName}",
                    'customer_phone'    => null,
                    'customer_address'  => null,
                    'issue_date'        => $date,
                    'due_date'          => $date,
                    'total_amount'      => $totalOrder,
                    'paid_amount'       => 0,
                    'remaining_amount'  => $netReceivable,
                    'mdr_rate'          => $mdrRate,
                    'mdr_fee'           => $mdrFee,
                    'net_amount'        => $netReceivable,
                    'status'            => 'UNPAID',
                    'settlement_status' => 'UNSETTLED',
                    'notes'             => "AR Merchant {$channelName} POS — Order #{$orderNumber}",
                    'created_by'        => $request->user()->id,
                ]);
            }

            // Deduct SaaS platform coins for completed nota
            CoinService::deductForOrder($businessId, $orderNumber, $outletId, $request->user()->id);
        });

        // Auto-post double-entry journal ledger for settled open bill
        foreach ($transactions as $t) {
            AccountingService::postTransaction($t);
        }

        $first = $transactions->first();
        $payOutlet = $first?->outlet ? $first->outlet->loadMissing('business') : null;

        return response()->json([
            'message'        => 'Pembayaran tagihan berhasil diselesaikan.',
            'order_number'   => $orderNumber,
            'business_name'  => $payOutlet?->business?->name ?? 'MOVA POS',
            'outlet_name'    => $payOutlet?->name ?? 'Outlet',
            'outlet_address' => $payOutlet?->address,
            'outlet_phone'   => $payOutlet?->phone,
            'status'         => 'PAID',
            'table_number'   => $first->table_number,
            'customer_name'  => $first->customer_name,
            'customer_id'    => $customerId,
            'customer'       => $customerId ? Customer::find($customerId) : null,
            'payment_method' => $data['payment_method'],
            'dp_payment_method' => (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($amountPaid ?? 0) > 0) ? ($data['dp_payment_method'] ?? 'CASH') : null,
            'dp_reference_no'   => (in_array(strtoupper($data['payment_method'] ?? ''), ['KASBON', 'PIUTANG']) && floatval($amountPaid ?? 0) > 0) ? ($data['dp_reference_no'] ?? null) : null,
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

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$existing->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk mengubah tagihan di cabang outlet lain.'
                ], 403);
            }
        }

        $outletId = $existing->outlet_id;
        $activeShift = \App\Models\Shift::where('status', 'OPEN')->where('outlet_id', $outletId)->orderByDesc('opened_at')->first();
        if (!$activeShift) {
            $activeShift = \App\Models\Shift::find($existing->shift_id);
        }
        $shiftId = $activeShift ? $activeShift->id : $existing->shift_id;
        $date = now()->toDateString();
        $tableNumber = $existing->table_number;
        $customerName = $existing->customer_name;
        $orderType = $existing->order_type;
        $existingPaymentMethod = $existing->payment_method ?: 'CASH';

        $businessId = (int)($request->user()->business_id ?: ($existing->business_id ?: ($existing->outlet?->business_id ?: 1)));
        $businessObj = \App\Models\Business::find($businessId);
        $isUnlimited = (bool)($businessObj && $businessObj->is_unlimited);
        $addedTransactions = DB::transaction(function () use (
            $data, $orderNumber, $date, $shiftId, $outletId, $businessId, $tableNumber, $customerName, $orderType, $existingPaymentMethod, $request, $isUnlimited
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
                    'business_id'       => $businessId,
                    'is_unlimited_mode' => $isUnlimited,
                    'order_number'      => $orderNumber,
                    'status'            => 'HOLD',
                    'date'              => $date,
                    'menu_id'           => $menu->id,
                    'qty'               => $qty,
                    'recipe_version'    => $recipe?->version ?? null,
                    'total_price'       => $price * $qty,
                    'customer_name'     => $customerName,
                    'order_type'        => $orderType,
                    'table_number'      => $tableNumber,
                    'is_open_bill'      => true,
                    'payment_method'    => $existingPaymentMethod,
                    'amount_paid'       => 0,
                    'change_amount'     => 0,
                    'notes'             => $it['notes'] ?? null,
                    'user_id'           => $request->user()->id,
                    'created_by'        => $request->user()->id,
                    'shift_id'          => $shiftId,
                    'outlet_id'         => $outletId,
                ]);

                // Save selected modifiers & deduct modifier ingredients
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

                    if ($opt->ingredient_id && $opt->qty > 0 && $opt->ingredient && !$isUnlimited) {
                        $requiredModQty = (float)($opt->qty * $qty);
                        $this->processIngredientStockDeduction(
                            $businessId,
                            $outletId,
                            $trx,
                            $orderNumber,
                            $date,
                            $opt->ingredient,
                            $requiredModQty,
                            "Modifier {$opt->name} ({$menu->name} x{$qty}) [Item Tambahan Open Bill]",
                            'MODIFIER',
                            $menu->id,
                            !empty($shiftId),
                            true,
                            $request->user()->id,
                            $opt->unit
                        );
                    }
                }

                // Deduct stock for DIRECT retail items
                if ($menu->item_type === 'DIRECT') {
                    $this->processDirectProductStockDeduction(
                        $businessId,
                        $outletId,
                        $trx,
                        $orderNumber,
                        $menu,
                        $qty,
                        $request->user()->id
                    );
                }

                // Deduct recipe ingredients
                if ($recipe && !$isUnlimited) {
                    foreach ($recipe->items as $item) {
                        if ($item->ingredient) {
                            $requiredIngQty = (float)($item->qty * $qty);
                            $this->processIngredientStockDeduction(
                                $businessId,
                                $outletId,
                                $trx,
                                $orderNumber,
                                $date,
                                $item->ingredient,
                                $requiredIngQty,
                                "{$menu->name} (x{$qty}) [Item Tambahan Open Bill]",
                                'RECIPE',
                                $menu->id,
                                !empty($shiftId),
                                true,
                                $request->user()->id,
                                $item->unit
                            );
                        }
                    }
                }

                $trx->load(['menu', 'modifiers.ingredient', 'urgentNotes.ingredient']);
                $created[] = $trx;
            }
            return $created;
        });

        // Auto-post double-entry journal ledger for newly added open bill items
        foreach ($addedTransactions as $aTrx) {
            AccountingService::postTransaction($aTrx);
        }

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
     * Void an entire open bill (Batalkan 1 Nota Open Bill).
     * Supports:
     * - WRONG_INPUT (Salah Input): All items cancelled before cooking/production. No waste recorded.
     * - WASTED (Wasted / Rusak / Terbuang): Items were already prepared and wasted. Deducts ingredients/stock as WASTE and creates WasteLog.
     */
    public function cancelOpenBill(Request $request, $orderNumber)
    {
        $data = $request->validate([
            'void_type'           => 'nullable|string|in:WRONG_INPUT,WASTED',
            'waste_category'      => 'nullable|string|max:50',
            'reason'              => 'nullable|string|max:255',
            'supervisor_id'       => 'nullable|integer',
            'supervisor_password' => 'nullable|string|max:255',
            'supervisor_pin'      => 'nullable|string|max:50',
        ]);

        $transactions = Transaction::with(['menu', 'modifiers.ingredient', 'outlet'])
            ->where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Tagihan terbuka '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        $user = $request->user();
        $firstTrx = $transactions->first();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ($firstTrx && (int)$firstTrx->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk membatalkan tagihan di cabang outlet lain.'
                ], 403);
            }
        }

        $isManagerOrOwner = $user->isOwnerOrManager();
        $approver = $user;

        // If user is cashier/pegawai without owner/manager role, verify supervisor approval if provided
        if (!$isManagerOrOwner) {
            if (!empty($data['supervisor_id']) && (!empty($data['supervisor_password']) || !empty($data['supervisor_pin']))) {
                $supervisor = User::where('id', $data['supervisor_id'])
                    ->where('business_id', $firstTrx->business_id)
                    ->first();
                if (!$supervisor || !$supervisor->isOwnerOrManager()) {
                    return response()->json(['message' => 'Supervisor tidak valid atau tidak memiliki hak akses.'], 422);
                }
                $validPass = false;
                if (!empty($data['supervisor_password']) && Hash::check($data['supervisor_password'], $supervisor->password)) {
                    $validPass = true;
                }
                if (!$validPass && !empty($data['supervisor_pin']) && !empty($supervisor->pin) && Hash::check($data['supervisor_pin'], $supervisor->pin)) {
                    $validPass = true;
                }
                if (!$validPass) {
                    return response()->json(['message' => 'Password / PIN Supervisor tidak sesuai.'], 422);
                }
                $approver = $supervisor;
            }
        }

        $voidType = strtoupper($data['void_type'] ?? 'WRONG_INPUT') === 'WASTED' ? 'WASTED' : 'WRONG_INPUT';
        $isWasted = $voidType === 'WASTED';
        $wasteCategory = !empty($data['waste_category']) ? $data['waste_category'] : 'CUSTOMER_COMPLAINT';
        $reason = trim($data['reason'] ?? ($isWasted ? 'Pesanan Terbuang (Wasted)' : 'Salah Input / Batal'));
        $now = now();
        $dateStr = $firstTrx->date ?: $now->toDateString();
        $outletId = $firstTrx->outlet_id;
        $businessId = $firstTrx->business_id ?: $approver->business_id;

        $createdWasteLogs = [];

        DB::transaction(function () use ($transactions, $voidType, $isWasted, $wasteCategory, $reason, $approver, $user, $now, $dateStr, $outletId, $businessId, $orderNumber, &$createdWasteLogs) {
            foreach ($transactions as $t) {
                $menu = $t->menu;
                $itemQty = (int)$t->qty;

                // 1. If WASTED: deduct ingredients / stock as WASTE and create WasteLog
                if ($isWasted && $itemQty > 0) {
                    $realCost = 0.0;
                    if ($menu && $menu->item_type === 'RECIPE') {
                        $recipe = $menu->activeRecipe($dateStr);
                        if ($recipe && $recipe->items) {
                            foreach ($recipe->items as $rItem) {
                                $ing = $rItem->ingredient;
                                if (!$ing) continue;
                                $neededQty = abs((float)$rItem->qty * $itemQty);

                                // Calculate real cost per unit pakai (supporting batch cooked / semi-finished products)
                                $ingCost = (float)$ing->costPerPakaiForOutlet($outletId);
                                $lastMovement = StockMovement::where('ingredient_id', $ing->id)
                                    ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                                    ->whereIn('type', ['PURCHASE', 'TRANSFER_IN', 'INITIAL', 'PREP_OUTPUT', 'ADJUSTMENT_IN'])
                                    ->latest('date')
                                    ->latest('id')
                                    ->first();
                                if ($lastMovement) {
                                    if ((float)$lastMovement->cost_after > 0) {
                                        $ingCost = (float)$lastMovement->cost_after;
                                    } elseif ((float)$lastMovement->unit_price > 0) {
                                        $ingCost = (float)$lastMovement->unit_price;
                                    }
                                }
                                if ($ingCost <= 0) {
                                    $ingCost = (float)($ing->harga / max((float)$ing->konversi, 1));
                                }

                                $itemTotalCost = round($neededQty * $ingCost, 2);
                                $realCost += $itemTotalCost;

                                StockMovement::create([
                                    'business_id'    => $businessId,
                                    'ingredient_id'  => $ing->id,
                                    'outlet_id'      => $outletId,
                                    'type'           => 'WASTE',
                                    'waste_reason'   => $wasteCategory,
                                    'qty'            => $neededQty,
                                    'unit_price'     => $ingCost,
                                    'total_price'    => $itemTotalCost,
                                    'cost_before'    => $ingCost,
                                    'cost_after'     => $ingCost,
                                    'date'           => $dateStr,
                                    'note'           => "Void Wasted Open Bill #{$orderNumber} ({$menu->name} {$itemQty}x) [{$wasteCategory}]: {$reason}",
                                    'created_by'     => $approver->id,
                                    'user_id'        => $approver->id,
                                ]);
                            }
                        }
                    } elseif ($menu && $menu->item_type === 'DIRECT' && $menu->track_stock) {
                        $menu->decrement('stock', $itemQty);
                        if ($outletId) {
                            try {
                                $om = OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                                if ($om) $om->decrement('stock', $itemQty);
                            } catch (\Throwable $e) {}
                        }
                        $realCost = round((float)($menu->cost_price ?: $menu->price) * $itemQty, 2);
                    }

                    if ($realCost <= 0 && $menu && method_exists($menu, 'calculateHpp')) {
                        try {
                            $realCost = round((float)$menu->calculateHpp() * $itemQty, 2);
                        } catch (\Throwable $e) {}
                    }
                    if ($realCost <= 0 && $menu && $menu->item_type === 'DIRECT') {
                        $realCost = round((float)($menu->cost_price ?: ($menu->price * 0.4)) * $itemQty, 2);
                    }

                    $datePrefix = $now->format('Ymd');
                    $latestWaste = WasteLog::where('waste_no', 'like', "WST-{$datePrefix}-%")->orderBy('id', 'desc')->first();
                    $nextSeq = 1;
                    if ($latestWaste && preg_match('/WST-\d+-(\d+)/', $latestWaste->waste_no, $m)) {
                        $nextSeq = intval($m[1]) + 1;
                    }
                    $wasteNo = "WST-{$datePrefix}-" . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

                    $costPerUnit = $itemQty > 0 ? ($realCost / $itemQty) : $realCost;
                    $wasteLog = WasteLog::create([
                        'business_id'     => $businessId,
                        'outlet_id'       => $outletId,
                        'shift_id'        => $t->shift_id,
                        'date'            => $dateStr,
                        'waste_no'        => $wasteNo,
                        'item_type'       => $menu?->item_type ?: 'RECIPE',
                        'menu_id'         => $menu?->id,
                        'qty'             => (float)$itemQty,
                        'unit_type'       => $menu?->unit ?: 'porsi',
                        'qty_pakai'       => (float)$itemQty,
                        'cost_per_unit'   => $costPerUnit,
                        'loss_cost'       => $realCost,
                        'reason_category' => $wasteCategory,
                        'notes'           => "Void Open Bill Wasted ({$menu?->name} {$itemQty}x) Nota #{$orderNumber}: {$reason}",
                        'user_id'         => $approver->id,
                        'created_by'      => $approver->id,
                        'updated_by'      => $approver->id,
                    ]);
                    $createdWasteLogs[] = $wasteLog;
                }

                // Clean up previous SALE_USAGE stock movements
                // Gunakan Eloquent model delete agar event static::deleted terpanggil dan current_stock di outlet_ingredients terpulihkan!
                $saleMovements = StockMovement::where('transaction_id', $t->id)->where('type', 'SALE_USAGE')->get();
                foreach ($saleMovements as $sm) {
                    $sm->delete();
                }

                if (!$isWasted) {
                    // WRONG_INPUT: Revert direct retail product stock
                    if ($menu && $menu->item_type === 'DIRECT' && $menu->track_stock && $itemQty > 0) {
                        $menu->increment('stock', $itemQty);
                        if ($outletId) {
                            try {
                                $om = OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                                if ($om) $om->increment('stock', $itemQty);
                            } catch (\Throwable $e) {}
                        }
                    }
                }

                $t->update([
                    'status'              => 'CANCELLED',
                    'urgent_status'       => 'CANCELLED',
                    'void_type'           => $voidType,
                    'cancellation_reason' => $reason,
                    'cancelled_at'        => $now,
                    'cancelled_by'        => $approver->id,
                    'void_requested_by'   => $user->id,
                    'void_requested_at'   => $now,
                    'void_approved_by'    => $approver->id,
                    'void_approved_at'    => $now,
                    'notes'               => ($t->notes ? $t->notes . ' | ' : '') . "[VOID: {$voidType} - {$reason} (Oleh: {$approver->name})]",
                    'updated_by'          => $user->id,
                ]);

                // Batalkan UrgentNote yang masih PENDING untuk open bill yang dibatalkan (simpan sebagai CANCELLED untuk jejak audit & jurnal)
                UrgentNote::where('transaction_id', $t->id)
                    ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                    ->update([
                        'status' => 'CANCELLED',
                        'notes'  => DB::raw("CONCAT(COALESCE(notes, ''), ' [Void " . ($isWasted ? 'Wasted' : 'Salah Input') . ": " . addslashes($reason) . "]')"),
                    ]);
            }
        });

        // Auto-post double-entry journal reverse/cleanup for cancelled transactions
        foreach ($transactions as $t) {
            AccountingService::postTransaction($t);
        }

        // Post accounting journal for waste if any
        foreach ($createdWasteLogs as $wl) {
            try {
                AccountingService::postWaste($wl);
            } catch (\Throwable $e) {}
        }

        $typeLabel = $isWasted ? 'Wasted (Bahan baku dicatat terbuang)' : 'Salah Input (Bahan baku aman/tidak berkurang)';

        return response()->json([
            'success'       => true,
            'message'       => "Tagihan terbuka '{$orderNumber}' berhasil dibatalkan [Tipe: {$typeLabel}].",
            'order_number'  => $orderNumber,
            'void_type'     => $voidType,
            'reason'        => $reason,
        ]);
    }

    /**
     * Void a single item or partial quantity in an open bill.
     * Supports:
     * - WRONG_INPUT (Salah Input): Item cancelled before cooking/production. No waste recorded.
     * - WASTED (Wasted / Rusak / Terbuang): Item was already prepared and wasted. Deducts ingredients/stock as WASTE and creates WasteLog.
     */
    public function voidOpenBillItem(Request $request, $orderNumber)
    {
        $data = $request->validate([
            'item_id'             => 'required|integer',
            'qty'                 => 'nullable|integer|min:1',
            'void_type'           => 'required|string|in:WRONG_INPUT,WASTED',
            'waste_category'      => 'nullable|string|max:50',
            'reason'              => 'required|string|max:255',
            'supervisor_id'       => 'nullable|integer',
            'supervisor_password' => 'nullable|string|max:255',
            'supervisor_pin'      => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $targetTrx = Transaction::with(['menu', 'modifiers.ingredient'])
            ->where('id', $data['item_id'])
            ->where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->first();

        if (!$targetTrx) {
            return response()->json([
                'message' => "Item tagihan terbuka tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$targetTrx->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk membatalkan item tagihan di cabang outlet lain.'
                ], 403);
            }
        }

        $isManagerOrOwner = $user->isOwnerOrManager();
        $approver = $user;

        // If user is cashier/pegawai without owner/manager role, verify supervisor approval if provided
        if (!$isManagerOrOwner) {
            if (!empty($data['supervisor_id']) && (!empty($data['supervisor_password']) || !empty($data['supervisor_pin']))) {
                $supervisor = User::where('id', $data['supervisor_id'])
                    ->where('business_id', $targetTrx->business_id)
                    ->first();
                if (!$supervisor || !$supervisor->isOwnerOrManager()) {
                    return response()->json(['message' => 'Supervisor tidak valid atau tidak memiliki hak akses.'], 422);
                }
                $validPass = false;
                if (!empty($data['supervisor_password']) && Hash::check($data['supervisor_password'], $supervisor->password)) {
                    $validPass = true;
                }
                if (!$validPass && !empty($data['supervisor_pin']) && !empty($supervisor->pin) && Hash::check($data['supervisor_pin'], $supervisor->pin)) {
                    $validPass = true;
                }
                if (!$validPass) {
                    return response()->json(['message' => 'Password / PIN Supervisor tidak sesuai.'], 422);
                }
                $approver = $supervisor;
            }
        }

        $voidQty = !empty($data['qty']) ? min((int)$data['qty'], (int)$targetTrx->qty) : (int)$targetTrx->qty;
        $voidType = strtoupper($data['void_type']) === 'WASTED' ? 'WASTED' : 'WRONG_INPUT';
        $isWasted = $voidType === 'WASTED';
        $wasteCategory = !empty($data['waste_category']) ? $data['waste_category'] : 'CUSTOMER_COMPLAINT';
        $reason = trim($data['reason']);
        $now = now();

        $createdWasteLogs = [];
        $cancelledTrx = null;

        DB::transaction(function () use ($targetTrx, $voidQty, $voidType, $isWasted, $wasteCategory, $reason, $approver, $user, $now, &$createdWasteLogs, &$cancelledTrx) {
            $menu = $targetTrx->menu;
            $unitPrice = (float)$targetTrx->qty > 0 ? ((float)$targetTrx->total_price / (float)$targetTrx->qty) : (float)($menu?->price ?? 0);
            $unitSubtotal = (float)$targetTrx->qty > 0 ? ((float)$targetTrx->subtotal / (float)$targetTrx->qty) : $unitPrice;
            $unitDiscount = (float)$targetTrx->qty > 0 ? ((float)$targetTrx->discount_amount / (float)$targetTrx->qty) : 0;

            // 1. If WASTED: deduct ingredients / stock and record to WasteLog
            if ($isWasted) {
                $datePrefix = $now->format('Ymd');
                $dateStr = $targetTrx->date ?: $now->toDateString();
                $outletId = $targetTrx->outlet_id;
                $businessId = $targetTrx->business_id ?: $approver->business_id;

                $realCost = 0.0;
                // If Recipe Menu, deduct ingredients from StockMovement as WASTE
                if ($menu && $menu->item_type === 'RECIPE') {
                    $recipe = $menu->activeRecipe($dateStr);
                    if ($recipe && $recipe->items) {
                        foreach ($recipe->items as $rItem) {
                            $ing = $rItem->ingredient;
                            if (!$ing) continue;
                            $neededQty = abs((float)$rItem->qty * $voidQty);

                            // Calculate real cost per unit pakai (supporting batch cooked / semi-finished products)
                            $ingCost = (float)$ing->costPerPakaiForOutlet($outletId);
                            if ($ingCost <= 0) {
                                $ingCost = (float)($ing->harga / max((float)$ing->konversi, 1));
                            }

                            $itemTotalCost = round($neededQty * $ingCost, 2);
                            $realCost += $itemTotalCost;

                            // Create StockMovement for ingredient as WASTE
                            StockMovement::create([
                                'business_id'    => $businessId,
                                'ingredient_id'  => $ing->id,
                                'outlet_id'      => $outletId,
                                'transaction_id' => $targetTrx->id,
                                'type'           => 'WASTE',
                                'waste_reason'   => $wasteCategory,
                                'qty'            => $neededQty,
                                'unit_price'     => $ingCost,
                                'total_price'    => $itemTotalCost,
                                'cost_before'    => $ingCost,
                                'cost_after'     => $ingCost,
                                'date'           => $dateStr,
                                'note'           => "Void Wasted Open Bill #{$targetTrx->order_number} ({$menu->name} {$voidQty}x) [{$wasteCategory}]: {$reason}",
                                'created_by'     => $approver->id,
                                'user_id'        => $approver->id,
                            ]);
                        }
                    }
                } elseif ($menu && $menu->item_type === 'DIRECT' && $menu->track_stock) {
                    // For Direct Product: decrement stock and record cost
                    $menu->decrement('stock', $voidQty);
                    if ($outletId) {
                        try {
                            $om = OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                            if ($om) $om->decrement('stock', $voidQty);
                        } catch (\Throwable $e) {}
                    }
                    $realCost = round((float)($menu->cost_price ?: $menu->price) * $voidQty, 2);
                }

                if ($realCost <= 0 && $menu && method_exists($menu, 'calculateHpp')) {
                    try {
                        $hppPerUnit = (float)$menu->calculateHpp($dateStr, $outletId);
                        $realCost = round($hppPerUnit * $voidQty, 2);
                    } catch (\Throwable $e) {}
                }
                if ($realCost <= 0 && $menu && $menu->item_type === 'DIRECT' && (float)$menu->cost_price > 0) {
                    $realCost = round((float)$menu->cost_price * $voidQty, 2);
                }

                $costPerUnit = $voidQty > 0 ? round($realCost / $voidQty, 2) : $realCost;
                $lossCost = round($realCost, 2);

                $latestWaste = WasteLog::where('waste_no', 'like', "WST-{$datePrefix}-%")->orderBy('id', 'desc')->first();
                $nextSeq = 1;
                if ($latestWaste && preg_match('/WST-\d+-(\d+)/', $latestWaste->waste_no, $m)) {
                    $nextSeq = intval($m[1]) + 1;
                }
                $wasteNo = "WST-{$datePrefix}-" . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

                $wl = WasteLog::create([
                    'business_id'     => $businessId,
                    'waste_no'        => $wasteNo,
                    'date'            => $dateStr,
                    'item_type'       => $menu?->item_type ?: 'RECIPE',
                    'menu_id'         => $targetTrx->menu_id,
                    'outlet_id'       => $outletId,
                    'shift_id'        => $targetTrx->shift_id,
                    'qty'             => (float)$voidQty,
                    'unit_type'       => $menu?->unit ?: 'porsi',
                    'qty_pakai'       => (float)$voidQty,
                    'cost_per_unit'   => $costPerUnit,
                    'loss_cost'       => $lossCost,
                    'reason_category' => $wasteCategory,
                    'notes'           => "Void Wasted Open Bill #{$targetTrx->order_number} ({$menu?->name} {$voidQty}x): {$reason}",
                    'user_id'         => $approver->id,
                    'created_by'      => $approver->id,
                    'updated_by'      => $approver->id,
                ]);
                $createdWasteLogs[] = $wl;
            }

            // 2. Adjust or cancel the transaction row
            $typeLabel = $isWasted ? 'WASTED (Barang Terbuang)' : 'SALAH INPUT (Batal Pesan)';

            if ($voidQty >= (int)$targetTrx->qty) {
                // Entire row voided: Clean up SALE_USAGE stock movements
                // Gunakan Eloquent model delete agar event static::deleted terpanggil dan current_stock di outlet_ingredients terpulihkan!
                $saleMovements = StockMovement::where('transaction_id', $targetTrx->id)->where('type', 'SALE_USAGE')->get();
                foreach ($saleMovements as $sm) {
                    $sm->delete();
                }

                if (!$isWasted) {
                    if ($menu && $menu->item_type === 'DIRECT' && $menu->track_stock && $voidQty > 0) {
                        $menu->increment('stock', $voidQty);
                        if ($targetTrx->outlet_id) {
                            try {
                                $om = OutletMenu::where('outlet_id', $targetTrx->outlet_id)->where('menu_id', $menu->id)->first();
                                if ($om) $om->increment('stock', $voidQty);
                            } catch (\Throwable $e) {}
                        }
                    }
                }

                $targetTrx->update([
                    'status'              => 'CANCELLED',
                    'urgent_status'       => 'CANCELLED',
                    'void_type'           => $voidType,
                    'cancellation_reason' => $reason,
                    'cancelled_at'        => $now,
                    'cancelled_by'        => $approver->id,
                    'void_approved_by'    => $approver->id,
                    'void_approved_at'    => $now,
                    'notes'               => trim(($targetTrx->notes ?? '') . " [VOID ITEM {$typeLabel} ({$approver->name}): {$reason}]"),
                    'updated_by'          => $approver->id,
                ]);

                // Batalkan UrgentNote yang masih PENDING untuk transaksi yang di-void penuh (simpan sebagai CANCELLED untuk jejak audit & jurnal)
                UrgentNote::where('transaction_id', $targetTrx->id)
                    ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                    ->update([
                        'status' => 'CANCELLED',
                        'notes'  => DB::raw("CONCAT(COALESCE(notes, ''), ' [Void " . ($isWasted ? 'Wasted' : 'Salah Input') . ": " . addslashes($reason) . "]')"),
                    ]);
            } else {
                // Partial void: reduce qty on original HOLD row, create CANCELLED row for audit trail
                $remainingQty = (int)$targetTrx->qty - $voidQty;
                $originalQty = (int)$targetTrx->qty;

                // Adjust SALE_USAGE stock movements proportionally
                $movements = StockMovement::where('transaction_id', $targetTrx->id)->where('type', 'SALE_USAGE')->get();
                foreach ($movements as $sm) {
                    if ($originalQty > 0) {
                        $newQty = round(((float)$sm->qty / $originalQty) * $remainingQty, 4);
                        $sm->update([
                            'qty'         => $newQty,
                            'total_price' => round($newQty * (float)$sm->unit_price, 2),
                        ]);
                    }
                }

                // Adjust UrgentNotes proportionally
                $unList = UrgentNote::where('transaction_id', $targetTrx->id)->where('status', 'PENDING')->get();
                foreach ($unList as $un) {
                    if ($remainingQty <= 0) {
                        $un->update([
                            'status' => 'CANCELLED',
                            'notes'  => DB::raw("CONCAT(COALESCE(notes, ''), ' [Void " . ($isWasted ? 'Wasted' : 'Salah Input') . ": " . addslashes($reason) . "]')"),
                        ]);
                    } elseif ($originalQty > 0) {
                        $un->update([
                            'required_qty' => round(((float)$un->required_qty / $originalQty) * $remainingQty, 4),
                            'pending_qty'  => round(((float)$un->pending_qty / $originalQty) * $remainingQty, 4),
                        ]);
                    }
                }

                if (!$isWasted && $menu && $menu->item_type === 'DIRECT' && $menu->track_stock && $voidQty > 0) {
                    $menu->increment('stock', $voidQty);
                    if ($targetTrx->outlet_id) {
                        try {
                            $om = OutletMenu::where('outlet_id', $targetTrx->outlet_id)->where('menu_id', $menu->id)->first();
                            if ($om) $om->increment('stock', $voidQty);
                        } catch (\Throwable $e) {}
                    }
                }

                $targetTrx->update([
                    'qty'             => $remainingQty,
                    'subtotal'        => round($unitSubtotal * $remainingQty, 2),
                    'discount_amount' => round($unitDiscount * $remainingQty, 2),
                    'total_price'     => round($unitPrice * $remainingQty, 2),
                    'notes'           => trim(($targetTrx->notes ?? '') . " [Item di-void {$voidQty}x ({$typeLabel})]"),
                    'updated_by'      => $approver->id,
                ]);

                // Create audit cancelled record
                $cancelledTrx = $targetTrx->replicate();
                $cancelledTrx->qty = $voidQty;
                $cancelledTrx->subtotal = round($unitSubtotal * $voidQty, 2);
                $cancelledTrx->discount_amount = round($unitDiscount * $voidQty, 2);
                $cancelledTrx->total_price = round($unitPrice * $voidQty, 2);
                $cancelledTrx->status = 'CANCELLED';
                $cancelledTrx->void_type = $voidType;
                $cancelledTrx->cancellation_reason = $reason;
                $cancelledTrx->cancelled_at = $now;
                $cancelledTrx->cancelled_by = $approver->id;
                $cancelledTrx->void_approved_by = $approver->id;
                $cancelledTrx->void_approved_at = $now;
                $cancelledTrx->notes = "Void Sebagian Open Bill ({$voidQty}x dari {$targetTrx->qty}x) [{$typeLabel}]: {$reason}";
            }

            if ($cancelledTrx) {
                StockMovement::where('note', 'like', "%#{$orderNumber}%")
                    ->where('type', 'WASTE')
                    ->where(function($q) use ($targetTrx) {
                        $q->whereNull('transaction_id')->orWhere('transaction_id', $targetTrx->id);
                    })
                    ->where('created_at', '>=', $now->copy()->subSeconds(60))
                    ->update(['transaction_id' => $cancelledTrx->id]);
            }
        });

        // Auto-post / update double-entry journal for voided item
        AccountingService::postTransaction($targetTrx);
        if ($cancelledTrx) {
            AccountingService::postTransaction($cancelledTrx);
        }

        // Auto-post waste loss journals if void type is WASTED
        foreach ($createdWasteLogs as $wl) {
            AccountingService::postWaste($wl);
        }

        $menuName = $targetTrx->menu?->name ?? 'Menu';
        $succMsg = $isWasted
            ? "Item '{$menuName}' ({$voidQty}x) berhasil di-void (WASTED). Bahan baku terpakai dicatat sebagai kerugian waste & stok terpotong."
            : "Item '{$menuName}' ({$voidQty}x) berhasil di-void (Salah Input) dari tagihan {$orderNumber}.";

        return response()->json([
            'status'        => 'success',
            'message'       => $succMsg,
            'order_number'  => $orderNumber,
            'voided_item'   => $menuName,
            'voided_qty'    => $voidQty,
            'void_type'     => $voidType,
        ]);
    }

    /**
     * Deduct coin and record when printing Pre-Bill for an Open Bill.
     */
    public function printPrebill(Request $request, $orderNumber)
    {
        $transactions = Transaction::where('order_number', $orderNumber)
            ->where('status', 'HOLD')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Tagihan terbuka dengan nomor '{$orderNumber}' tidak ditemukan atau sudah diselesaikan."
            ], 404);
        }

        $first = $transactions->first();
        $businessId = (int)($request->user()->business_id ?: ($first->business_id ?: ($first->outlet?->business_id ?: 1)));
        $outletId = $first->outlet_id;

        // Check SaaS platform coin balance
        $coinCheck = CoinService::canTransact($businessId);
        if (!$coinCheck['allowed']) {
            return response()->json([
                'message'    => $coinCheck['message'],
                'coin_error' => true,
                'coin_data'  => $coinCheck,
            ], 402);
        }

        // Deduct coin if not already deducted for this order_number
        $coinTx = CoinService::deductForOrder(
            $businessId,
            $orderNumber,
            $outletId,
            $request->user()->id,
            "Pemotongan koin untuk Cetak Pre-Bill Nota #{$orderNumber}"
        );

        $business = \App\Models\Business::find($businessId);

        return response()->json([
            'success'       => true,
            'message'       => 'Koin transaksi berhasil dipotong untuk cetak Pre-Bill.',
            'deducted'      => (bool)$coinTx,
            'coin_balance'  => (float)($business?->coin_balance ?? 0),
            'cost'          => (float)($business?->coins_per_transaction ?? 1.0),
        ]);
    }

    /**
     * Void / Batalkan transaksi yang sudah tercetak / lunas (PAID) atau selesai,
     * dengan tetap menyimpan riwayat lengkap (audit trail), mengembalikan stok bahan/menu,
     * mengembalikan poin pelanggan, membatalkan kasbon / AR, serta mencatat alasan pembatalan.
     */
    /**
     * Get list of eligible Managers and Owners for supervisor approval PIN/Password.
     */
    public function getSupervisors(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $query = User::where('business_id', $businessId);

        $supervisors = $query->get()->filter(function ($u) {
            return $u->isOwnerOrManager();
        })->values()->map(function ($u) {
            return [
                'id'       => $u->id,
                'name'     => $u->name,
                'username' => $u->username ?? $u->email,
                'role'     => $u->role,
                'outlet_id'=> $u->outlet_id,
            ];
        });

        return response()->json($supervisors);
    }

    /**
     * Execute full cancellation, stock management, waste recording, and audit logging for an approved void transaction.
     */
    private function executeVoidApproval($transactions, string $orderNum, string $reason, User $approver, ?User $requester = null, string $voidType = 'WRONG_INPUT', string $wasteCategory = 'CUSTOMER_COMPLAINT')
    {
        $now = now();
        $firstTrx = $transactions->first();
        $isWasted = strtoupper($voidType) === 'WASTED';

        $createdWasteLogs = [];
        DB::transaction(function () use ($transactions, $orderNum, $reason, $now, $approver, $requester, $firstTrx, $isWasted, $voidType, $wasteCategory, &$createdWasteLogs) {
            $trxIds = $transactions->pluck('id')->toArray();

            // 1. Direct Product Stock Management
            if (!$isWasted) {
                // Restore DIRECT Product Stock (Retail items) ONLY when NOT wasted (Salah Input)
                foreach ($transactions as $t) {
                    if ($t->status === 'PAID' || $t->status === 'VOID_PENDING') {
                        $menu = $t->menu;
                        if ($menu && $menu->item_type === 'DIRECT' && $menu->track_stock) {
                            $menu->increment('stock', $t->qty);
                            if ($t->outlet_id) {
                                try {
                                    $om = OutletMenu::where('outlet_id', $t->outlet_id)->where('menu_id', $menu->id)->first();
                                    if ($om) {
                                        $om->increment('stock', $t->qty);
                                    }
                                } catch (\Throwable $e) {}
                            }
                        }
                    }
                }
            }

            // 2. Ingredient Stock Movements Management
            $saleMovements = StockMovement::where(function ($q) use ($trxIds, $orderNum) {
                $q->whereIn('transaction_id', $trxIds)
                  ->orWhere('note', 'like', "{$orderNum} – %")
                  ->orWhere('note', 'like', "{$orderNum}%");
            })->get();

            if (!$isWasted) {
                // If WRONG_INPUT: Hapus total riwayat mutasi dari Kartu Stok (stock_movements)
                // sesuai permintaan: "klau dia pilih void karna salah input dia harusnya menghapus juga riwayat di kartu stock dan hpp di laba rugi"
                StockMovement::where(function ($q) use ($trxIds, $orderNum) {
                    $q->whereIn('transaction_id', $trxIds)
                      ->orWhere('note', 'like', "{$orderNum} – %")
                      ->orWhere('note', 'like', "{$orderNum}%");
                })->delete();
            } else {
                // If WASTED: Stock is NOT returned (tetap dianggap barang keluar).
                // Change movement type to 'WASTE' so that it is NOT counted in SALE_USAGE (HPP Riil becomes 0),
                // but correctly recorded under WASTE in Kartu Stok & Laba Rugi.
                foreach ($saleMovements as $mov) {
                    $mov->update([
                        'type'         => 'WASTE',
                        'waste_reason' => $wasteCategory,
                        'note'         => trim(($mov->note ?? '') . " [VOID WASTED / {$wasteCategory}: {$reason}]"),
                        'updated_by'   => $approver->id,
                    ]);
                }

                // Automatically create WasteLog records for each wasted menu item with real ingredient cost
                $datePrefix = $now->format('Ymd');
                foreach ($transactions as $t) {
                    $menu = $t->menu;

                    // ⚡ Calculate actual real ingredient cost from the stock movements of this transaction item
                    $itemMovements = $saleMovements->where('transaction_id', $t->id);
                    $realCost = (float)$itemMovements->sum('total_price');

                    if ($realCost <= 0) {
                        $realCost = (float)$saleMovements->filter(function ($m) use ($orderNum, $menu) {
                            return str_contains($m->note ?? '', $orderNum) && ($menu ? str_contains($m->note ?? '', $menu->name) : true);
                        })->sum('total_price');
                    }

                    if ($realCost <= 0 && $menu && method_exists($menu, 'calculateHpp')) {
                        try {
                            $hppPerUnit = (float)$menu->calculateHpp($t->date ?: $now->toDateString(), $t->outlet_id);
                            $realCost = round($hppPerUnit * (float)$t->qty, 2);
                        } catch (\Throwable $e) {}
                    }

                    if ($realCost <= 0 && $menu && $menu->item_type === 'DIRECT' && (float)$menu->cost_price > 0) {
                        $realCost = round((float)$menu->cost_price * (float)$t->qty, 2);
                    }

                    $costPerUnit = (float)$t->qty > 0 ? round($realCost / (float)$t->qty, 2) : $realCost;
                    $lossCost = round($realCost, 2);

                    $latestWaste = WasteLog::where('waste_no', 'like', "WST-{$datePrefix}-%")->orderBy('id', 'desc')->first();
                    $nextSeq = 1;
                    if ($latestWaste && preg_match('/WST-\d+-(\d+)/', $latestWaste->waste_no, $m)) {
                        $nextSeq = intval($m[1]) + 1;
                    }
                    $wasteNo = "WST-{$datePrefix}-" . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

                    $wl = WasteLog::create([
                        'business_id'     => $t->business_id ?: $approver->business_id,
                        'waste_no'        => $wasteNo,
                        'date'            => $now->toDateString(),
                        'item_type'       => $menu?->item_type ?: 'RECIPE',
                        'menu_id'         => $t->menu_id,
                        'outlet_id'       => $t->outlet_id,
                        'shift_id'        => $t->shift_id,
                        'qty'             => (float)$t->qty,
                        'unit_type'       => $menu?->unit ?: 'porsi',
                        'qty_pakai'       => (float)$t->qty,
                        'cost_per_unit'   => $costPerUnit,
                        'loss_cost'       => $lossCost,
                        'reason_category' => $wasteCategory,
                        'notes'           => "Void Wasted (Makanan Batal/Terbuang) Nota #{$orderNum}: {$reason}",
                        'user_id'         => $approver->id,
                        'created_by'      => $approver->id,
                        'updated_by'      => $approver->id,
                    ]);
                    $createdWasteLogs[] = $wl;
                }
            }

            // 3. Cancel associated Urgent Notes
            UrgentNote::whereIn('transaction_id', $trxIds)
                ->orWhere('order_number', $orderNum)
                ->update([
                    'status' => 'CANCELLED',
                    'notes'  => DB::raw("CONCAT(COALESCE(notes, ''), ' [Dibatalkan / Void: " . addslashes($reason) . "]')"),
                ]);

            // 4. Revert Customer Loyalty Points & Promo Redemptions
            if ($firstTrx->customer_id) {
                $customer = Customer::find($firstTrx->customer_id);
                if ($customer) {
                    $customer->total_points = max(0, (int)$customer->total_points - 1);
                    $customer->total_visits = max(0, (int)$customer->total_visits - 1);

                    // If point promo was used, refund points back
                    $redemptions = PointRedemption::where('order_number', $orderNum)->get();
                    foreach ($redemptions as $red) {
                        $customer->total_points += (int)$red->points_used;
                        $red->delete();
                    }
                    $customer->save();
                }
            }

            // 5. Decrement Discount usage count if applicable
            if ($firstTrx->discount_id) {
                $disc = Discount::find($firstTrx->discount_id);
                if ($disc && $disc->used_count > 0) {
                    $disc->decrement('used_count');
                }
            }

            // 6. Cancel Receivables / Kasbon / AR Merchant records
            \App\Models\Receivable::where('order_number', $orderNum)->update([
                'status' => 'CANCELLED',
                'notes'  => DB::raw("CONCAT(COALESCE(notes, ''), ' [VOID: " . addslashes($reason) . "]')"),
            ]);

            // 7. Update all transaction lines to CANCELLED with audit trail details
            $reqId = $requester?->id ?? ($firstTrx->void_requested_by ?? $approver->id);
            $reqAt = $firstTrx->void_requested_at ?? $now;
            $typeLabel = $isWasted ? 'WASTED (Barang Terbuang)' : 'SALAH INPUT (Stok Kembali)';

            foreach ($transactions as $t) {
                $t->update([
                    'status'              => 'CANCELLED',
                    'void_type'           => $isWasted ? 'WASTED' : 'WRONG_INPUT',
                    'cancellation_reason' => $reason,
                    'cancelled_at'        => $now,
                    'cancelled_by'        => $approver->id,
                    'void_requested_by'   => $reqId,
                    'void_requested_at'   => $reqAt,
                    'void_approved_by'    => $approver->id,
                    'void_approved_at'    => $now,
                    'notes'               => trim(($t->notes ?? '') . " [VOID {$typeLabel} Disetujui ({$approver->name}): {$reason}]"),
                    'updated_by'          => $approver->id,
                ]);
            }
        });

        // Auto-post / reverse double-entry journal entries for voided transactions
        foreach ($transactions as $t) {
            AccountingService::postTransaction($t);
        }

        // Auto-post waste loss journals if void type is WASTED
        foreach ($createdWasteLogs as $wl) {
            AccountingService::postWaste($wl);
        }
    }

    /**
     * Void / Batalkan transaksi yang sudah tercetak / lunas (PAID) atau selesai.
     * Mendukung 2 Tipe Void:
     * 1. WRONG_INPUT (Salah Input): Stok bahan baku dikembalikan (ADJUSTMENT_IN) & penjualan dibatalkan.
     * 2. WASTED (Wasted / Rusak / Terbuang): Bahan tetap dianggap keluar (tidak dikembalikan) & dicatat ke Laporan Waste.
     */
    public function voidTransaction(Request $request, $orderNumber = null)
    {
        $data = $request->validate([
            'order_number'        => 'nullable|string|max:100',
            'reason'              => 'required|string|max:255',
            'void_type'           => 'nullable|string|in:WRONG_INPUT,WASTED',
            'waste_category'      => 'nullable|string|max:50',
            'supervisor_id'       => 'nullable|integer',
            'supervisor_password' => 'nullable|string|max:255',
            'supervisor_pin'      => 'nullable|string|max:50',
        ]);

        $orderNum = $orderNumber ?: ($data['order_number'] ?? null);
        if (!$orderNum) {
            return response()->json(['message' => 'Nomor order/nota transaksi harus diisi.'], 422);
        }

        $transactions = Transaction::where('order_number', $orderNum)->get();

        if ($transactions->isEmpty() && is_numeric($orderNum)) {
            $singleTrx = Transaction::find((int)$orderNum);
            if ($singleTrx) {
                $transactions = collect([$singleTrx]);
                $orderNum = $singleTrx->order_number ?: "TRX-{$singleTrx->id}";
            }
        }

        if ($transactions->isEmpty()) {
            return response()->json([
                'message' => "Nota transaksi '{$orderNum}' tidak ditemukan."
            ], 404);
        }

        // Check if already cancelled
        $allCancelled = $transactions->every(fn($t) => $t->status === 'CANCELLED');
        if ($allCancelled) {
            return response()->json([
                'message' => "Nota transaksi '{$orderNum}' sudah dibatalkan (void) sebelumnya."
            ], 422);
        }

        $user = $request->user();
        $firstTrx = $transactions->first();

        // Check multi-tenant & outlet authorization
        if ($user && $user->business_id && $firstTrx->business_id && (int)$user->business_id !== (int)$firstTrx->business_id) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke data bisnis ini.'], 403);
        }

        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ($firstTrx && (int)$firstTrx->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk membatalkan transaksi di cabang outlet lain.'
                ], 403);
            }
        }

        $reason = trim($data['reason']);
        $voidType = !empty($data['void_type']) && strtoupper($data['void_type']) === 'WASTED' ? 'WASTED' : 'WRONG_INPUT';
        $isWasted = $voidType === 'WASTED';
        $wasteCategory = !empty($data['waste_category']) ? $data['waste_category'] : 'CUSTOMER_COMPLAINT';
        $isManagerOrOwner = $user->isOwnerOrManager();

        // SCENARIO 1: Current logged-in user IS Manager/Owner
        if ($isManagerOrOwner) {
            $this->executeVoidApproval($transactions, $orderNum, $reason, $user, $user, $voidType, $wasteCategory);

            $succMsg = $isWasted
                ? "Nota transaksi '{$orderNum}' berhasil di-void (Wasted / {$wasteCategory}) oleh Manajer/Owner ({$user->name}). Bahan tetap tercatat keluar dan dicatat ke Laporan Waste."
                : "Nota transaksi '{$orderNum}' berhasil di-void (Salah Input) oleh Manajer/Owner ({$user->name}). Riwayat di Kartu Stok & HPP Laba Rugi telah dihapus bersih.";

            return response()->json([
                'message'             => $succMsg,
                'order_number'        => $orderNum,
                'cancellation_reason' => $reason,
                'void_type'           => $voidType,
                'waste_category'      => $wasteCategory,
                'cancelled_at'        => now()->toDateTimeString(),
                'cancelled_by_name'   => $user->name,
                'void_approved_by'    => $user->name,
                'status'              => 'CANCELLED',
                'is_approved'         => true,
            ]);
        }

        // SCENARIO 2: Current user is Pegawai/Kasir, with Supervisor credentials for instant approval
        if (!empty($data['supervisor_id']) && (!empty($data['supervisor_password']) || !empty($data['supervisor_pin']))) {
            $supervisor = User::where('business_id', $user->business_id)->find((int)$data['supervisor_id']);
            if (!$supervisor || !$supervisor->isOwnerOrManager()) {
                return response()->json([
                    'message' => 'Akun supervisor / manajer yang dipilih tidak valid.'
                ], 422);
            }

            $pass = $data['supervisor_password'] ?? $data['supervisor_pin'];
            $passValid = Hash::check($pass, $supervisor->password) || (isset($supervisor->pin) && $supervisor->pin === $pass);

            if (!$passValid) {
                return response()->json([
                    'message' => "Otorisasi gagal: Password / PIN untuk Manajer/Owner '{$supervisor->name}' salah."
                ], 422);
            }

            // Execute instant void approval authorized by this supervisor
            $this->executeVoidApproval($transactions, $orderNum, $reason, $supervisor, $user, $voidType, $wasteCategory);

            $succMsg = $isWasted
                ? "Nota transaksi '{$orderNum}' berhasil di-void (Wasted / {$wasteCategory}) via otorisasi Manajer ({$supervisor->name}). Bahan tetap tercatat keluar dan dicatat ke Laporan Waste."
                : "Nota transaksi '{$orderNum}' berhasil di-void (Salah Input) via otorisasi Manajer ({$supervisor->name}). Riwayat di Kartu Stok & HPP Laba Rugi telah dihapus bersih.";

            return response()->json([
                'message'             => $succMsg,
                'order_number'        => $orderNum,
                'cancellation_reason' => $reason,
                'void_type'           => $voidType,
                'waste_category'      => $wasteCategory,
                'cancelled_at'        => now()->toDateTimeString(),
                'cancelled_by_name'   => $user->name,
                'void_approved_by'    => $supervisor->name,
                'status'              => 'CANCELLED',
                'is_approved'         => true,
            ]);
        }

        // SCENARIO 3: Current user is Pegawai/Kasir requesting void asynchronously (Pending Approval)
        $now = now();
        $typeStr = $isWasted ? "WASTED [{$wasteCategory}]" : 'SALAH INPUT';
        foreach ($transactions as $t) {
            $t->update([
                'status'              => 'VOID_PENDING',
                'void_type'           => $voidType,
                'cancellation_reason' => $reason,
                'void_requested_by'   => $user->id,
                'void_requested_at'   => $now,
                'notes'               => trim(($t->notes ?? '') . " [PERMOHONAN VOID {$typeStr} ({$user->name}): {$reason}]"),
                'updated_by'          => $user->id,
            ]);
        }

        $pendingMsg = $isWasted
            ? "Permohonan void nota (Wasted / {$wasteCategory}) '{$orderNum}' berhasil diajukan ke Manajer/Owner. Bahan tetap tercatat keluar setelah disetujui."
            : "Permohonan void nota (Salah Input) '{$orderNum}' berhasil diajukan ke Manajer/Owner. Riwayat di Kartu Stok & HPP akan dibersihkan setelah permohonan disetujui.";

        return response()->json([
            'message'             => $pendingMsg,
            'order_number'        => $orderNum,
            'cancellation_reason' => $reason,
            'void_type'           => $voidType,
            'waste_category'      => $wasteCategory,
            'void_requested_at'   => $now->toDateTimeString(),
            'void_requested_by'   => $user->name,
            'status'              => 'VOID_PENDING',
            'is_pending_approval' => true,
        ]);
    }

    /**
     * Approve a pending void request (Manager / Owner only).
     */
    public function approveVoidTransaction(Request $request, $orderNumber)
    {
        $user = $request->user();
        if (!$user->isOwnerOrManager() && !$user->hasPermission('pos_void_approve')) {
            return response()->json([
                'message' => 'Akses Ditolak: Hanya Manajer atau Owner yang berhak menyetujui pembatalan (void) nota transaksi.'
            ], 403);
        }

        $transactions = Transaction::where('order_number', $orderNumber)->get();
        if ($transactions->isEmpty() && is_numeric($orderNumber)) {
            $singleTrx = Transaction::find((int)$orderNumber);
            if ($singleTrx) {
                $transactions = collect([$singleTrx]);
                $orderNumber = $singleTrx->order_number ?: "TRX-{$singleTrx->id}";
            }
        }

        if ($transactions->isEmpty()) {
            return response()->json(['message' => "Nota transaksi '{$orderNumber}' tidak ditemukan."], 404);
        }

        $firstTrx = $transactions->first();
        if ($user->business_id && $firstTrx->business_id && (int)$user->business_id !== (int)$firstTrx->business_id) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke data bisnis ini.'], 403);
        }

        if ($firstTrx->status === 'CANCELLED') {
            return response()->json(['message' => "Nota transaksi '{$orderNumber}' sudah dibatalkan (void) sebelumnya."], 422);
        }

        $reason = $request->input('reason') ?: ($firstTrx->cancellation_reason ?: 'Disetujui Manajer/Owner');
        $voidType = $request->input('void_type') ?: ($firstTrx->void_type ?: 'WRONG_INPUT');
        $wasteCategory = $request->input('waste_category') ?: 'CUSTOMER_COMPLAINT';
        $requester = $firstTrx->voidRequestedByUser;

        $this->executeVoidApproval($transactions, $orderNumber, $reason, $user, $requester, $voidType, $wasteCategory);

        $succMsg = strtoupper($voidType) === 'WASTED'
            ? "Permohonan void nota '{$orderNumber}' (Wasted / {$wasteCategory}) telah disetujui. Bahan tetap tercatat keluar dan dicatat ke Laporan Waste."
            : "Permohonan void nota '{$orderNumber}' (Salah Input) telah disetujui. Transaksi dibatalkan dan seluruh riwayat mutasi telah dihapus dari Kartu Stok.";

        return response()->json([
            'message'             => $succMsg,
            'order_number'        => $orderNumber,
            'void_type'           => $voidType,
            'waste_category'      => $wasteCategory,
            'status'              => 'CANCELLED',
            'void_approved_by'    => $user->name,
            'void_approved_at'    => now()->toDateTimeString(),
        ]);
    }

    /**
     * Reject a pending void request (Manager / Owner only).
     */
    public function rejectVoidTransaction(Request $request, $orderNumber)
    {
        $user = $request->user();
        if (!$user->isOwnerOrManager() && !$user->hasPermission('pos_void_approve')) {
            return response()->json([
                'message' => 'Akses Ditolak: Hanya Manajer atau Owner yang berhak menolak permohonan void nota transaksi.'
            ], 403);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $transactions = Transaction::where('order_number', $orderNumber)->get();
        if ($transactions->isEmpty() && is_numeric($orderNumber)) {
            $singleTrx = Transaction::find((int)$orderNumber);
            if ($singleTrx) {
                $transactions = collect([$singleTrx]);
                $orderNumber = $singleTrx->order_number ?: "TRX-{$singleTrx->id}";
            }
        }

        if ($transactions->isEmpty()) {
            return response()->json(['message' => "Nota transaksi '{$orderNumber}' tidak ditemukan."], 404);
        }

        $firstTrx = $transactions->first();
        if ($user->business_id && $firstTrx->business_id && (int)$user->business_id !== (int)$firstTrx->business_id) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke data bisnis ini.'], 403);
        }

        $rejectReason = trim($data['reason'] ?? 'Permohonan void ditolak oleh Manajer/Owner');
        $now = now();

        foreach ($transactions as $t) {
            $t->update([
                'status'              => 'PAID',
                'void_rejected_by'    => $user->id,
                'void_rejected_at'    => $now,
                'void_reject_reason'  => $rejectReason,
                'notes'               => trim(($t->notes ?? '') . " [VOID DITOLAK ({$user->name}): {$rejectReason}]"),
                'updated_by'          => $user->id,
            ]);
        }

        return response()->json([
            'message'             => "Permohonan void nota '{$orderNumber}' telah ditolak. Transaksi tetap berstatus LUNAS dan bahan baku tetap terpakai.",
            'order_number'        => $orderNumber,
            'status'              => 'PAID',
            'void_rejected_by'    => $user->name,
            'void_rejected_at'    => $now->toDateTimeString(),
            'void_reject_reason'  => $rejectReason,
        ]);
    }

    /**
     * Get list of void requests and history for Manager / Owner approval page.
     */
    public function voidRequests(Request $request)
    {
        $user = $request->user();
        if (!$user->isOwnerOrManager() && !$user->isPegawai() && !$user->hasPermission('pos_void_request') && !$user->hasPermission('pos_void_approve')) {
            return response()->json([
                'message' => 'Akses Ditolak: Anda tidak memiliki akses ke data permohonan void.'
            ], 403);
        }

        $businessId = $user->business_id;
        $status = $request->input('status', 'VOID_PENDING'); // 'VOID_PENDING' | 'CANCELLED' | 'REJECTED' | 'ALL'
        $outletId = $request->input('outlet_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $search = trim($request->input('search', ''));

        // Query base
        $baseQuery = Transaction::with([
            'menu.recipes.items.ingredient',
            'modifiers.ingredient',
            'voidRequestedByUser',
            'voidApprovedByUser',
            'voidRejectedByUser',
            'outlet',
            'user',
            'stockMovements.ingredient'
        ]);

        if ($businessId) {
            $baseQuery->where('business_id', $businessId);
        }

        if ($outletId) {
            $baseQuery->where('outlet_id', $outletId);
        } elseif (($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $baseQuery->where('outlet_id', $user->outlet_id);
        }

        if ($startDate && $endDate) {
            $baseQuery->whereBetween('date', [$startDate, $endDate]);
        }

        // Summary stats across the business/outlet
        $statsQuery = Transaction::query();
        if ($businessId) {
            $statsQuery->where('business_id', $businessId);
        }
        if ($outletId) {
            $statsQuery->where('outlet_id', $outletId);
        } elseif (($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $statsQuery->where('outlet_id', $user->outlet_id);
        }

        // Filter transactions for requests list
        $query = clone $baseQuery;
        if ($status === 'VOID_PENDING') {
            $query->where('status', 'VOID_PENDING');
        } elseif ($status === 'CANCELLED') {
            $query->where('status', 'CANCELLED')->whereNotNull('cancellation_reason');
        } elseif ($status === 'REJECTED') {
            $query->whereNotNull('void_rejected_at');
        } else { // ALL
            $query->where(function ($q) {
                $q->where('status', 'VOID_PENDING')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'CANCELLED')->whereNotNull('cancellation_reason');
                  })
                  ->orWhereNotNull('void_rejected_at');
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('cancellation_reason', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('voidRequestedByUser', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $allMatching = $query->orderBy('void_requested_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        // Group by order_number
        $grouped = $allMatching->groupBy(function ($item) {
            return $item->order_number ?: "TRX-{$item->id}";
        });

        $resultOrders = [];
        foreach ($grouped as $orderNum => $trxList) {
            $first = $trxList->first();
            $items = [];
            $ingredientsMap = [];

            $totalOrderPrice = 0.0;
            $subtotalOrder = 0.0;
            $discountOrder = 0.0;

            foreach ($trxList as $t) {
                $itemSub = (float)($t->subtotal ?: $t->total_price);
                $itemTotal = (float)$t->total_price;
                $totalOrderPrice += $itemTotal;
                $subtotalOrder += $itemSub;
                $discountOrder += (float)($t->discount_amount ?: 0);

                $modArray = [];
                foreach ($t->modifiers as $mod) {
                    $modArray[] = [
                        'name'        => $mod->name,
                        'price'       => (float)$mod->price,
                        'qty'         => (float)$mod->qty,
                        'unit'        => $mod->unit,
                        'ingredient'  => $mod->ingredient ? [
                            'id'   => $mod->ingredient->id,
                            'name' => $mod->ingredient->name,
                            'unit' => $mod->ingredient->unit_pakai ?: $mod->ingredient->unit,
                        ] : null
                    ];

                    // Add modifier ingredient to preview
                    if ($mod->ingredient) {
                        $ingId = $mod->ingredient->id;
                        $ingQty = (float)($mod->qty * $t->qty);
                        if (!isset($ingredientsMap[$ingId])) {
                            $ingredientsMap[$ingId] = [
                                'ingredient_id' => $ingId,
                                'name'          => $mod->ingredient->name,
                                'unit'          => $mod->unit ?: ($mod->ingredient->unit_pakai ?: $mod->ingredient->unit),
                                'qty'           => 0.0,
                                'type'          => 'MODIFIER',
                            ];
                        }
                        $ingredientsMap[$ingId]['qty'] += $ingQty;
                    }
                }

                $items[] = [
                    'id'          => $t->id,
                    'menu_id'     => $t->menu_id,
                    'menu_name'   => $t->menu?->name ?: "Item #{$t->id}",
                    'item_type'   => $t->menu?->item_type ?: 'RECIPE',
                    'qty'         => (int)$t->qty,
                    'price'       => (float)($t->qty > 0 ? ($itemSub / $t->qty) : $itemSub),
                    'subtotal'    => $itemSub,
                    'total_price' => $itemTotal,
                    'modifiers'   => $modArray,
                ];

                // Direct retail product stock restoration preview
                if ($t->menu && $t->menu->item_type === 'DIRECT') {
                    $key = 'DIRECT_' . $t->menu->id;
                    if (!isset($ingredientsMap[$key])) {
                        $ingredientsMap[$key] = [
                            'ingredient_id' => null,
                            'name'          => $t->menu->name . ' (Produk Jadi/Konsinyasi)',
                            'unit'          => 'pcs',
                            'qty'           => 0.0,
                            'type'          => 'DIRECT_PRODUCT',
                        ];
                    }
                    $ingredientsMap[$key]['qty'] += (float)$t->qty;
                }

                // Check recipe ingredients
                $recipe = $t->menu?->activeRecipe($t->date);
                if ($recipe) {
                    foreach ($recipe->items as $rItem) {
                        if ($rItem->ingredient) {
                            $ingId = $rItem->ingredient->id;
                            $ingQty = (float)($rItem->qty * $t->qty);
                            if (!isset($ingredientsMap[$ingId])) {
                                $ingredientsMap[$ingId] = [
                                    'ingredient_id' => $ingId,
                                    'name'          => $rItem->ingredient->name,
                                    'unit'          => $rItem->unit ?: ($rItem->ingredient->unit_pakai ?: $rItem->ingredient->unit),
                                    'qty'           => 0.0,
                                    'type'          => 'RECIPE_INGREDIENT',
                                ];
                            }
                            $ingredientsMap[$ingId]['qty'] += $ingQty;
                        }
                    }
                }

                // If sale stock movements exist, match actual movements recorded
                if ($t->stockMovements) {
                    foreach ($t->stockMovements as $sm) {
                        if ($sm->type === 'SALE_USAGE' && $sm->ingredient) {
                            $ingId = $sm->ingredient->id;
                            // Update with exact movement unit
                            if (isset($ingredientsMap[$ingId])) {
                                $ingredientsMap[$ingId]['unit'] = $sm->ingredient->unit_pakai ?: $sm->ingredient->unit;
                            }
                        }
                    }
                }
            }

            $resultOrders[] = [
                'order_number'           => $orderNum,
                'parent_order_number'    => $first->parent_order_number,
                'outlet_id'              => $first->outlet_id,
                'outlet_name'            => $first->outlet?->name ?: 'Outlet Utama',
                'date'                   => $first->date,
                'created_at'             => $first->created_at?->toDateTimeString(),
                'status'                 => $first->status,
                'customer_name'          => $first->customer_name,
                'table_number'           => $first->table_number,
                'order_type'             => $first->order_type,
                'payment_method'         => $first->payment_method,
                'total_price'            => $totalOrderPrice,
                'subtotal'               => $subtotalOrder,
                'discount_amount'        => $discountOrder,
                'amount_paid'            => (float)$first->amount_paid,
                'change_amount'          => (float)$first->change_amount,
                'cancellation_reason'    => $first->cancellation_reason,
                'void_type'              => $first->void_type ?: 'WRONG_INPUT',
                'cashier_name'           => $first->user?->name ?: 'Kasir',
                'void_requested_by'      => $first->void_requested_by,
                'void_requested_by_name' => $first->voidRequestedByUser?->name ?: ($first->void_requested_by ? "User #{$first->void_requested_by}" : null),
                'void_requested_at'      => $first->void_requested_at ? (string)$first->void_requested_at : null,
                'void_approved_by'       => $first->void_approved_by,
                'void_approved_by_name'  => $first->voidApprovedByUser?->name ?: ($first->void_approved_by ? "User #{$first->void_approved_by}" : null),
                'void_approved_at'       => $first->void_approved_at ? (string)$first->void_approved_at : null,
                'void_rejected_by'       => $first->void_rejected_by,
                'void_rejected_by_name'  => $first->voidRejectedByUser?->name ?: ($first->void_rejected_by ? "User #{$first->void_rejected_by}" : null),
                'void_rejected_at'       => $first->void_rejected_at ? (string)$first->void_rejected_at : null,
                'void_reject_reason'     => $first->void_reject_reason,
                'items'                  => $items,
                'ingredients_breakdown'  => array_values($ingredientsMap),
            ];
        }

        // Stats calculation
        $pendingTrx = (clone $statsQuery)->where('status', 'VOID_PENDING')->get();
        $pendingOrderNumbers = $pendingTrx->pluck('order_number')->unique()->count();
        $pendingTotalAmount = (float)$pendingTrx->sum('total_price');

        $approvedTrx = (clone $statsQuery)->where('status', 'CANCELLED')->where(function ($q) {
            $q->whereNotNull('void_approved_at')->orWhereNotNull('cancellation_reason');
        })->get();
        $approvedOrderNumbers = $approvedTrx->pluck('order_number')->unique()->count();
        $approvedTotalAmount = (float)$approvedTrx->sum('total_price');
        $approvedWastedCount = $approvedTrx->where('void_type', 'WASTED')->pluck('order_number')->unique()->count();
        $approvedWrongInputCount = $approvedTrx->where('void_type', '!=', 'WASTED')->pluck('order_number')->unique()->count();

        $rejectedTrx = (clone $statsQuery)->whereNotNull('void_rejected_at')->get();
        $rejectedOrderNumbers = $rejectedTrx->pluck('order_number')->unique()->count();

        return response()->json([
            'data'  => $resultOrders,
            'stats' => [
                'pending_count'              => $pendingOrderNumbers,
                'pending_total_amount'       => $pendingTotalAmount,
                'approved_count'             => $approvedOrderNumbers,
                'approved_total_amount'      => $approvedTotalAmount,
                'approved_wasted_count'      => $approvedWastedCount,
                'approved_wrong_input_count' => $approvedWrongInputCount,
                'rejected_count'             => $rejectedOrderNumbers,
            ]
        ]);
    }

    /**
     * Deduct stock for transaction items when settling split bills or individual orders.
     */
    private function deductStockForTransaction(Transaction $t, string $orderRef, int $userId)
    {
        $menu = $t->menu;
        $outletId = $t->outlet_id ?: 1;
        $businessId = $t->business_id ?: 1;
        $date = $t->date ?: now()->toDateString();
        $isPaid = true;

        // Deduct direct stock for DIRECT retail items
        if ($menu) {
            $this->processDirectProductStockDeduction(
                $businessId,
                $outletId,
                $t,
                $orderRef,
                $menu,
                $t->qty,
                $userId
            );
        }

        $recipe = $menu?->activeRecipe($date);
        if ($recipe) {
            foreach ($recipe->items as $item) {
                if ($item->ingredient) {
                    $requiredIngQty = (float)($item->qty * $t->qty);
                    $this->processIngredientStockDeduction(
                        $businessId,
                        $outletId,
                        $t,
                        $orderRef,
                        $date,
                        $item->ingredient,
                        $requiredIngQty,
                        "{$menu->name} (x{$t->qty}) [Split Bill]",
                        'RECIPE',
                        $menu->id,
                        !empty($t->shift_id),
                        $isPaid,
                        $userId,
                        $item->unit
                    );
                }
            }
        }

        foreach ($t->modifiers as $mod) {
            if ($mod->ingredient_id && $mod->qty > 0 && $mod->ingredient) {
                $requiredModQty = (float)($mod->qty * $t->qty);
                $this->processIngredientStockDeduction(
                    $businessId,
                    $outletId,
                    $t,
                    $orderRef,
                    $date,
                    $mod->ingredient,
                    $requiredModQty,
                    "Modifier {$mod->name} ({$menu?->name} x{$t->qty}) [Split Bill]",
                    'MODIFIER',
                    $menu?->id,
                    !empty($t->shift_id),
                    $isPaid,
                    $userId,
                    $mod->unit
                );
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
            'payment_method'  => 'required|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,KASBON,PIUTANG,OTHER,APLIKASI',
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

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $firstTrx = $transactions->first();
            if ($firstTrx && (int)$firstTrx->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk memproses tagihan di cabang outlet lain.'
                ], 403);
            }
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

        $user = $request->user();
        $isOwner = $user && ($user->isOwnerBisnis() || $user->isOwnerWebsite() || $user->isPlatformAdmin() || $user->isOwnerOutlet());

        $first = $transactions->first();
        $outletId = $first->outlet_id;
        $activeShift = \App\Models\Shift::where('status', 'OPEN')->where('outlet_id', $outletId)->orderByDesc('opened_at')->first();
        if (!$activeShift) {
            $activeShift = \App\Models\Shift::find($first->shift_id);
        }
        $shiftId = $activeShift ? $activeShift->id : $first->shift_id;

        if (!$isOwner && (!$shiftId || !$activeShift)) {
            return response()->json([
                'message' => 'Shift kasir belum dibuka untuk cabang ini. Silakan buka shift kasir terlebih dahulu sebelum memproses pembayaran.',
                'shift_error' => true,
            ], 422);
        }

        $date = now()->toDateString();
        $tableNumber = $first->table_number;
        $customerName = !empty($data['customer_name']) ? trim($data['customer_name']) : ($first->customer_name ? "{$first->customer_name} (Bagian {$splitIndex})" : "Tamu {$splitIndex}");
        $businessId = (int)($request->user()->business_id ?: ($first->business_id ?: ($first->outlet?->business_id ?: 1)));

        // Pre-check SaaS coin balance
        $coinCheck = CoinService::canTransact($businessId);
        if (!$coinCheck['allowed']) {
            return response()->json([
                'message'    => $coinCheck['message'],
                'coin_error' => true,
                'coin_data'  => $coinCheck,
            ], 402);
        }

        $paidResult = DB::transaction(function () use (
            $requestedItems, $orderNumber, $subOrderNumber, $splitIndex, $data, $amountPaid, $changeAmount,
            $date, $outletId, $shiftId, $tableNumber, $customerName, $businessId, $request
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
                        'is_open_bill'        => true,
                        'shift_id'            => $shiftId,
                        'date'                => $date,
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
                        'is_open_bill'        => true,
                        'business_id'         => $orig->business_id,
                        'is_unlimited_mode'   => (bool)($orig->is_unlimited_mode ?? $orig->business?->is_unlimited),
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

            // Deduct SaaS platform coins for completed split sub-nota
            CoinService::deductForOrder($businessId, $subOrderNumber, $outletId, $request->user()->id);

            // Auto-post double-entry journal ledger inside the database transaction
            foreach ($paidRows as $pTrx) {
                AccountingService::postTransaction($pTrx);
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

        $splitFirst = $targetTransactions->first();
        $splitOutlet = $splitFirst?->outlet ? $splitFirst->outlet->loadMissing('business') : null;

        return response()->json([
            'message'              => "Pembayaran Split Bill Bagian {$splitIndex} ({$subOrderNumber}) berhasil!",
            'order_number'         => $subOrderNumber,
            'parent_order_number'  => $orderNumber,
            'business_name'        => $splitOutlet?->business?->name ?? 'MOVA POS',
            'outlet_name'          => $splitOutlet?->name ?? 'Outlet',
            'outlet_address'       => $splitOutlet?->address,
            'outlet_phone'         => $splitOutlet?->phone,
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
            'payment_method' => 'required|string|in:CASH,QRIS,TRANSFER,DEBIT,GRAB,GRABFOOD,GOFOOD,GOJEK,SHOPEEFOOD,SHOPEE,KASBON,PIUTANG,OTHER,APLIKASI',
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

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $firstTrx = $transactions->first();
            if ($firstTrx && (int)$firstTrx->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk memproses tagihan di cabang outlet lain.'
                ], 403);
            }
        }

        $first = $transactions->first();
        $businessId = (int)($request->user()->business_id ?: ($first->business_id ?: ($first->outlet?->business_id ?: 1)));

        // Pre-check SaaS coin balance
        $coinCheck = CoinService::canTransact($businessId);
        if (!$coinCheck['allowed']) {
            return response()->json([
                'message'    => $coinCheck['message'],
                'coin_error' => true,
                'coin_data'  => $coinCheck,
            ], 402);
        }

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

        $user = $request->user();
        $isOwner = $user && ($user->isOwnerBisnis() || $user->isOwnerWebsite() || $user->isPlatformAdmin() || $user->isOwnerOutlet());

        DB::transaction(function () use (
            $transactions, $first, $orderNumber, $subOrderNumber, $splitIndex, $totalSplits, $splitAmount,
            $amountPaid, $changeAmount, $data, $customerName, $isTableClosed, $businessId, $request, $isOwner
        ) {
            $outletId = $first->outlet_id;
            $activeShift = \App\Models\Shift::where('outlet_id', $outletId)
                ->where('status', 'OPEN')
                ->orderByDesc('opened_at')
                ->first();
            if (!$activeShift) {
                $activeShift = \App\Models\Shift::find($first->shift_id);
            }
            $splitShiftId = $activeShift ? $activeShift->id : $first->shift_id;

            if (!$isOwner && (!$splitShiftId || !$activeShift)) {
                throw new \Exception('Shift kasir belum dibuka untuk cabang ini. Silakan buka shift kasir terlebih dahulu sebelum memproses pembayaran.');
            }

            $date = now()->toDateString();

            // Create a payment record for this equal split installment
            $splitTrx = Transaction::create([
                'order_number'        => $subOrderNumber,
                'parent_order_number' => $orderNumber,
                'split_index'         => $splitIndex,
                'split_total'         => $totalSplits,
                'split_type'          => 'EQUAL',
                'status'              => 'PAID',
                'is_open_bill'        => true,
                'business_id'         => $first->business_id,
                'is_unlimited_mode'   => (bool)($first->is_unlimited_mode ?? $first->business?->is_unlimited),
                'date'                => $date,
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
                'shift_id'            => $splitShiftId,
                'outlet_id'           => $first->outlet_id,
                'created_by'          => $request->user()->id,
            ]);

            // If this is the final split, mark the original HOLD transactions as SPLIT_CLOSED to close the order
            if ($isTableClosed) {
                foreach ($transactions as $t) {
                    $t->update([
                        'status'     => 'SPLIT_CLOSED',
                        'shift_id'   => $splitShiftId,
                        'updated_by' => $request->user()->id,
                    ]);
                    $this->deductStockForTransaction($t, $orderNumber, $request->user()->id);
                }
            }

            // Deduct SaaS platform coins for completed split sub-nota
            CoinService::deductForOrder($businessId, $subOrderNumber, $first->outlet_id, $request->user()->id);

            // Auto-post double-entry journal ledger inside the database transaction
            AccountingService::postTransaction($splitTrx);
        });

        $remainingTotal = max(0, $tableTotal - ($splitAmount * $newPaidSplitsCount));

        $splitOutlet = $first?->outlet ? $first->outlet->loadMissing('business') : null;

        return response()->json([
            'message'              => "Pembayaran Patungan Bagian {$splitIndex}/{$totalSplits} berhasil!",
            'order_number'         => $subOrderNumber,
            'parent_order_number'  => $orderNumber,
            'business_name'        => $splitOutlet?->business?->name ?? 'MOVA POS',
            'outlet_name'          => $splitOutlet?->name ?? 'Outlet',
            'outlet_address'       => $splitOutlet?->address,
            'outlet_phone'         => $splitOutlet?->phone,
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
        return response()->json([
            'message' => 'Dilarang menghapus transaksi penjualan! Data transaksi yang sudah tercatat tidak boleh dihapus secara permanen agar riwayat keuangan, laporan kasir, kartu stok, dan audit trail tidak hilang. Silakan gunakan fitur Void / Pembatalan resmi kasir melalui otorisasi supervisor jika transaksi perlu dibatalkan.',
            'error'   => 'TRANSACTION_DELETION_FORBIDDEN',
        ], 422);
    }
}
