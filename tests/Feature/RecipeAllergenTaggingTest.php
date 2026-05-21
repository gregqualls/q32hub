<?php

namespace Tests\Feature;

use App\Enums\AllergenPresence;
use App\Enums\AllergenSource;
use App\Enums\FamilyRole;
use App\Models\Allergen;
use App\Models\Family;
use App\Models\Recipe;
use App\Models\RecipeAllergen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeAllergenTaggingTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private Family $otherFamily;

    private User $parent;

    private User $otherParent;

    private Allergen $peanuts;

    private Allergen $milk;

    private Allergen $familyCustom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->family = Family::create([
            'name' => 'Test Family',
            'slug' => 'test-family',
            'invite_code' => 'TEST01',
            'settings' => ['modules' => ['food' => true]],
        ]);

        $this->otherFamily = Family::create([
            'name' => 'Other Family',
            'slug' => 'other-family',
            'invite_code' => 'OTHER1',
            'settings' => ['modules' => ['food' => true]],
        ]);

        $this->parent = $this->makeUser('Parent', 'parent@test.com', $this->family, FamilyRole::Parent);
        $this->otherParent = $this->makeUser('Other Parent', 'other@test.com', $this->otherFamily, FamilyRole::Parent);

        $this->peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        $this->milk = Allergen::where('slug', 'milk')->whereNull('family_id')->firstOrFail();
        $this->familyCustom = Allergen::create([
            'family_id' => $this->family->id,
            'name' => 'Cinnamon',
            'slug' => 'cinnamon',
        ]);
    }

    public function test_recipe_can_be_created_with_allergens(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes', [
            'title' => 'Peanut Cookies',
            'servings' => 12,
            'allergens' => [
                ['allergen_id' => $this->peanuts->id, 'presence' => 'contains'],
                ['allergen_id' => $this->milk->id, 'presence' => 'may_contain'],
            ],
        ])->assertCreated();

        $allergens = collect($response->json('recipe.allergens'));
        $this->assertCount(2, $allergens);
        $this->assertTrue($allergens->contains(fn ($a) => $a['slug'] === 'peanuts' && $a['presence'] === 'contains' && $a['source'] === AllergenSource::HumanConfirmed->value));
        $this->assertTrue($allergens->contains(fn ($a) => $a['slug'] === 'milk' && $a['presence'] === 'may_contain'));
    }

    public function test_recipe_can_carry_same_allergen_at_both_presence_levels(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes', [
            'title' => 'Peanut Sauce (with cross-contamination warning)',
            'allergens' => [
                ['allergen_id' => $this->peanuts->id, 'presence' => 'contains'],
                ['allergen_id' => $this->peanuts->id, 'presence' => 'may_contain'],
            ],
        ])->assertCreated();

        $this->assertCount(2, $response->json('recipe.allergens'));
        $presences = collect($response->json('recipe.allergens'))->pluck('presence')->all();
        $this->assertEqualsCanonicalizing(['contains', 'may_contain'], $presences);
    }

    public function test_human_confirmed_provenance_is_stamped_on_form_save(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes', [
            'title' => 'PB&J',
            'allergens' => [['allergen_id' => $this->peanuts->id, 'presence' => 'contains']],
        ])->assertCreated();

        $recipeId = $response->json('recipe.id');
        $row = RecipeAllergen::where('recipe_id', $recipeId)->firstOrFail();

        $this->assertEquals(AllergenSource::HumanConfirmed, $row->source);
        $this->assertEquals($this->parent->id, $row->confirmed_by);
        $this->assertNotNull($row->confirmed_at);
    }

    public function test_family_custom_allergen_can_be_used(): void
    {
        Sanctum::actingAs($this->parent);

        $this->postJson('/api/v1/recipes', [
            'title' => 'Cinnamon Roll',
            'allergens' => [['allergen_id' => $this->familyCustom->id, 'presence' => 'contains']],
        ])->assertCreated();

        $this->assertDatabaseHas('recipe_allergens', [
            'allergen_id' => $this->familyCustom->id,
            'presence' => AllergenPresence::Contains->value,
        ]);
    }

    public function test_cannot_use_allergen_from_another_family(): void
    {
        $otherCustom = Allergen::create(['family_id' => $this->otherFamily->id, 'name' => 'Kiwi', 'slug' => 'kiwi']);
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes', [
            'title' => 'Mystery Bowl',
            'allergens' => [['allergen_id' => $otherCustom->id, 'presence' => 'contains']],
        ])->assertCreated();

        // Allergens silently filtered out by the service's availableToFamily guard.
        $this->assertCount(0, $response->json('recipe.allergens'));
    }

    public function test_update_replaces_allergen_list(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'PB&J',
            'allergens' => [
                ['allergen_id' => $this->peanuts->id, 'presence' => 'contains'],
                ['allergen_id' => $this->milk->id, 'presence' => 'contains'],
            ],
        ])->assertCreated()->json('recipe.id');

        $this->putJson("/api/v1/recipes/{$recipeId}", [
            'title' => 'PB&J (updated)',
            'allergens' => [
                ['allergen_id' => $this->peanuts->id, 'presence' => 'contains'],
            ],
        ])->assertOk();

        $this->assertEquals(1, RecipeAllergen::where('recipe_id', $recipeId)->count());
        $this->assertDatabaseHas('recipe_allergens', [
            'recipe_id' => $recipeId,
            'allergen_id' => $this->peanuts->id,
        ]);
    }

    public function test_update_without_allergens_field_does_not_change_existing_tags(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'PB&J',
            'allergens' => [['allergen_id' => $this->peanuts->id, 'presence' => 'contains']],
        ])->assertCreated()->json('recipe.id');

        // Update title only; omit allergens key entirely.
        $this->putJson("/api/v1/recipes/{$recipeId}", ['title' => 'PB&J Deluxe'])->assertOk();

        $this->assertEquals(1, RecipeAllergen::where('recipe_id', $recipeId)->count());
    }

    public function test_empty_allergens_array_clears_existing_tags(): void
    {
        Sanctum::actingAs($this->parent);

        $recipeId = $this->postJson('/api/v1/recipes', [
            'title' => 'PB&J',
            'allergens' => [['allergen_id' => $this->peanuts->id, 'presence' => 'contains']],
        ])->assertCreated()->json('recipe.id');

        $this->putJson("/api/v1/recipes/{$recipeId}", [
            'title' => 'PB&J',
            'allergens' => [],
        ])->assertOk();

        $this->assertEquals(0, RecipeAllergen::where('recipe_id', $recipeId)->count());
    }

    public function test_show_endpoint_returns_allergens(): void
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'PB&J',
            'servings' => 1,
        ]);
        $recipe->allergens()->attach($this->peanuts->id, [
            'id' => (string) Str::uuid(),
            'presence' => AllergenPresence::Contains->value,
            'source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $this->parent->id,
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($this->parent);

        $response = $this->getJson("/api/v1/recipes/{$recipe->id}")->assertOk();
        $allergens = $response->json('recipe.allergens');

        $this->assertCount(1, $allergens);
        $this->assertEquals('peanuts', $allergens[0]['slug']);
        $this->assertEquals('contains', $allergens[0]['presence']);
    }

    // ---------- Fine-grained edit endpoints ----------

    public function test_post_single_allergen_to_recipe(): void
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'PB&J',
        ]);

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/recipes/{$recipe->id}/allergens", [
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
        ])->assertCreated();

        $this->assertDatabaseHas('recipe_allergens', [
            'recipe_id' => $recipe->id,
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
        ]);
    }

    public function test_post_duplicate_single_allergen_is_idempotent(): void
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'PB&J',
        ]);

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/recipes/{$recipe->id}/allergens", [
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
        ])->assertCreated();

        $this->postJson("/api/v1/recipes/{$recipe->id}/allergens", [
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
        ])->assertCreated();

        $this->assertEquals(1, RecipeAllergen::where('recipe_id', $recipe->id)->count());
    }

    public function test_patch_can_change_presence_and_confirm(): void
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'PB&J',
        ]);
        $row = RecipeAllergen::create([
            'id' => (string) Str::uuid(),
            'recipe_id' => $recipe->id,
            'allergen_id' => $this->peanuts->id,
            'presence' => AllergenPresence::MayContain->value,
            'source' => AllergenSource::AiSuggested->value,
        ]);

        Sanctum::actingAs($this->parent);

        $this->patchJson("/api/v1/recipes/{$recipe->id}/allergens/{$row->id}", [
            'presence' => 'contains',
        ])->assertOk();

        $row->refresh();
        $this->assertEquals(AllergenPresence::Contains, $row->presence);
        $this->assertEquals(AllergenSource::HumanConfirmed, $row->source);
        $this->assertEquals($this->parent->id, $row->confirmed_by);
        $this->assertNotNull($row->confirmed_at);
    }

    public function test_patch_remove_action_deletes_row(): void
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'PB&J',
        ]);
        $row = RecipeAllergen::create([
            'id' => (string) Str::uuid(),
            'recipe_id' => $recipe->id,
            'allergen_id' => $this->peanuts->id,
            'presence' => AllergenPresence::Contains->value,
            'source' => AllergenSource::HumanConfirmed->value,
        ]);

        Sanctum::actingAs($this->parent);

        $this->patchJson("/api/v1/recipes/{$recipe->id}/allergens/{$row->id}", [
            'action' => 'remove',
        ])->assertOk();

        $this->assertDatabaseMissing('recipe_allergens', ['id' => $row->id]);
    }

    public function test_cannot_edit_allergens_on_another_familys_recipe(): void
    {
        $foreignRecipe = Recipe::create([
            'family_id' => $this->otherFamily->id,
            'created_by' => $this->otherParent->id,
            'title' => 'Stranger Stew',
        ]);

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/recipes/{$foreignRecipe->id}/allergens", [
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
        ])->assertForbidden();
    }

    // ---------- Validation + module gating ----------

    public function test_invalid_presence_is_rejected(): void
    {
        Sanctum::actingAs($this->parent);

        $this->postJson('/api/v1/recipes', [
            'title' => 'Bad Recipe',
            'allergens' => [['allergen_id' => $this->peanuts->id, 'presence' => 'definitely']],
        ])->assertStatus(422);
    }

    public function test_module_gating_blocks_recipe_allergen_routes_when_food_off(): void
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'PB&J',
        ]);

        $this->family->settings = ['modules' => ['food' => false]];
        $this->family->save();

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/recipes/{$recipe->id}/allergens", [
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
        ])->assertForbidden();
    }

    private function makeUser(string $name, string $email, Family $family, FamilyRole $role): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'family_id' => $family->id,
            'family_role' => $role,
        ]);
    }
}
