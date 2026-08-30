<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // Which DynamicTable this view belongs to (e.g. "users").
            $table->string('table_key', 100)->index();

            // Null for system views, otherwise the owning user.
            $table->string('user_id', 64)->nullable()->index();

            $table->string('name', 150);
            $table->string('icon', 50)->nullable();

            // Declarative state: columns, filters, sort, search, grouping.
            $table->json('configuration');

            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->string('created_by', 64)->nullable();
            $table->string('updated_by', 64)->nullable();

            $table->timestamps();

            $table->index(['table_key', 'user_id', 'is_default'], 'dt_views_lookup_index');
            $table->index(['table_key', 'is_system'], 'dt_views_system_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('dynamic-table.views.table', 'dynamic_table_views');
    }
};
