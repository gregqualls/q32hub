<?php

namespace Tests\Feature;

use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\Recipe;
use App\Models\RecipeImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeImageGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->family = Family::create([
            'name' => 'Test Family',
            'slug' => 'test-family',
            'invite_code' => 'TEST01',
            'settings' => ['modules' => ['food' => true]],
        ]);

        $this->parent = User::create([
            'name' => 'Parent',
            'email' => 'parent@test.com',
            'password' => bcrypt('password'),
            'family_id' => $this->family->id,
            'family_role' => FamilyRole::Parent,
        ]);
    }

    public function test_recipe_can_be_created_with_multiple_images(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes', [
            'title' => 'Banana Bread',
            'images' => [
                ['path' => 'recipes/hero.jpg', 'sort_order' => 0, 'is_primary' => true],
                ['path' => 'recipes/process.jpg', 'sort_order' => 1, 'is_primary' => false],
                ['path' => 'recipes/sliced.jpg', 'sort_order' => 2, 'is_primary' => false],
            ],
        ])->assertCreated();

        $images = $response->json('recipe.images');
        $this->assertCount(3, $images);
        $this->assertEquals('recipes/hero.jpg', $images[0]['path']);
        $this->assertTrue($images[0]['is_primary']);
        $this->assertFalse($images[1]['is_primary']);
        $this->assertFalse($images[2]['is_primary']);
    }

    public function test_primary_image_path_syncs_recipes_image_path_column(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'Banana Bread',
            'images' => [
                ['path' => 'recipes/hero.jpg', 'sort_order' => 0, 'is_primary' => true],
            ],
        ])->json('recipe.id');

        $recipe = Recipe::findOrFail($recipeId);
        $this->assertEquals('recipes/hero.jpg', $recipe->image_path);
    }

    public function test_first_image_becomes_primary_when_no_flag_set(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes', [
            'title' => 'Salad',
            'images' => [
                ['path' => 'recipes/a.jpg'],
                ['path' => 'recipes/b.jpg'],
            ],
        ])->assertCreated();

        $images = $response->json('recipe.images');
        $this->assertTrue($images[0]['is_primary']);
        $this->assertFalse($images[1]['is_primary']);
    }

    public function test_update_replaces_images_and_swaps_primary(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'Banana Bread',
            'images' => [
                ['path' => 'recipes/old-hero.jpg', 'sort_order' => 0, 'is_primary' => true],
                ['path' => 'recipes/old-extra.jpg', 'sort_order' => 1, 'is_primary' => false],
            ],
        ])->json('recipe.id');

        $oldImages = Recipe::with('images')->findOrFail($recipeId)->images->keyBy('path');

        $response = $this->putJson("/api/v1/recipes/{$recipeId}", [
            'title' => 'Banana Bread',
            'images' => [
                // Swap primary: extra becomes primary, hero becomes secondary
                ['id' => $oldImages['recipes/old-extra.jpg']->id, 'path' => 'recipes/old-extra.jpg', 'sort_order' => 0, 'is_primary' => true],
                ['id' => $oldImages['recipes/old-hero.jpg']->id, 'path' => 'recipes/old-hero.jpg', 'sort_order' => 1, 'is_primary' => false],
                // New image appended
                ['path' => 'recipes/new.jpg', 'sort_order' => 2, 'is_primary' => false],
            ],
        ])->assertOk();

        $images = $response->json('recipe.images');
        $this->assertCount(3, $images);
        $this->assertEquals('recipes/old-extra.jpg', $images[0]['path']);
        $this->assertTrue($images[0]['is_primary']);
        $this->assertEquals('recipes/new.jpg', $images[2]['path']);

        // image_path cache updated
        $this->assertEquals('recipes/old-extra.jpg', Recipe::findOrFail($recipeId)->image_path);
    }

    public function test_update_with_subset_removes_dropped_images(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'Banana Bread',
            'images' => [
                ['path' => 'a.jpg'],
                ['path' => 'b.jpg'],
                ['path' => 'c.jpg'],
            ],
        ])->json('recipe.id');

        $existing = Recipe::with('images')->findOrFail($recipeId)->images->keyBy('path');

        $this->putJson("/api/v1/recipes/{$recipeId}", [
            'title' => 'Banana Bread',
            'images' => [
                ['id' => $existing['a.jpg']->id, 'path' => 'a.jpg', 'sort_order' => 0, 'is_primary' => true],
            ],
        ])->assertOk();

        $this->assertEquals(1, RecipeImage::where('recipe_id', $recipeId)->count());
    }

    public function test_update_with_empty_images_clears_all(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'Banana Bread',
            'images' => [['path' => 'a.jpg']],
        ])->json('recipe.id');

        $this->putJson("/api/v1/recipes/{$recipeId}", [
            'title' => 'Banana Bread',
            'images' => [],
        ])->assertOk();

        $this->assertEquals(0, RecipeImage::where('recipe_id', $recipeId)->count());
        $this->assertNull(Recipe::findOrFail($recipeId)->image_path);
    }

    public function test_recipe_resource_exposes_images_in_order(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'Banana Bread',
            'images' => [
                ['path' => 'first.jpg', 'sort_order' => 0, 'is_primary' => true],
                ['path' => 'second.jpg', 'sort_order' => 1],
                ['path' => 'third.jpg', 'sort_order' => 2],
            ],
        ])->json('recipe.id');

        $response = $this->getJson("/api/v1/recipes/{$recipeId}")->assertOk();
        $paths = collect($response->json('recipe.images'))->pluck('path')->all();
        $this->assertEquals(['first.jpg', 'second.jpg', 'third.jpg'], $paths);
    }

    public function test_legacy_image_path_only_save_still_works(): void
    {
        Sanctum::actingAs($this->parent);

        // Caller didn't send `images` but did send the legacy `image_path` field.
        $response = $this->postJson('/api/v1/recipes', [
            'title' => 'Legacy',
            'image_path' => 'recipes/legacy.jpg',
        ])->assertCreated();

        $images = $response->json('recipe.images');
        $this->assertCount(1, $images);
        $this->assertEquals('recipes/legacy.jpg', $images[0]['path']);
        $this->assertTrue($images[0]['is_primary']);
    }
}
