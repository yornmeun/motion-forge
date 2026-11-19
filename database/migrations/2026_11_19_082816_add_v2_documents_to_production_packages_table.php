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
        Schema::table('production_packages', function (Blueprint $table) {
            // V2 package documents (scene sheet, registries, Kiki reference, execution plan, README) by role
            $table->json('documents')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_packages', function (Blueprint $table) {
            $table->dropColumn('documents');
        });
    }
};
