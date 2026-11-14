<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestGeminiCommand extends Command
{
    protected $signature = 'gemini:test';
    protected $description = 'Tes koneksi dan kevalidan Google Gemini API Key';

    public function handle(): int
    {
        $key = config('services.gemini.key') ?? env('GEMINI_API_KEY');

        $this->info('=========================================');
        $this->info('  PLANIT - TES GOOGLE GEMINI API KEY     ');
        $this->info('=========================================');

        if (empty($key)) {
            $this->error('❌ GEMINI_API_KEY belum diisi di file .env!');
            $this->warn('Silakan tambahkan GEMINI_API_KEY=... di backend/.env');
            return Command::FAILURE;
        }

        $maskedKey = substr($key, 0, 8) . '...' . substr($key, -4);
        $this->line("Memeriksa API Key: {$maskedKey}");

        // First list models
        $this->line("1. Mengambil daftar model yang tersedia untuk API Key ini...");
        $listRes = Http::timeout(15)->get("https://generativelanguage.googleapis.com/v1beta/models?key={$key}");
        if ($listRes->failed()) {
            $this->error('❌ API Key tidak valid atau ditolak oleh Google (HTTP ' . $listRes->status() . ')');
            $this->warn('Respon Google:');
            $this->line($listRes->body());
            return Command::FAILURE;
        }

        $modelsData = $listRes->json()['models'] ?? [];
        $availableModelNames = array_map(fn($m) => str_replace('models/', '', $m['name']), $modelsData);
        $this->info("Ditemukan " . count($availableModelNames) . " model yang didukung.");
        $this->line("Daftar model: " . implode(', ', array_slice($availableModelNames, 0, 15)));

        $candidateModels = [
            'gemini-2.5-flash-lite',
            'gemini-flash-lite-latest',
            'gemini-flash-latest',
            'gemini-2.5-pro',
            'gemini-pro-latest',
        ];

        $testedModel = null;
        $response = null;

        foreach ($candidateModels as $model) {
            $this->line("Menguji model: {$model}...");
            $res = Http::timeout(20)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => 'Halo! Balas dalam 1 kalimat ramah: "Koneksi Google Gemini API berhasil terhubung dan siap digunakan!"'],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 100,
                ],
            ]);

            if ($res->successful()) {
                $testedModel = $model;
                $response = $res;
                break;
            } else {
                $this->warn("Model {$model} merespon: HTTP " . $res->status());
            }
        }

        if ($response && $response->successful()) {
            $json = $response->json();
            $reply = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $this->newLine();
            $this->info('✅ KONEKSI BERHASIL (HTTP 200 OK)!');
            $this->line("Model Aktif : {$testedModel}");
            $this->line("Status AI   : AKTIF & SIAP PAKAI");
            $this->line("Respon AI   : " . trim($reply));
            $this->info('=========================================');
            $this->info('Fitur Scan Struk AI dan Copilot di aplikasi PlanIt sekarang sudah beroperasi penuh secara nyata!');
            return Command::SUCCESS;
        } else {
            $this->newLine();
            $this->error('❌ KONEKSI GAGAL');
            $this->warn('Detail pesan dari Google API:');
            $this->line($res ? $res->body() : 'Tidak ada respon');
            return Command::FAILURE;
        }
    }
}
