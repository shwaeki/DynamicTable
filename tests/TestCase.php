<?php

namespace Shwaeki\DynamicTable\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Shwaeki\DynamicTable\DynamicTableServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [DynamicTableServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('dynamic-table.cache.metadata', false);
        $app['config']->set('dynamic-table.tables.paths', []);
        $app['config']->set('dynamic-table.tables.register', [
            'users' => Fixtures\UsersTable::class,
            'full_users' => Fixtures\FullUsersTable::class,
            'extras_users' => Fixtures\ExtrasUsersTable::class,
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('status', 20)->default('active');
            $table->boolean('is_active')->default(true);
            $table->decimal('salary', 10, 2)->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->json('settings')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('role_id')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
