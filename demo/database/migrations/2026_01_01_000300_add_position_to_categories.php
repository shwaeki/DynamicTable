<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A display order for the catalogue, so the row-reorder example has something
 * honest to drag.
 *
 * Indexed, because every page of a reorderable table sorts by this column —
 * which is exactly what dynamic-table:doctor says out loud if you forget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(0)->index()->after('slug');
        });

        // Existing rows all sit at 0, which would leave the example with one
        // undifferentiated block. Seed the order from the ids they already have.
        foreach (DB::table('categories')->orderBy('id')->pluck('id') as $index => $id) {
            DB::table('categories')->where('id', $id)->update(['position' => $index + 1]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['position']);
            $table->dropColumn('position');
        });
    }
};
