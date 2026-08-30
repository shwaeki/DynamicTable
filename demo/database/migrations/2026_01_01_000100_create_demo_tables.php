<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo domain: a small but realistic company with staff, a catalogue,
 * customers, orders and invoices. Deliberately more than one model, so the
 * examples can show real relationships rather than a toy table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('country', 2)->nullable();
            $table->string('website')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 10)->nullable();
            $table->unsignedInteger('headcount')->default(0);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('level')->default(1);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku', 32)->unique();
            $table->string('status', 20)->default('draft');
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('image_url')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 40)->nullable();
            $table->string('country', 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('lifetime_value', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->string('status', 20)->default('pending');
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->unique();
            $table->string('status', 20)->default('unpaid');
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active')->after('email');
            $table->boolean('is_active')->default(true)->after('status');
            $table->string('phone', 40)->nullable()->after('is_active');
            $table->decimal('salary', 10, 2)->nullable()->after('phone');
            $table->string('avatar_url')->nullable()->after('salary');
            $table->timestamp('joined_at')->nullable()->after('avatar_url');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'company_id', 'department_id', 'role_id', 'status',
                'is_active', 'phone', 'salary', 'avatar_url', 'joined_at',
            ]);
        });

        foreach (['invoices', 'order_items', 'orders', 'customers', 'products', 'categories', 'roles', 'departments', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
