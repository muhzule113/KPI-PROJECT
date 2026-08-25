<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_movements');

        // Subsistem Manajemen Inventory — ledger mutasi stok
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sparepart_id')->constrained('spareparts')->cascadeOnDelete();
            // restock_in, request_out, opname_adjustment, return_in
            $table->string('movement_type', 30);
            $table->integer('quantity'); // positif = masuk, negatif = keluar
            $table->integer('stock_before')->nullable();
            $table->integer('stock_after')->nullable();
            $table->string('reference_type', 50)->nullable(); // sparepart_request, stock_opname
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sparepart_id', 'movement_type']);
        });

        // Sesi stock opname
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // e.g. OPN-202608-001
            $table->foreignId('period_id')->nullable()->constrained('kpi_periods')->nullOnDelete();
            $table->string('status', 20)->default('in_progress'); // draft, in_progress, completed
            $table->date('deadline')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Item opname — snapshot stok sistem vs stok fisik
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->cascadeOnDelete();
            $table->foreignId('sparepart_id')->constrained('spareparts')->cascadeOnDelete();
            $table->integer('system_stock')->default(0);
            $table->integer('physical_stock')->nullable();
            $table->integer('difference')->default(0);
            $table->boolean('is_counted')->default(false);
            $table->timestamps();

            $table->unique(['stock_opname_id', 'sparepart_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_movements');
    }
};
