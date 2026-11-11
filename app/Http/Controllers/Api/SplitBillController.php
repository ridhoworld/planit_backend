<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SplitBillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bills = $request->user()->splitBills()->with('participants')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'split_bills' => $bills,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:1',
            'notes' => 'nullable|string',
            'participants' => 'required|array|min:1',
            'participants.*.name' => 'required|string|max:255',
            'participants.*.amount' => 'required|numeric|min:0',
            'record_as_receivable' => 'nullable|boolean',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $bill = SplitBill::create([
                'user_id' => $request->user()->id,
                'title' => $validated['title'],
                'total_amount' => $validated['total_amount'],
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['participants'] as $p) {
                $participant = SplitBillParticipant::create([
                    'split_bill_id' => $bill->id,
                    'name' => $p['name'],
                    'amount' => $p['amount'],
                    'is_paid' => false,
                ]);

                // FR-5.1: Automatically create receivable if requested
                if (!empty($validated['record_as_receivable']) && $p['amount'] > 0) {
                    Debt::create([
                        'user_id' => $request->user()->id,
                        'type' => 'receivable',
                        'party_name' => $p['name'] . ' (Patungan: ' . $bill->title . ')',
                        'total_amount' => $p['amount'],
                        'remaining_amount' => $p['amount'],
                        'due_date' => Carbon::now()->addDays(7)->toDateString(),
                        'notes' => 'Tagihan patungan ' . $bill->title,
                    ]);
                }
            }

            return response()->json([
                'message' => 'Patungan tagihan berhasil dibuat',
                'split_bill' => $bill->load('participants'),
            ], 201);
        });
    }

    public function toggleParticipantPaid(Request $request, int $billId, int $participantId): JsonResponse
    {
        $bill = $request->user()->splitBills()->findOrFail($billId);
        $participant = $bill->participants()->findOrFail($participantId);

        $participant->is_paid = !$participant->is_paid;
        $participant->save();

        return response()->json([
            'message' => 'Status pembayaran peserta berhasil diubah',
            'participant' => $participant,
            'split_bill' => $bill->fresh('participants'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $bill = $request->user()->splitBills()->findOrFail($id);
        $bill->delete();

        return response()->json([
            'message' => 'Patungan tagihan berhasil dihapus',
        ]);
    }
}

