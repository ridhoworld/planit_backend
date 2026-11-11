<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestmentController extends Controller
{
    /**
     * Display a listing of investments with portfolio calculations.
     */
    public function index(Request $request): JsonResponse
    {
        $investments = $request->user()->investments()->orderBy('name')->get();

        $totalInvested = (float) $investments->sum('total_invested');
        $totalCurrentValue = (float) $investments->sum('current_value');
        $totalGainLoss = $totalCurrentValue - $totalInvested;
        $totalGainLossPercentage = $totalInvested > 0 ? round(($totalGainLoss / $totalInvested) * 100, 2) : 0;

        // Allocation by type
        $allocation = $investments->groupBy('type')->map(function ($items, $type) use ($totalCurrentValue) {
            $value = (float) $items->sum('current_value');
            return [
                'type' => $type,
                'value' => $value,
                'percentage' => $totalCurrentValue > 0 ? round(($value / $totalCurrentValue) * 100, 1) : 0,
            ];
        })->values();

        return response()->json([
            'investments' => $investments,
            'total_invested' => $totalInvested,
            'total_current_value' => $totalCurrentValue,
            'total_gain_loss' => $totalGainLoss,
            'total_gain_loss_percentage' => $totalGainLossPercentage,
            'allocation' => $allocation,
        ]);
    }

    /**
     * Store a newly created investment asset.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:stock,mutual_fund,gold,crypto,property',
            'units' => 'required|numeric|min:0.0001',
            'avg_buy_price' => 'required|numeric|min:0',
            'current_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['last_price_updated_at'] = Carbon::now();

        $investment = Investment::create($validated);

        return response()->json([
            'message' => 'Aset investasi berhasil ditambahkan',
            'investment' => $investment,
        ], 201);
    }

    /**
     * Update the specified investment asset.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $investment = $request->user()->investments()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:stock,mutual_fund,gold,crypto,property',
            'units' => 'sometimes|required|numeric|min:0.0001',
            'avg_buy_price' => 'sometimes|required|numeric|min:0',
            'current_price' => 'sometimes|required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if (isset($validated['current_price']) && $validated['current_price'] != $investment->current_price) {
            $validated['last_price_updated_at'] = Carbon::now();
        }

        $investment->update($validated);

        return response()->json([
            'message' => 'Aset investasi berhasil diperbarui',
            'investment' => $investment,
        ]);
    }

    /**
     * Update current market price of an investment asset.
     */
    public function updatePrice(Request $request, int $id): JsonResponse
    {
        $investment = $request->user()->investments()->findOrFail($id);

        $validated = $request->validate([
            'current_price' => 'required|numeric|min:0',
        ]);

        $investment->update([
            'current_price' => $validated['current_price'],
            'last_price_updated_at' => Carbon::now(),
        ]);

        return response()->json([
            'message' => 'Harga pasar berhasil diperbarui',
            'investment' => $investment,
        ]);
    }

    /**
     * Remove the specified investment.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $investment = $request->user()->investments()->findOrFail($id);
        $investment->delete();

        return response()->json([
            'message' => 'Aset investasi berhasil dihapus',
        ]);
    }
}

