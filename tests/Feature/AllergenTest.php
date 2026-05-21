<?php

namespace Tests\Feature;

use App\Enums\FamilyRole;
use App\Models\Allergen;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AllergenTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private Family $otherFamily;

    private User $parent;

    private User $child;

    private User $otherParent;

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
        $this->child = $this->makeUser('Child', 'child@test.com', $this->family, FamilyRole::Child);
        $this->otherParent = $this->makeUser('Other Parent', 'other@test.com', $this->otherFamily, FamilyRole::Parent);
    }

    // ---------- Allergen list / CRUD ----------

    public function test_big_nine_seeded_globally(): void
    {
        $bigNine = Allergen::whereNull('family_id')->where('is_big_nine', true)->get();

        $this->assertCount(9, $bigNine);
        $this->assertEqualsCanonicalizing(
            ['milk', 'eggs', 'fish', 'shellfish', 'tree-nuts', 'peanuts', 'wheat', 'soy', 'sesame'],
            $bigNine->pluck('slug')->all()
        );
    }

    public function test_index_returns_big_nine_plus_family_customs(): void
    {
        Allergen::create(['family_id' => $this->family->id, 'name' => 'Corn', 'slug' => 'corn']);
        Allergen::create(['family_id' => $this->otherFamily->id, 'name' => 'Kiwi', 'slug' => 'kiwi']);

        Sanctum::actingAs($this->parent);
        $response = $this->getJson('/api/v1/allergens')->assertOk();

        $slugs = collect($response->json('allergens'))->pluck('slug')->all();
        $this->assertContains('milk', $slugs);
        $this->assertContains('corn', $slugs);
        $this->assertNotContains('kiwi', $slugs);
    }

    public function test_parent_can_create_custom_allergen(): void
    {
        Sanctum::actingAs($this->parent);

        $this->postJson('/api/v1/allergens', ['name' => 'Corn'])
            ->assertCreated()
            ->assertJsonPath('allergen.slug', 'corn')
            ->assertJsonPath('allergen.family_id', $this->family->id)
            ->assertJsonPath('allergen.is_big_nine', false);
    }

    public function test_child_cannot_create_custom_allergen(): void
    {
        Sanctum::actingAs($this->child);

        $this->postJson('/api/v1/allergens', ['name' => 'Corn'])->assertForbidden();
    }

    public function test_duplicate_custom_name_rejected(): void
    {
        Allergen::create(['family_id' => $this->family->id, 'name' => 'Corn', 'slug' => 'corn']);
        Sanctum::actingAs($this->parent);

        $this->postJson('/api/v1/allergens', ['name' => 'Corn'])
            ->assertStatus(422)
            ->assertJson(['message' => 'An allergen with that name already exists.']);
    }

    public function test_custom_cannot_collide_with_big_nine_slug(): void
    {
        Sanctum::actingAs($this->parent);

        $this->postJson('/api/v1/allergens', ['name' => 'Milk'])
            ->assertStatus(422)
            ->assertJson(['message' => 'An allergen with that name already exists.']);
    }

    public function test_parent_can_rename_their_family_custom(): void
    {
        $allergen = Allergen::create(['family_id' => $this->family->id, 'name' => 'Corn', 'slug' => 'corn']);
        Sanctum::actingAs($this->parent);

        $this->patchJson("/api/v1/allergens/{$allergen->id}", ['name' => 'Corn (sweet)'])
            ->assertOk()
            ->assertJsonPath('allergen.slug', 'corn-sweet');
    }

    public function test_parent_cannot_rename_big_nine(): void
    {
        $milk = Allergen::where('slug', 'milk')->whereNull('family_id')->firstOrFail();
        Sanctum::actingAs($this->parent);

        $this->patchJson("/api/v1/allergens/{$milk->id}", ['name' => 'Dairy'])->assertForbidden();
    }

    public function test_parent_cannot_modify_another_familys_custom(): void
    {
        $allergen = Allergen::create(['family_id' => $this->otherFamily->id, 'name' => 'Kiwi', 'slug' => 'kiwi']);
        Sanctum::actingAs($this->parent);

        $this->patchJson("/api/v1/allergens/{$allergen->id}", ['name' => 'Kiwi fruit'])->assertForbidden();
        $this->deleteJson("/api/v1/allergens/{$allergen->id}")->assertForbidden();
    }

    public function test_parent_can_delete_their_family_custom(): void
    {
        $allergen = Allergen::create(['family_id' => $this->family->id, 'name' => 'Corn', 'slug' => 'corn']);
        Sanctum::actingAs($this->parent);

        $this->deleteJson("/api/v1/allergens/{$allergen->id}")->assertOk();
        $this->assertDatabaseMissing('allergens', ['id' => $allergen->id]);
    }

    // ---------- Member allergy profile ----------

    public function test_member_profile_starts_empty_and_unreviewed(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->getJson("/api/v1/users/{$this->child->id}/allergens")->assertOk();
        $this->assertEquals([], $response->json('allergen_ids'));
        $this->assertNull($response->json('reviewed_at'));
    }

    public function test_parent_can_set_any_members_profile_and_it_marks_reviewed(): void
    {
        $peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        Sanctum::actingAs($this->parent);

        $response = $this->putJson("/api/v1/users/{$this->child->id}/allergens", [
            'allergen_ids' => [$peanuts->id],
        ])->assertOk();

        $this->assertEquals([$peanuts->id], $response->json('allergen_ids'));
        $this->assertNotNull($response->json('reviewed_at'));
        $this->assertDatabaseHas('member_allergens', [
            'user_id' => $this->child->id,
            'allergen_id' => $peanuts->id,
        ]);
    }

    public function test_member_can_edit_their_own_profile(): void
    {
        $peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        Sanctum::actingAs($this->child);

        $this->putJson("/api/v1/users/{$this->child->id}/allergens", [
            'allergen_ids' => [$peanuts->id],
        ])->assertOk();
    }

    public function test_member_cannot_edit_anothers_profile(): void
    {
        $peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        Sanctum::actingAs($this->child);

        $this->putJson("/api/v1/users/{$this->parent->id}/allergens", [
            'allergen_ids' => [$peanuts->id],
        ])->assertForbidden();
    }

    public function test_cannot_view_or_edit_member_in_other_family(): void
    {
        Sanctum::actingAs($this->parent);

        $this->getJson("/api/v1/users/{$this->otherParent->id}/allergens")->assertNotFound();
        $this->putJson("/api/v1/users/{$this->otherParent->id}/allergens", ['allergen_ids' => []])->assertNotFound();
    }

    public function test_cannot_set_allergen_not_available_to_family(): void
    {
        $otherFamilyAllergen = Allergen::create([
            'family_id' => $this->otherFamily->id,
            'name' => 'Kiwi',
            'slug' => 'kiwi',
        ]);

        Sanctum::actingAs($this->parent);

        $this->putJson("/api/v1/users/{$this->child->id}/allergens", [
            'allergen_ids' => [$otherFamilyAllergen->id],
        ])->assertStatus(422);
    }

    public function test_mark_reviewed_clears_prompt_without_changing_profile(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson("/api/v1/users/{$this->child->id}/allergens/mark-reviewed")->assertOk();
        $this->assertNotNull($response->json('reviewed_at'));
        $this->assertEquals(0, $this->child->fresh()->allergens()->count());
    }

    public function test_replacing_with_empty_set_still_counts_as_reviewed(): void
    {
        $peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        $this->child->allergens()->attach($peanuts->id);

        Sanctum::actingAs($this->parent);

        $response = $this->putJson("/api/v1/users/{$this->child->id}/allergens", [
            'allergen_ids' => [],
        ])->assertOk();

        $this->assertEquals([], $response->json('allergen_ids'));
        $this->assertNotNull($response->json('reviewed_at'));
    }

    // ---------- Module gating ----------

    public function test_module_gating_blocks_allergen_routes_when_food_disabled(): void
    {
        $this->family->settings = ['modules' => ['food' => false]];
        $this->family->save();

        Sanctum::actingAs($this->parent);

        $this->getJson('/api/v1/allergens')->assertForbidden();
        $this->postJson('/api/v1/allergens', ['name' => 'Corn'])->assertForbidden();
        $this->getJson("/api/v1/users/{$this->child->id}/allergens")->assertForbidden();
        $this->putJson("/api/v1/users/{$this->child->id}/allergens", ['allergen_ids' => []])->assertForbidden();
        $this->postJson("/api/v1/users/{$this->child->id}/allergens/mark-reviewed")->assertForbidden();
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
