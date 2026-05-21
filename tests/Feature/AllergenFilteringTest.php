<?php

namespace Tests\Feature;

use App\Enums\AllergenPresence;
use App\Enums\AllergenSource;
use App\Enums\FamilyRole;
use App\Models\Allergen;
use App\Models\Family;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AllergenFilteringTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private User $parent;

    private User $childWithProfile;

    private User $childWithoutProfile;

    private Allergen $peanuts;

    private Allergen $milk;

    private Recipe $peanutCookies;

    private Recipe $milkBread;

    private Recipe $plainSalad;

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

        $this->childWithProfile = User::create([
            'name' => 'Reviewed Child',
            'email' => 'reviewed@test.com',
            'password' => bcrypt('password'),
            'family_id' => $this->family->id,
            'family_role' => FamilyRole::Child,
            'allergen_profile_reviewed_at' => now(),
        ]);

        $this->childWithoutProfile = User::create([
            'name' => 'Unreviewed Child',
            'email' => 'unreviewed@test.com',
            'password' => bcrypt('password'),
            'family_id' => $this->family->id,
            'family_role' => FamilyRole::Child,
        ]);

        $this->peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        $this->milk = Allergen::where('slug', 'milk')->whereNull('family_id')->firstOrFail();

        $this->childWithProfile->allergens()->attach($this->peanuts->id);
        $this->childWithoutProfile->allergens()->attach($this->milk->id); // ignored: profile not reviewed

        $this->peanutCookies = $this->makeRecipeWithAllergen('Peanut Cookies', $this->peanuts, AllergenPresence::Contains);
        $this->milkBread = $this->makeRecipeWithAllergen('Milk Bread', $this->milk, AllergenPresence::Contains);
        $this->plainSalad = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'Plain Salad',
        ]);
    }

    // ── Filter param: safe_for_members[] ──

    public function test_safe_for_members_excludes_recipes_containing_their_allergens(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->getJson('/api/v1/recipes?safe_for_members[]='.$this->childWithProfile->id)
            ->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertNotContains('Peanut Cookies', $titles);
        $this->assertContains('Milk Bread', $titles);
        $this->assertContains('Plain Salad', $titles);
    }

    public function test_safe_for_members_treats_may_contain_as_unsafe(): void
    {
        $traceRecipe = $this->makeRecipeWithAllergen('Trace Peanut Granola', $this->peanuts, AllergenPresence::MayContain);

        Sanctum::actingAs($this->parent);

        $titles = collect(
            $this->getJson('/api/v1/recipes?safe_for_members[]='.$this->childWithProfile->id)
                ->assertOk()
                ->json('data')
        )->pluck('title')->all();

        $this->assertNotContains('Trace Peanut Granola', $titles);
    }

    public function test_safe_for_all_uses_only_reviewed_member_profiles(): void
    {
        // Unreviewed child has milk on their profile, but it must be ignored —
        // so Milk Bread should NOT be filtered out by safe_for=all.
        Sanctum::actingAs($this->parent);

        $titles = collect(
            $this->getJson('/api/v1/recipes?safe_for=all')
                ->assertOk()
                ->json('data')
        )->pluck('title')->all();

        $this->assertNotContains('Peanut Cookies', $titles);
        $this->assertContains('Milk Bread', $titles); // unreviewed profile is silently ignored
        $this->assertContains('Plain Salad', $titles);
    }

    public function test_safe_for_member_with_no_allergens_returns_everything(): void
    {
        $cleanMember = User::create([
            'name' => 'Clean',
            'email' => 'clean@test.com',
            'password' => bcrypt('password'),
            'family_id' => $this->family->id,
            'family_role' => FamilyRole::Child,
            'allergen_profile_reviewed_at' => now(),
        ]);

        Sanctum::actingAs($this->parent);

        $titles = collect(
            $this->getJson('/api/v1/recipes?safe_for_members[]='.$cleanMember->id)
                ->assertOk()
                ->json('data')
        )->pluck('title')->all();

        $this->assertContains('Peanut Cookies', $titles);
        $this->assertContains('Milk Bread', $titles);
        $this->assertContains('Plain Salad', $titles);
    }

    public function test_unreviewed_member_id_in_safe_for_members_is_skipped(): void
    {
        // Pass the unreviewed child's ID; the filter should silently ignore it.
        Sanctum::actingAs($this->parent);

        $titles = collect(
            $this->getJson('/api/v1/recipes?safe_for_members[]='.$this->childWithoutProfile->id)
                ->assertOk()
                ->json('data')
        )->pluck('title')->all();

        // No filter applied → everything returns
        $this->assertContains('Peanut Cookies', $titles);
        $this->assertContains('Milk Bread', $titles);
    }

    // ── Meal planner guard ──

    public function test_planning_recipe_with_allergen_for_reviewed_member_requires_acknowledgement(): void
    {
        $plan = MealPlan::create([
            'family_id' => $this->family->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'created_by' => $this->parent->id,
        ]);

        Sanctum::actingAs($this->parent);

        $response = $this->postJson("/api/v1/meal-plans/{$plan->id}/entries", [
            'date' => now()->toDateString(),
            'meal_slot' => 'dinner',
            'recipe_id' => $this->peanutCookies->id,
        ])->assertStatus(409);

        $response->assertJsonPath('requires_acknowledgement', true);
        $hits = $response->json('hits');
        $this->assertCount(1, $hits);
        $this->assertEquals($this->childWithProfile->id, $hits[0]['member_id']);
        $this->assertEquals('peanuts', Str::slug($hits[0]['allergen_name']));
    }

    public function test_planning_with_acknowledge_flag_succeeds_despite_allergen(): void
    {
        $plan = MealPlan::create([
            'family_id' => $this->family->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'created_by' => $this->parent->id,
        ]);

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/meal-plans/{$plan->id}/entries", [
            'date' => now()->toDateString(),
            'meal_slot' => 'dinner',
            'recipe_id' => $this->peanutCookies->id,
            'acknowledge_allergens' => true,
        ])->assertCreated();
    }

    public function test_planning_recipe_with_no_matching_allergen_proceeds_without_ack(): void
    {
        $plan = MealPlan::create([
            'family_id' => $this->family->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'created_by' => $this->parent->id,
        ]);

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/meal-plans/{$plan->id}/entries", [
            'date' => now()->toDateString(),
            'meal_slot' => 'dinner',
            'recipe_id' => $this->plainSalad->id,
        ])->assertCreated();
    }

    public function test_planning_does_not_block_for_unreviewed_member_profile(): void
    {
        // Milk Bread has milk; unreviewed child has milk on profile. Since the
        // profile is unreviewed, planner should NOT require acknowledgement.
        $plan = MealPlan::create([
            'family_id' => $this->family->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'created_by' => $this->parent->id,
        ]);

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/meal-plans/{$plan->id}/entries", [
            'date' => now()->toDateString(),
            'meal_slot' => 'dinner',
            'recipe_id' => $this->milkBread->id,
        ])->assertCreated();
    }

    private function makeRecipeWithAllergen(string $title, Allergen $allergen, AllergenPresence $presence): Recipe
    {
        $recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => $title,
        ]);
        $recipe->allergens()->attach($allergen->id, [
            'id' => (string) Str::uuid(),
            'presence' => $presence->value,
            'source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $this->parent->id,
            'confirmed_at' => now(),
        ]);

        return $recipe;
    }
}
