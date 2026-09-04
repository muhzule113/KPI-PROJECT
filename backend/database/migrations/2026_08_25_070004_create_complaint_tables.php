<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('complaints');

        // Subsistem Complaint Management — record komplain kanal resmi + SLA
        // Feed KPI: CS-05 (jumlah komplain), SUP-04 (penyelesaian komplain tepat waktu)
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // e.g. CMP-202608-001
            $table->date('complaint_date');
            // Karyawan yang menjadi subjek komplain (Pelayan/Kasir/Teknisi)
            $table->foreignUlid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('service_ticket_id')->nullable()->constrained('service_tickets')->nullOnDelete();
            $table->string('channel', 30); // in_store, phone, whatsapp, google_review, other
            $table->string('category', 50)->nullable(); // service, product, cashier, general
            $table->string('severity', 20)->default('medium'); // low, medium, high
            $table->string('status', 20)->default('open'); // open, in_progress, resolved, closed
            $table->text('description');
            $table->timestamp('sla_deadline')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status', 'complaint_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
