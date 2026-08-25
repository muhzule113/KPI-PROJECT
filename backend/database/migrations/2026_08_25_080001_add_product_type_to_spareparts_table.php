<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perluas katalog 'spareparts' jadi katalog produk toko secara umum
        // (sparepart, handset HP, tablet/iPad, aksesoris, dll) tanpa merusak relasi yang ada.
        Schema::table('spareparts', function (Blueprint $table) {
            $table->string('product_type', 30)->default('sparepart')->after('code')
                ->comment('sparepart, handset, tablet, aksesoris, lainnya');
        });
    }

    public function down(): void
    {
        Schema::table('spareparts', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }
};