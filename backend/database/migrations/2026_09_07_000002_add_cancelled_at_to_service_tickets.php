<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->nullable()->after('delivered_at');
            $table->index(['status', 'cancelled_at']);
        });

        DB::table('service_tickets')
            ->where('status', 'cancelled')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->dropIndex(['status', 'cancelled_at']);
            $table->dropColumn('cancelled_at');
        });
    }
};
