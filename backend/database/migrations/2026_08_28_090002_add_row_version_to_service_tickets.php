<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->unsignedInteger('row_version')->default(1)->after('status');
            $table->index(['branch_id', 'row_version']);
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropIndex('service_tickets_branch_id_row_version_index');
            $table->dropColumn('row_version');
        });
    }
};
