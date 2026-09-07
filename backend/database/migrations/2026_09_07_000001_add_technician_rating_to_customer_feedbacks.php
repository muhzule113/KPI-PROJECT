<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_feedbacks', function (Blueprint $table): void {
            $table->foreignUlid('technician_employee_id')
                ->nullable()
                ->after('cs_employee_id')
                ->constrained('employees')
                ->nullOnDelete();
            $table->unsignedTinyInteger('technician_rating')->nullable()->after('rating');
            $table->index(['technician_employee_id', 'technician_rating'], 'customer_feedbacks_technician_rating_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_feedbacks', function (Blueprint $table): void {
            $table->dropIndex('customer_feedbacks_technician_rating_index');
            $table->dropForeign(['technician_employee_id']);
            $table->dropColumn(['technician_employee_id', 'technician_rating']);
        });
    }
};
