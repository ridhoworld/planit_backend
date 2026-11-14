<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAiService
{
    protected ?string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key') ?? env('GEMINI_API_KEY');
    }

    /**
     * Check if live Gemini API is configured.
     */
    public function hasApiKey(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Scan receipt / transfer proof image using Gemini Vision (or smart fallback).
     */
    public function scanReceipt(string $base64Image, string $mimeType = 'image/jpeg', int $userId = 0): array
    {
        // Fetch categories available to this user for smart matching
        $categories = Category::where(function ($q) use ($userId) {
                $q->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->where('is_active', true)
            ->get(['id', 'name', 'type'])
            ->toArray();

        $catListStr = json_encode(array_map(function ($c) {
            return ['id' => $c['id'], 'name' => $c['name'], 'type' => $c['type']];
        }, $categories));

        $apiError = null;
        if ($this->hasApiKey()) {
            try {
                $cleanBase64 = preg_replace('#^data:image/\w+;base64,#i', '', $base64Image);
                $cleanBase64 = str_replace(["\r", "\n", ' '], '', $cleanBase64);

                $prompt = <<<PROMPT
Anda adalah asisten AI OCR keuangan untuk aplikasi pencatat keuangan "PlanIt".
Tugas Anda adalah membaca gambar struk belanja fisik, bukti pembayaran QRIS, atau tangkapan layar bukti transfer bank/e-wallet.

Daftar kategori yang tersedia di aplikasi:
{$catListStr}

Ekstrak informasi berikut dan WAJIB kembalikan dalam format JSON murni:
{
  "merchant": "Nama toko / merchant / penerima transfer (contoh: Indomaret, Starbucks, Transfer ke Budi)",
  "amount": 54000, // Total nominal akhir yang dibayar/diterima dalam bentuk angka integer/float tanpa titik/koma pemisah ribuan
  "date": "2026-11-13", // Tanggal transaksi format YYYY-MM-DD (jika tahun tidak terbaca, gunakan tahun saat ini)
  "type": "expense", // "expense" jika pengeluaran atau pembayaran belanja/transfer keluar, "income" jika bukti transfer masuk/gaji
  "suggested_category_id": 1, // ID kategori dari daftar di atas yang paling cocok, atau null jika tidak ada yang cocok
  "suggested_category_name": "Makanan & Minuman", // Nama kategori yang cocok
  "notes": "Deskripsi singkat barang yang dibeli atau tujuan transaksi",
  "items": [
    {"name": "Nama item", "price": 25000, "qty": 1}
  ],
  "confidence": 0.95
}
Jika gambar buram atau bukan bukti keuangan, berikan tebakan terbaik dengan confidence rendah.
PROMPT;

                $response = Http::timeout(30)->post("{$this->baseUrl}/gemini-1.5-flash:generateContent?key={$this->apiKey}", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => $cleanBase64,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'response_mime_type' => 'application/json',
                        'temperature' => 0.1,
                    ],
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
                    $parsed = json_decode($text, true);

                    if (is_array($parsed) && isset($parsed['amount'])) {
                        $parsed['is_ai_live'] = true;
                        return $parsed;
                    }
                } else {
                    $apiError = "HTTP " . $response->status() . ": " . $response->body();
                    Log::warning('Gemini Receipt Scan Error: ' . $apiError);
                }
            } catch (\Exception $e) {
                $apiError = $e->getMessage();
                Log::error('Gemini Receipt Scan Exception: ' . $apiError);
            }
        }

        // Smart Heuristic Fallback when API key is not present or offline
        return $this->heuristicReceiptFallback($categories, $apiError);
    }

    /**
     * Fallback receipt parsing simulator for seamless development & demo.
     */
    protected function heuristicReceiptFallback(array $categories, ?string $apiError = null): array
    {
        $defaultCat = !empty($categories) ? $categories[0] : ['id' => 1, 'name' => 'Belanja & Kebutuhan'];
        // Pick groceries or food if available
        foreach ($categories as $c) {
            if (stripos($c['name'], 'Makanan') !== false || stripos($c['name'], 'Belanja') !== false) {
                $defaultCat = $c;
                break;
            }
        }

        $notice = empty($this->apiKey)
            ? 'GEMINI_API_KEY belum dipasang di backend/.env. Hasil nominal diisi contoh Rp 45.000 (Mode Simulasi).'
            : ($apiError ? "Gagal menghubungi Gemini API ({$apiError}). Beralih ke data simulasi." : 'Mode simulasi bawaan.');

        return [
            'merchant' => 'Minimarket (Simulasi)',
            'amount' => 45000,
            'date' => date('Y-m-d'),
            'type' => 'expense',
            'suggested_category_id' => $defaultCat['id'],
            'suggested_category_name' => $defaultCat['name'],
            'notes' => 'Pembelian harian (Hasil Mode Simulasi)',
            'items' => [
                ['name' => 'Bahan Makanan / Snack', 'price' => 30000, 'qty' => 1],
                ['name' => 'Minuman Dingin', 'price' => 15000, 'qty' => 1],
            ],
            'confidence' => 0.5,
            'is_ai_live' => false,
            'ai_notice' => $notice,
        ];
    }

    /**
     * Contextual Financial Chat with AI Financial Copilot.
     */
    public function financialChat(User $user, string $userMessage, array $chatHistory = []): array
    {
        // 1. Gather comprehensive financial context for this user
        $context = $this->buildUserFinancialContext($user);

        if ($this->hasApiKey()) {
            try {
                $systemPrompt = <<<SYS
Anda adalah "PlanIt Copilot", asisten dan penasihat keuangan pribadi cerdas berwawasan luas, ramah, bijak, dan berbahasa Indonesia santun namun profesional.
Anda mendampingi pengguna bernama {$user->name} dalam mengelola keuangan, tabungan, anggaran, dan investasi mereka.

DATA KONDISI KEUANGAN PENGGUNA SAAT INI:
- Mata Uang Utama: {$context['currency']}
- Total Saldo Bersih (Net Worth): {$context['formatted_net_worth']}
- Rincian Saldo Akun / Dompet: {$context['accounts_summary']}
- Pemasukan Bulan Ini: {$context['formatted_monthly_income']}
- Pengeluaran Bulan Ini: {$context['formatted_monthly_expense']}
- Tabungan Bersih Bulan Ini (Net Savings): {$context['formatted_net_savings']} (Rasio Tabungan: {$context['savings_rate']}%)
- Status Anggaran (Budgets): {$context['budgets_summary']}
- Target Tabungan (Financial Goals): {$context['goals_summary']}
- Tagihan / Transaksi Rutin Mendatang: {$context['recurring_summary']}
- Status Utang & Piutang: {$context['debts_summary']}

PANDUAN MENJAWAB:
1. Jawablah langsung, ringkas, terstruktur (gunakan bullet points, bolding teks, dan format angka Rupiah yang mudah dibaca).
2. Dasarkan analisis dan saran Anda pada data keuangan riil pengguna di atas.
3. Berikan saran yang solutif, realistis, dan memotivasi tanpa menggurui secara berlebihan.
4. Jika pengguna bertanya tentang anggaran, jelaskan pos mana yang sehat dan pos mana yang hampir habis/melebihi batas.
SYS;

                $contents = [];
                // Add system instructions
                $contents[] = [
                    'role' => 'user',
                    'parts' => [['text' => "System Instructions:\n" . $systemPrompt]],
                ];
                $contents[] = [
                    'role' => 'model',
                    'parts' => [['text' => "Halo {$user->name}! Saya PlanIt Copilot, asisten keuangan pribadi Anda. Saya telah membaca seluruh ringkasan kondisi keuangan Anda dan siap membantu menganalisis arus kas, anggaran, dan target finansial Anda. Ada yang bisa saya bantu hari ini?"]],
                ];

                // Append recent chat history
                foreach (array_slice($chatHistory, -6) as $msg) {
                    $role = ($msg['sender'] ?? 'user') === 'user' ? 'user' : 'model';
                    $contents[] = [
                        'role' => $role,
                        'parts' => [['text' => $msg['text'] ?? '']],
                    ];
                }

                // Append current user message
                $contents[] = [
                    'role' => 'user',
                    'parts' => [['text' => $userMessage]],
                ];

                $response = Http::timeout(30)->post("{$this->baseUrl}/gemini-1.5-flash:generateContent?key={$this->apiKey}", [
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 800,
                    ],
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    $reply = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    if (!empty($reply)) {
                        return [
                            'reply' => trim($reply),
                            'is_ai_live' => true,
                            'quick_suggestions' => $this->generateFollowUpSuggestions($context),
                        ];
                    }
                } else {
                    Log::warning('Gemini Chat Error: ' . $response->body());
                }
            } catch (\Exception $e) {
                Log::error('Gemini Chat Exception: ' . $e->getMessage());
            }
        }

        // Context-aware heuristic advisor fallback
        return $this->heuristicFinancialAdvisor($user, $userMessage, $context);
    }

    /**
     * Build structured financial data context.
     */
    protected function buildUserFinancialContext(User $user): array
    {
        $userId = $user->id;
        $currency = $user->primary_currency ?? $user->primaryCurrency ?? 'IDR';

        // Accounts
        $accounts = \App\Models\Account::where('user_id', $userId)->where('is_active', true)->get();
        $totalBalance = $accounts->sum('current_balance');
        $accSummaryList = [];
        foreach ($accounts as $a) {
            $accSummaryList[] = "{$a->name} ({$a->type}): Rp " . number_format($a->current_balance, 0, ',', '.');
        }
        $accountsSummary = empty($accSummaryList) ? 'Belum ada akun dompet aktif' : implode(', ', $accSummaryList);

        // Cashflow this month
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $monthlyIncome = \App\Models\Transaction::where('user_id', $userId)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $monthlyExpense = \App\Models\Transaction::where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $netSavings = $monthlyIncome - $monthlyExpense;
        $savingsRate = $monthlyIncome > 0 ? round(($netSavings / $monthlyIncome) * 100, 1) : 0;

        // Budgets
        $budgets = \App\Models\Budget::where('user_id', $userId)
            ->where('period', 'monthly')
            ->with('category')
            ->get();
        $bList = [];
        foreach ($budgets as $b) {
            $catName = $b->category ? $b->category->name : 'Kategori';
            $spent = \App\Models\Transaction::where('user_id', $userId)
                ->where('category_id', $b->category_id)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');
            $pct = $b->amount_limit > 0 ? round(($spent / $b->amount_limit) * 100) : 0;
            $status = $pct >= 100 ? 'MELEBIHI BATAS' : ($pct >= 80 ? 'WASPADA' : 'AMAN');
            $bList[] = "{$catName}: Terpakai Rp " . number_format($spent, 0, ',', '.') . " dari batas Rp " . number_format($b->amount_limit, 0, ',', '.') . " ({$pct}% - {$status})";
        }
        $budgetsSummary = empty($bList) ? 'Belum ada anggaran bulanan yang dibuat' : implode('; ', $bList);

        // Goals
        $goals = \App\Models\FinancialGoal::where('user_id', $userId)->where('status', 'in_progress')->get();
        $gList = [];
        foreach ($goals as $g) {
            $pct = $g->target_amount > 0 ? round(($g->current_amount / $g->target_amount) * 100) : 0;
            $gList[] = "{$g->name}: Rp " . number_format($g->current_amount, 0, ',', '.') . " / Rp " . number_format($g->target_amount, 0, ',', '.') . " ({$pct}%)";
        }
        $goalsSummary = empty($gList) ? 'Belum ada target tabungan aktif' : implode('; ', $gList);

        // Debts
        $debts = \App\Models\Debt::where('user_id', $userId)->where('remaining_amount', '>', 0)->get();
        $payable = $debts->where('type', 'payable')->sum('remaining_amount');
        $receivable = $debts->where('type', 'receivable')->sum('remaining_amount');
        $debtsSummary = "Total Utang Saya: Rp " . number_format($payable, 0, ',', '.') . ", Total Piutang Saya: Rp " . number_format($receivable, 0, ',', '.');

        // Recurring bills
        $bills = \App\Models\RecurringTransaction::where('user_id', $userId)
            ->where('is_active', true)
            ->where('next_due_date', '<=', now()->addDays(30)->toDateString())
            ->get();
        $bSummaryList = [];
        foreach ($bills as $bill) {
            $bSummaryList[] = "{$bill->title}: Rp " . number_format($bill->amount, 0, ',', '.') . " (Jatuh tempo: {$bill->next_due_date})";
        }
        $recurringSummary = empty($bSummaryList) ? 'Tidak ada tagihan mendesak dalam 30 hari' : implode('; ', $bSummaryList);

        return [
            'currency' => $currency,
            'formatted_net_worth' => 'Rp ' . number_format($totalBalance, 0, ',', '.'),
            'accounts_summary' => $accountsSummary,
            'formatted_monthly_income' => 'Rp ' . number_format($monthlyIncome, 0, ',', '.'),
            'formatted_monthly_expense' => 'Rp ' . number_format($monthlyExpense, 0, ',', '.'),
            'formatted_net_savings' => 'Rp ' . number_format($netSavings, 0, ',', '.'),
            'savings_rate' => $savingsRate,
            'budgets_summary' => $budgetsSummary,
            'goals_summary' => $goalsSummary,
            'recurring_summary' => $recurringSummary,
            'debts_summary' => $debtsSummary,
            'raw_income' => $monthlyIncome,
            'raw_expense' => $monthlyExpense,
            'raw_balance' => $totalBalance,
        ];
    }

    /**
     * Context-aware heuristic financial analysis response generator.
     */
    protected function heuristicFinancialAdvisor(User $user, string $query, array $context): array
    {
        $q = strtolower($query);

        if (str_contains($q, 'anggaran') || str_contains($q, 'budget') || str_contains($q, 'boros')) {
            $reply = "### 📊 Analisis Anggaran Bulan Ini\n\n";
            $reply .= "Berikut adalah status pos anggaran Anda saat ini:\n";
            $reply .= "• **Status Kategori**: {$context['budgets_summary']}\n\n";
            $reply .= "**Rekomendasi Tindakan:**\n";
            $reply .= "1. Prioritaskan pos kebutuhan pokok dan tunda pengeluaran impulsif pada kategori yang mendekati batas 80%.\n";
            $reply .= "2. Manfaatkan fitur *Notifikasi Anggaran* agar Anda segera diperingatkan saat sisa anggaran menipis.";
        } elseif (str_contains($q, 'target') || str_contains($q, 'nabung') || str_contains($q, 'goal')) {
            $reply = "### 🎯 Progres Target Finansial Anda\n\n";
            $reply .= "• **Target Tabungan Aktif**: {$context['goals_summary']}\n";
            $reply .= "• **Sisa Arus Kas Bulan Ini**: {$context['formatted_net_savings']}\n\n";
            $reply .= "**Tips Mempercepat Target:**\n";
            $reply .= "1. Sisihkan minimal 20% dari setiap pemasukan begitu gaji/pendapatan masuk (metode *Pay Yourself First*).\n";
            $reply .= "2. Alihkan dana surplus dari pos yang hemat langsung ke dompet target tabungan.";
        } else {
            // General overview
            $reply = "### 💡 Ringkasan Kesehatan Finansial ({$user->name})\n\n";
            $reply .= "Halo **{$user->name}**, berikut adalah potret keuangan Anda per hari ini:\n\n";
            $reply .= "• **Total Saldo Bersih**: {$context['formatted_net_worth']}\n";
            $reply .= "• **Pemasukan Bulan Ini**: {$context['formatted_monthly_income']}\n";
            $reply .= "• **Pengeluaran Bulan Ini**: {$context['formatted_monthly_expense']}\n";
            $reply .= "• **Rasio Tabungan (Savings Rate)**: **{$context['savings_rate']}%**\n";
            $reply .= "• **Status Utang & Piutang**: {$context['debts_summary']}\n\n";

            if ($context['savings_rate'] >= 20) {
                $reply .= "🌟 **Performa Bagus!** Rasio tabungan Anda berada di atas 20%. Anda berada di jalur yang sangat sehat untuk mencapai kebebasan finansial.";
            } elseif ($context['raw_expense'] > $context['raw_income']) {
                $reply .= "⚠️ **Perhatian Arus Kas Negatif**: Pengeluaran bulan ini melebihi pemasukan Anda. Disarankan untuk meninjau kembali transaksi pengeluaran dan menunda pos non-esensial.";
            } else {
                $reply .= "✅ Arus kas Anda positif. Targetkan untuk meningkatkan rasio tabungan hingga minimal 20% sesuai prinsip 50/30/20.";
            }
        }

        return [
            'reply' => $reply,
            'is_ai_live' => false,
            'quick_suggestions' => $this->generateFollowUpSuggestions($context),
            'ai_notice' => 'Analisis berbasis data riil PlanIt. Pasang GEMINI_API_KEY di backend/.env untuk konsultasi interaktif penuh via Gemini 1.5 Flash.',
        ];
    }

    protected function generateFollowUpSuggestions(array $context): array
    {
        return [
            'Bulan ini pengeluaranku paling boros di mana?',
            'Berapa sisa uang aman yang bisa kupakai?',
            'Bagaimana cara mencapai target tabungan lebih cepat?',
            'Analisis rasio 50/30/20 dari pengeluaranku',
        ];
    }
}

