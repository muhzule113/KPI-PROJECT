<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('attendances');

        // Subsistem Absensi & Kehadiran
        // Feed KPI: ADM-05, KSR-06, GUD-07, CS-06 Pelayan (kehadiran & disiplin), SUP-03 (agregasi tim)
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('attendance_date');
            // present = hadir, late = hadir terlambat, permission = izin, sick_leave = sakit, absent = alpha
            $table->string('status', 20);
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
