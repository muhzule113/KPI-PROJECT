<x-filament-panels::page>
    <div class="flex flex-col gap-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div>
                <h2 class="text-lg font-bold text-gray-950 dark:text-white">
                    {{ $this->record->employee?->name }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->record->employee?->position?->name ?? '-' }}
                    · {{ $this->record->employee?->branch?->name ?? '-' }}
                    · Periode: {{ $this->record->period?->name ?? '-' }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::badge :color="$this->record->status === 'submitted' ? 'warning' : 'info'">
                    {{ $this->record->status === 'submitted' ? 'Menunggu Review' : 'Sedang Direview' }}
                </x-filament::badge>
                @if ($this->record->final_score !== null)
                    <x-filament::badge color="success">
                        Skor: {{ number_format($this->record->final_score, 2) }}
                        · {{ $this->record->rating_label ?? '-' }}
                    </x-filament::badge>
                @endif
            </div>
        </div>

        <x-filament::section heading="Daftar Indikator KPI">
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Verifikasi item objektif (Valid / Minta Revisi) dan isi rubrik untuk item subjektif (SOP, kerapian, pelayanan).
                Setelah semua item berstatus <strong>Terverifikasi</strong> / <strong>Dinilai</strong>, teruskan ke Manager.
            </div>

            <div class="mt-4">
                {{ $this->table }}
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
