<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Packed float32 vector (see App\Search\VectorMath::pack).
            $table->binary('embedding')->nullable();
            // Hash of the text that was embedded, so unchanged products are skipped.
            $table->string('embedding_hash', 40)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_hash']);
        });
    }
};
