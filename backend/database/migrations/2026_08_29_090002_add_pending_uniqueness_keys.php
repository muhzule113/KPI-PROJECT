<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sparepart_requests', function (Blueprint $table): void {
            $table->string('pending_unique_key', 191)->nullable()->unique();
        });

        Schema::table('kpi_correction_requests', function (Blueprint $table): void {
            $table->string('pending_unique_key', 191)->nullable()->unique();
        });

        $duplicateRequests = DB::table('sparepart_requests')
            ->select('service_ticket_id', 'sparepart_id')
            ->where('status', 'pending')
            ->groupBy('service_ticket_id', 'sparepart_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateRequests) {
            throw new RuntimeException('Tidak dapat menambahkan constraint sparepart: terdapat pending request duplikat yang harus direkonsiliasi.');
        }

        $duplicateCorrections = DB::table('kpi_correction_requests')
            ->select('employee_kpi_id')
            ->where('status', 'pending')
            ->groupBy('employee_kpi_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateCorrections) {
            throw new RuntimeException('Tidak dapat menambahkan constraint koreksi: terdapat lebih dari satu pending correction per KPI.');
        }

        DB::table('sparepart_requests')
            ->where('status', 'pending')
            ->update([
                'pending_unique_key' => DB::raw("CONCAT('ticket:', service_ticket_id, ':part:', sparepart_id)"),
            ]);

        DB::table('kpi_correction_requests')
            ->where('status', 'pending')
            ->update([
                'pending_unique_key' => DB::raw("CONCAT('kpi:', employee_kpi_id)"),
            ]);
    }

    public function down(): void
    {
        Schema::table('kpi_correction_requests', function (Blueprint $table): void {
            $table->dropUnique(['pending_unique_key']);
            $table->dropColumn('pending_unique_key');
        });

        Schema::table('sparepart_requests', function (Blueprint $table): void {
            $table->dropUnique(['pending_unique_key']);
            $table->dropColumn('pending_unique_key');
        });
    }
};
