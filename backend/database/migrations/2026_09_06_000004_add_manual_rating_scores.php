<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_rating_bands', function (Blueprint $table): void {
            $table->decimal('manual_score', 8, 2)->nullable()->after('max_score');
        });

        DB::table('kpi_rating_bands')->where('code', 'FAIR')->update(['manual_score' => 75.00]);
        DB::table('kpi_rating_bands')->where('code', 'GOOD')->update(['manual_score' => 85.00]);
        DB::table('kpi_rating_bands')->where('code', 'VERY_GOOD')->update(['manual_score' => 95.00]);
    }

    public function down(): void
    {
        Schema::table('kpi_rating_bands', function (Blueprint $table): void {
            $table->dropColumn('manual_score');
        });
    }
};
