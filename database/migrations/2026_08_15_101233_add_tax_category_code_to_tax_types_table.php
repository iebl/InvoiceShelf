<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the EN 16931 tax category code (BT-151) and exemption reason
     * (BT-121) to tax types.
     *
     * The code column is NOT NULL with an `S` (standard rate) default, so
     * existing tax types are backfilled as the column is created.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tax_types', 'tax_category_code')) {
            return;
        }

        Schema::table('tax_types', function (Blueprint $table) {
            // Literal rather than TaxType::TAX_CATEGORY_CODE_STANDARD: a
            // migration must keep describing the schema it created even if
            // the model's default moves.
            $table->string('tax_category_code', 4)
                ->default('S')
                ->after('percent');
            $table->string('tax_exemption_reason', 255)
                ->nullable()
                ->after('tax_category_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_types', function (Blueprint $table) {
            $table->dropColumn(['tax_category_code', 'tax_exemption_reason']);
        });
    }
};
