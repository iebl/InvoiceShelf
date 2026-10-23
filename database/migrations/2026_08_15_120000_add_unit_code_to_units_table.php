<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the UN/ECE Rec 20 Unit Code (EN 16931 BT-130) to units.
     *
     * The column is NOT NULL with a `C62` (piece) default, so existing units
     * are backfilled with the default code as the column is created.
     */
    public function up(): void
    {
        if (Schema::hasColumn('units', 'unit_code')) {
            return;
        }

        Schema::table('units', function (Blueprint $table) {
            // Literal rather than Unit::DEFAULT_UNIT_CODE: a migration must keep
            // describing the schema it created even if the model's default moves.
            $table->string('unit_code', 3)
                ->default('C62')
                ->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('unit_code');
        });
    }
};
