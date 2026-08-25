<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('customer_feedbacks');
        Schema::dropIfExists('sparepart_requests');
        Schema::dropIfExists('service_tickets');
        Schema::dropIfExists('spareparts');

        // 1. Spareparts Catalog & Stock
        Schema::create('spareparts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('category', 50); // LCD, Baterai, IC, Fleksibel, Housing, Kamera, Speaker, Port
            $table->string('compatible_models', 255)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('min_stock_alert')->default(5);
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->boolean('is_critical')->default(false);
            $table->timestamps();

            $table->index(['category', 'is_critical']);
        });

        // 2. Service Tickets
        Schema::create('service_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 50)->unique(); // e.g. SRV-202608-0001
            
            // Customer Info
            $table->string('customer_name', 100);
            $table->string('customer_phone', 30);
            $table->text('customer_address')->nullable();

            // Device Info
            $table->string('device_brand', 50); // Apple, Samsung, Xiaomi, etc.
            $table->string('device_model', 100); // iPhone 13 Pro, Galaxy A54
            $table->string('imei_or_serial', 100)->nullable();
            $table->string('passcode_or_pattern', 50)->nullable();
            $table->text('physical_condition')->nullable(); // Goresan, LCD pecah, dll.
            $table->text('initial_complaint'); // Mati total, layar bergaris, batre drop

            // Cost & Timeline
            $table->decimal('estimated_cost', 15, 2)->default(0);
            $table->decimal('final_cost', 15, 2)->default(0);
            $table->dateTime('estimated_completion_at')->nullable();

            // Assignments & Relational
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('kpi_periods')->nullOnDelete();
            $table->foreignUlid('intake_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUlid('technician_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // Statuses
            $table->string('status', 40)->default('intake'); 
            // intake, diagnosing, waiting_sparepart, in_progress, qc_ready, completed, cancelled_unrepairable, delivered
            $table->string('result_status', 30)->default('pending'); 
            // pending, success, unrepairable, warranty_return

            // Technical Notes
            $table->text('diagnosis_notes')->nullable();
            $table->text('action_notes')->nullable();
            $table->json('qc_checklist_json')->nullable(); // checklist status kamera, LCD, sinyal, mic, charging, dll.

            // Warranty & Retur tracking
            $table->boolean('is_warranty_return')->default(false);
            $table->foreignId('warranty_returned_from_ticket_id')->nullable()->constrained('service_tickets')->nullOnDelete();

            // Timestamps
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['technician_employee_id', 'status', 'period_id']);
            $table->index(['intake_by_employee_id', 'status']);
        });

        // 3. Sparepart Requests per Ticket
        Schema::create('sparepart_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained('service_tickets')->cascadeOnDelete();
            $table->foreignId('sparepart_id')->constrained('spareparts')->cascadeOnDelete();
            $table->foreignUlid('technician_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUlid('warehouse_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('status', 30)->default('pending'); // pending, fulfilled, rejected
            $table->dateTime('requested_at');
            $table->dateTime('fulfilled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['service_ticket_id', 'status']);
        });

        // 4. Customer Feedback & CSAT
        Schema::create('customer_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained('service_tickets')->cascadeOnDelete();
            $table->foreignUlid('cs_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('customer_name', 100);
            $table->tinyInteger('rating')->default(5); // 1 to 5 stars
            $table->text('comments')->nullable();
            $table->boolean('follow_up_ontime')->default(true);
            $table->string('feedback_channel', 30)->default('in_store'); // in_store, whatsapp, phone
            $table->timestamps();

            $table->index(['cs_employee_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_feedbacks');
        Schema::dropIfExists('sparepart_requests');
        Schema::dropIfExists('service_tickets');
        Schema::dropIfExists('spareparts');
    }
};
