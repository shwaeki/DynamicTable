<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a view has been shared with.
 *
 * A separate table rather than a JSON column on the view: "which views can this
 * user see" is the query that runs on every page load, and it needs an index.
 *
 * Sharing grants read access only. Editing, renaming and deleting stay with the
 * owner — recipients can apply a shared view and save their own copy, but never
 * change someone else's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('view_id')
                ->constrained($this->viewsTable())
                ->cascadeOnDelete();

            $table->string('user_id', 64);
            $table->string('shared_by', 64)->nullable();
            $table->timestamps();

            $table->unique(['view_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('dynamic-table.views.shares_table', 'dynamic_table_view_shares');
    }

    private function viewsTable(): string
    {
        return (string) config('dynamic-table.views.table', 'dynamic_table_views');
    }
};
