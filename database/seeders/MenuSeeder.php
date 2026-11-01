<?php

namespace Database\Seeders;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('seeders/menu_data.json')), true);

        foreach ($data as $categoryData) {
            $category = MenuCategory::firstOrCreate(
                ['name' => $categoryData['name']],
                [
                    'slug' => Str::slug($categoryData['name']),
                    'icon' => $categoryData['icon'] ?? 'utensils',
                    'sort_order' => 0,
                    'is_active' => true,
                ]
            );

            foreach ($categoryData['items'] as $itemData) {
                MenuItem::firstOrCreate(
                    ['name' => $itemData['name'], 'menu_category_id' => $category->id],
                    [
                        'description' => $itemData['description'] ?? '',
                        'price' => $itemData['price'] ?? 0,
                        'is_available' => true,
                        'sort_order' => 0,
                    ]
                );
            }

            $this->command->info("  ✓ {$category->name} (".count($categoryData['items']).' articles)');
        }

        $this->command->info('Menu importé : '.MenuItem::count().' articles dans '.MenuCategory::count().' catégories.');
    }
}
