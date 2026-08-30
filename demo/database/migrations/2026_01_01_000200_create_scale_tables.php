<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three identical tables, one per scale, so each example genuinely holds the
 * number of rows it claims. They stay empty until you seed them:
 *
 *   php artisan dynamic-table:scale 100k
 *   php artisan dynamic-table:scale 1m
 *   php artisan dynamic-table:scale 10m
 *
 * The schema is deliberately narrow and indexed on exactly the columns the
 * examples sort and filter by. That is the honest lesson of the large-data
 * examples: the package generates efficient SQL, but only your indexes make it
 * fast.
 */
return new class extends Migration
{
    private const TABLES = ['scale_events_100k', 'scale_events_1m', 'scale_events_10m'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 16);
                $table->string('category', 20);
                $table->string('region', 2);
                $table->string('status', 12);
                $table->decimal('amount', 10, 2);
                $table->unsignedInteger('quantity');
                $table->boolean('is_flagged')->default(false);
                $table->timestamp('occurred_at');

                $table->index('reference');
                $table->index('occurred_at');
                $table->index('category');
                $table->index('region');

                // The pairing the examples filter and sort on together.
                $table->index(['status', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::dropIfExists($name);
        }
    }
};
