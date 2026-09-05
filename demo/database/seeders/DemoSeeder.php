<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Deterministic demo data.
 *
 * The seed is fixed so the examples and the browser tests always see the same
 * rows — an example that shows different numbers on every reload is a poor
 * teaching tool.
 */
class DemoSeeder extends Seeder
{
    private const COMPANIES = ['Northwind', 'Umbrella', 'Initech', 'Globex'];

    private const DEPARTMENTS = ['IT', 'HR', 'Sales', 'Support', 'Finance', 'Marketing'];

    private const ROLES = ['Admin', 'Manager', 'Member', 'Guest'];

    private const CATEGORIES = ['Laptops', 'Monitors', 'Keyboards', 'Audio', 'Storage', 'Accessories'];

    private const COUNTRIES = ['US', 'GB', 'DE', 'JO', 'IL', 'RU', 'AE', 'FR'];

    private const FIRST_NAMES = [
        'Ahmad', 'Omar', 'Sara', 'Lina', 'Ali', 'Noor', 'Yusuf', 'Layla',
        'Daniel', 'Maya', 'Ivan', 'Olga', 'Hannah', 'Tomer', 'Grace', 'Ada',
    ];

    private const LAST_NAMES = [
        'Haddad', 'Khoury', 'Nasser', 'Barakat', 'Levi', 'Cohen',
        'Ivanov', 'Petrova', 'Smith', 'Turing', 'Hopper', 'Lovelace',
    ];

    public function run(): void
    {
        mt_srand(20260829);

        $companies = collect(self::COMPANIES)->map(fn (string $name, int $i): Company => Company::create([
            'name' => $name,
            'country' => self::COUNTRIES[$i],
            'website' => 'https://'.Str::slug($name).'.example.com',
        ]));

        $departments = collect(self::DEPARTMENTS)->map(fn (string $name, int $i): Department => Department::create([
            'company_id' => $companies[$i % $companies->count()]->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)),
            'headcount' => 5 + $i * 3,
        ]));

        $roles = collect(self::ROLES)->map(fn (string $name, int $i): Role => Role::create([
            'name' => $name,
            'level' => $i + 1,
        ]));

        // The position is seeded rather than left at its default, so the
        // row-reorder example opens on a catalogue that is already in an order
        // somebody could disagree with.
        $categories = collect(self::CATEGORIES)->values()->map(fn (string $name, int $index): Category => Category::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'position' => $index + 1,
        ]));

        $this->seedUsers($companies, $departments, $roles);
        $products = $this->seedProducts($categories);
        $customers = $this->seedCustomers($companies);
        $this->seedOrders($customers, $products);
    }

    private function seedUsers($companies, $departments, $roles): void
    {
        $statuses = UserStatus::cases();

        for ($i = 1; $i <= 240; $i++) {
            $first = self::FIRST_NAMES[$i % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[$i % count(self::LAST_NAMES)];

            User::create([
                'company_id' => $companies[$i % $companies->count()]->id,
                'department_id' => $departments[$i % $departments->count()]->id,
                'role_id' => $roles[$i % $roles->count()]->id,
                'name' => "{$first} {$last}",
                'email' => strtolower("{$first}.{$last}{$i}@example.com"),
                'password' => 'password',
                'status' => $statuses[$i % count($statuses)]->value,
                'is_active' => $i % 7 !== 0,
                'phone' => '+1 555 '.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'salary' => 3000 + ($i % 40) * 250,
                'avatar_url' => 'https://i.pravatar.cc/64?u='.$i,
                'joined_at' => now()->subDays($i * 3),
                'email_verified_at' => $i % 5 === 0 ? null : now()->subDays($i),
            ]);
        }

        // One soft-deleted row so the soft-delete example has something to show.
        User::where('id', 7)->delete();
    }

    private function seedProducts($categories)
    {
        $adjectives = ['Pro', 'Air', 'Ultra', 'Mini', 'Max', 'Studio', 'Lite'];
        $nouns = ['Book', 'Display', 'Board', 'Pod', 'Drive', 'Hub', 'Dock'];
        $statuses = ProductStatus::cases();

        for ($i = 1; $i <= 180; $i++) {
            Product::create([
                'category_id' => $categories[$i % $categories->count()]->id,
                'name' => $nouns[$i % count($nouns)].' '.$adjectives[$i % count($adjectives)].' '.(2020 + $i % 6),
                'sku' => 'SKU-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'status' => $statuses[$i % count($statuses)]->value,
                'price' => 49.99 + ($i % 30) * 37.5,
                'stock' => ($i * 7) % 250,
                'is_featured' => $i % 9 === 0,
                'image_url' => 'https://picsum.photos/seed/dt'.$i.'/64/64',
                'attributes' => ['colour' => ['black', 'silver', 'blue'][$i % 3], 'warranty' => ($i % 3) + 1 .' years'],
                'released_at' => now()->subDays($i * 5),
            ]);
        }

        Product::where('id', 3)->delete();

        return Product::all();
    }

    private function seedCustomers($companies)
    {
        for ($i = 1; $i <= 120; $i++) {
            $first = self::FIRST_NAMES[($i + 3) % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[($i + 5) % count(self::LAST_NAMES)];

            Customer::create([
                'company_id' => $companies[$i % $companies->count()]->id,
                'name' => "{$first} {$last}",
                'email' => strtolower("c{$i}.{$last}@example.org"),
                'phone' => '+44 20 '.str_pad((string) (2000 + $i), 4, '0', STR_PAD_LEFT),
                'country' => self::COUNTRIES[$i % count(self::COUNTRIES)],
                'is_active' => $i % 11 !== 0,
                'lifetime_value' => ($i % 50) * 320.75,
            ]);
        }

        return Customer::all();
    }

    private function seedOrders($customers, $products): void
    {
        $statuses = OrderStatus::cases();
        $staff = User::query()->limit(20)->pluck('id');

        for ($i = 1; $i <= 600; $i++) {
            $status = $statuses[$i % count($statuses)];
            $placed = now()->subDays($i % 400)->subHours($i % 24);

            $order = Order::create([
                'customer_id' => $customers[$i % $customers->count()]->id,
                'user_id' => $staff[$i % $staff->count()],
                'reference' => 'ORD-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'status' => $status->value,
                'placed_at' => $placed,
                'shipped_at' => in_array($status, [OrderStatus::Shipped, OrderStatus::Delivered], true)
                    ? $placed->copy()->addDays(2)
                    : null,
            ]);

            $total = 0;
            $count = ($i % 4) + 1;

            for ($line = 0; $line < $count; $line++) {
                $product = $products[($i + $line) % $products->count()];
                $quantity = ($line % 3) + 1;
                $total += $quantity * (float) $product->price;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ]);
            }

            $order->update(['total' => $total, 'items_count' => $count]);

            if ($status !== OrderStatus::Pending && $status !== OrderStatus::Cancelled) {
                Invoice::create([
                    'order_id' => $order->id,
                    'number' => 'INV-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                    'status' => $i % 3 === 0 ? 'unpaid' : 'paid',
                    'amount' => $total,
                    'due_on' => $placed->copy()->addDays(30)->toDateString(),
                    'paid_at' => $i % 3 === 0 ? null : $placed->copy()->addDays(4),
                ]);
            }
        }
    }
}
