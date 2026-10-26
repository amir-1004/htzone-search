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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 16)->unique();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category')->index();
            $table->string('subcategory')->index();
            $table->text('description');
            $table->text('specs');
            $table->string('image_url');
            $table->unsignedInteger('price');
            $table->boolean('price_is_demo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
