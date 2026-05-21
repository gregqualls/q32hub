<?php

namespace Tests\Feature;

use App\Enums\AllergenPresence;
use App\Enums\AllergenSource;
use App\Enums\FamilyRole;
use App\Jobs\BackfillRecipeAllergens as BackfillJob;
use App\Models\Allergen;
use App\Models\Family;
use App\Models\Recipe;
use App\Models\RecipeAllergen;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Services\RecipeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AllergenAiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private User $parent;

    private User $child;

    private Allergen $peanuts;

    private Allergen $milk;

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

        $this->child = User::create([
            'name' => 'Child',
            'email' => 'child@test.com',
            'password' => bcrypt('password'),
            'family_id' => $this->family->id,
            'family_role' => FamilyRole::Child,
        ]);

        $this->peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        $this->milk = Allergen::where('slug', 'milk')->whereNull('family_id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── persistAiAllergens (confidence routing + idempotency) ──

    public function test_high_confidence_writes_ai_auto_low_writes_ai_suggested(): void
    {
        $recipe = $this->makeRecipe('PB&J');
        $service = app(RecipeImportService::class);

        $service->persistAiAllergens($recipe, [
            ['slug' => 'peanuts', 'presence' => 'contains', 'confidence' => 0.99],
            ['slug' => 'milk', 'presence' => 'may_contain', 'confidence' => 0.6],
        ]);

        $peanutRow = RecipeAllergen::where('recipe_id', $recipe->id)->where('allergen_id', $this->peanuts->id)->firstOrFail();
        $milkRow = RecipeAllergen::where('recipe_id', $recipe->id)->where('allergen_id', $this->milk->id)->firstOrFail();

        $this->assertEquals(AllergenSource::AiAuto, $peanutRow->source);
        $this->assertEquals(0.99, (float) $peanutRow->confidence);
        $this->assertNull($peanutRow->confirmed_by);

        $this->assertEquals(AllergenSource::AiSuggested, $milkRow->source);
        $this->assertEquals(AllergenPresence::MayContain, $milkRow->presence);
    }

    public function test_persist_is_idempotent_and_preserves_existing_human_rows(): void
    {
        $recipe = $this->makeRecipe('PB&J');
        // Existing human-confirmed peanut tag
        $existing = RecipeAllergen::create([
            'id' => (string) Str::uuid(),
            'recipe_id' => $recipe->id,
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
            'source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $this->parent->id,
            'confirmed_at' => now(),
        ]);

        $service = app(RecipeImportService::class);
        $service->persistAiAllergens($recipe, [
            ['slug' => 'peanuts', 'presence' => 'contains', 'confidence' => 0.99],
            ['slug' => 'milk', 'presence' => 'contains', 'confidence' => 0.85],
        ]);

        // Existing row untouched
        $existing->refresh();
        $this->assertEquals(AllergenSource::HumanConfirmed, $existing->source);

        // Milk added as new
        $this->assertEquals(2, RecipeAllergen::where('recipe_id', $recipe->id)->count());
    }

    public function test_persist_drops_unknown_slugs_and_bad_confidence(): void
    {
        $recipe = $this->makeRecipe('PB&J');
        $service = app(RecipeImportService::class);

        // Bypassing normalize means we feed garbage directly; the slug→id map
        // ignores anything not in the family's allergen list.
        $service->persistAiAllergens($recipe, [
            ['slug' => 'unobtainium', 'presence' => 'contains', 'confidence' => 0.99],
            ['slug' => 'peanuts', 'presence' => 'contains', 'confidence' => 0.99],
        ]);

        $this->assertEquals(1, RecipeAllergen::where('recipe_id', $recipe->id)->count());
    }

    // ── One-click confirm ──

    public function test_patch_confirms_ai_tag_to_human_confirmed(): void
    {
        $recipe = $this->makeRecipe('PB&J');
        $row = RecipeAllergen::create([
            'id' => (string) Str::uuid(),
            'recipe_id' => $recipe->id,
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
            'source' => AllergenSource::AiAuto->value,
            'confidence' => 0.97,
        ]);

        Sanctum::actingAs($this->parent);

        $this->patchJson("/api/v1/recipes/{$recipe->id}/allergens/{$row->id}", [])
            ->assertOk();

        $row->refresh();
        $this->assertEquals(AllergenSource::HumanConfirmed, $row->source);
        $this->assertEquals($this->parent->id, $row->confirmed_by);
        $this->assertNotNull($row->confirmed_at);
    }

    public function test_recipe_resource_exposes_pivot_id_for_confirm_action(): void
    {
        $recipe = $this->makeRecipe('PB&J');
        $row = RecipeAllergen::create([
            'id' => (string) Str::uuid(),
            'recipe_id' => $recipe->id,
            'allergen_id' => $this->peanuts->id,
            'presence' => 'contains',
            'source' => AllergenSource::AiSuggested->value,
            'confidence' => 0.6,
        ]);

        Sanctum::actingAs($this->parent);

        $payload = $this->getJson("/api/v1/recipes/{$recipe->id}")
            ->assertOk()
            ->json('recipe.allergens');

        $this->assertCount(1, $payload);
        $this->assertEquals($row->id, $payload[0]['pivot_id']);
        $this->assertEquals('ai_suggested', $payload[0]['source']);
    }

    // ── Backfill ──

    public function test_backfill_endpoint_is_parent_only(): void
    {
        Sanctum::actingAs($this->child);

        $this->postJson('/api/v1/recipes/allergens/backfill')->assertForbidden();
    }

    public function test_backfill_endpoint_queues_jobs_only_for_untagged_recipes(): void
    {
        Queue::fake();

        $untagged1 = $this->makeRecipe('Untagged 1');
        $untagged2 = $this->makeRecipe('Untagged 2');
        $tagged = $this->makeRecipe('Tagged');
        $tagged->allergens()->attach($this->peanuts->id, [
            'id' => (string) Str::uuid(),
            'presence' => 'contains',
            'source' => AllergenSource::HumanConfirmed->value,
        ]);

        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes/allergens/backfill')->assertOk();
        $this->assertEquals(2, $response->json('queued'));

        Queue::assertPushed(BackfillJob::class, 2);
    }

    public function test_backfill_endpoint_with_force_re_tags_everything(): void
    {
        Queue::fake();

        $tagged = $this->makeRecipe('Tagged');
        $tagged->allergens()->attach($this->peanuts->id, [
            'id' => (string) Str::uuid(),
            'presence' => 'contains',
            'source' => AllergenSource::HumanConfirmed->value,
        ]);

        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes/allergens/backfill', ['force' => true])->assertOk();
        $this->assertEquals(1, $response->json('queued'));
    }

    public function test_backfill_job_skips_already_tagged_recipe_without_force(): void
    {
        $recipe = $this->makeRecipe('PB&J', withIngredients: true);
        $recipe->allergens()->attach($this->milk->id, [
            'id' => (string) Str::uuid(),
            'presence' => 'contains',
            'source' => AllergenSource::HumanConfirmed->value,
        ]);

        $service = Mockery::mock(RecipeImportService::class);
        $service->shouldNotReceive('extractAllergensFromIngredients');
        $service->shouldNotReceive('persistAiAllergens');

        (new BackfillJob($recipe->id))->handle($service);

        $this->assertEquals(1, RecipeAllergen::where('recipe_id', $recipe->id)->count());
    }

    public function test_backfill_job_skips_recipes_with_no_ingredients(): void
    {
        $recipe = $this->makeRecipe('Empty');

        $service = Mockery::mock(RecipeImportService::class);
        $service->shouldNotReceive('extractAllergensFromIngredients');

        (new BackfillJob($recipe->id))->handle($service);

        $this->assertEquals(0, RecipeAllergen::where('recipe_id', $recipe->id)->count());
    }

    public function test_backfill_job_writes_ai_allergens_when_extraction_returns_data(): void
    {
        $recipe = $this->makeRecipe('PB&J', withIngredients: true);

        $service = Mockery::mock(RecipeImportService::class)->makePartial();
        $service->shouldReceive('extractAllergensFromIngredients')
            ->once()
            ->andReturn([
                ['slug' => 'peanuts', 'presence' => 'contains', 'confidence' => 0.97],
            ]);
        $service->shouldReceive('persistAiAllergens')
            ->once()
            ->andReturnUsing(function ($r, $allergens) {
                $this->assertEquals('peanuts', $allergens[0]['slug']);

                return 1;
            });

        (new BackfillJob($recipe->id))->handle($service);
    }

    private function makeRecipe(string $title, bool $withIngredients = false): Recipe
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => $title,
        ]);

        if ($withIngredients) {
            RecipeIngredient::create([
                'id' => (string) Str::uuid(),
                'recipe_id' => $recipe->id,
                'name' => 'Peanut butter',
                'quantity' => '2',
                'unit' => 'tbsp',
                'sort_order' => 0,
            ]);
        }

        return $recipe;
    }
}
