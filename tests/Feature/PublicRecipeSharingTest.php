<?php

namespace Tests\Feature;

use App\Enums\AllergenPresence;
use App\Enums\AllergenSource;
use App\Enums\FamilyRole;
use App\Models\Allergen;
use App\Models\Family;
use App\Models\Recipe;
use App\Models\RecipeImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicRecipeSharingTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private User $parent;

    private User $child;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->family = Family::create([
            'name' => 'The Ellis Family',
            'slug' => 'ellis',
            'invite_code' => 'ELLIS1',
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

        $this->recipe = Recipe::create([
            'family_id' => $this->family->id,
            'created_by' => $this->parent->id,
            'title' => 'Banana Bread',
            'description' => 'Family favourite.',
            'servings' => 8,
            'prep_time_minutes' => 10,
            'cook_time_minutes' => 50,
            'instructions' => [
                ['step' => 1, 'text' => 'Mash bananas.'],
                ['step' => 2, 'text' => 'Mix dry ingredients.'],
            ],
        ]);
    }

    // ── API: share / attribution / revoke ──

    public function test_parent_can_publish_a_recipe_and_url_is_returned(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson("/api/v1/recipes/{$this->recipe->id}/share")
            ->assertCreated();

        $share = $response->json('share');
        $this->assertTrue($share['is_shared']);
        $this->assertNotNull($share['url']);
        $this->assertStringContainsString('/r/', $share['url']);
        $this->assertFalse($share['visible_attribution']);

        $this->recipe->refresh();
        $this->assertNotNull($this->recipe->share_token);
    }

    public function test_publishing_twice_does_not_rotate_the_token(): void
    {
        Sanctum::actingAs($this->parent);

        $first = $this->postJson("/api/v1/recipes/{$this->recipe->id}/share")->json('share');
        $second = $this->postJson("/api/v1/recipes/{$this->recipe->id}/share")->json('share');

        $this->assertEquals($first['url'], $second['url']);
    }

    public function test_child_cannot_publish_recipe(): void
    {
        Sanctum::actingAs($this->child);

        $this->postJson("/api/v1/recipes/{$this->recipe->id}/share")->assertForbidden();
    }

    public function test_patch_toggles_attribution_without_changing_token(): void
    {
        $this->recipe->forceFill(['share_token' => 'fixedtoken1234567890ab'])->save();
        Sanctum::actingAs($this->parent);

        $response = $this->patchJson("/api/v1/recipes/{$this->recipe->id}/share", [
            'visible_attribution' => true,
        ])->assertOk();

        $share = $response->json('share');
        $this->assertTrue($share['visible_attribution']);
        $this->assertStringContainsString('fixedtoken1234567890ab', $share['url']);
    }

    public function test_patch_attribution_fails_when_not_shared(): void
    {
        Sanctum::actingAs($this->parent);

        $this->patchJson("/api/v1/recipes/{$this->recipe->id}/share", [
            'visible_attribution' => true,
        ])->assertStatus(422);
    }

    public function test_revoke_clears_token_and_old_url_404s(): void
    {
        Sanctum::actingAs($this->parent);

        $url = $this->postJson("/api/v1/recipes/{$this->recipe->id}/share")->json('share.url');
        $token = basename(parse_url($url, PHP_URL_PATH));

        // Public URL works while shared
        $this->get("/r/{$token}")->assertOk();

        // Revoke
        $this->deleteJson("/api/v1/recipes/{$this->recipe->id}/share")->assertOk();

        // Old URL now hard-404s
        $this->get("/r/{$token}")->assertNotFound();

        $this->recipe->refresh();
        $this->assertNull($this->recipe->share_token);
        $this->assertFalse($this->recipe->share_visible_attribution);
    }

    public function test_revoking_and_resharing_mints_a_new_token(): void
    {
        Sanctum::actingAs($this->parent);

        $first = $this->postJson("/api/v1/recipes/{$this->recipe->id}/share")->json('share.url');
        $this->deleteJson("/api/v1/recipes/{$this->recipe->id}/share")->assertOk();
        $second = $this->postJson("/api/v1/recipes/{$this->recipe->id}/share")->json('share.url');

        $this->assertNotEquals($first, $second);
    }

    public function test_resource_exposes_share_state_for_owner(): void
    {
        Sanctum::actingAs($this->parent);

        $this->postJson("/api/v1/recipes/{$this->recipe->id}/share");
        $response = $this->getJson("/api/v1/recipes/{$this->recipe->id}")->assertOk();

        $this->assertTrue($response->json('recipe.share.is_shared'));
        $this->assertNotNull($response->json('recipe.share.url'));
    }

    // ── Public route ──

    public function test_public_route_renders_recipe_with_title(): void
    {
        $this->recipe->forceFill(['share_token' => 'visibletoken123456789a'])->save();

        $this->get('/r/visibletoken123456789a')
            ->assertOk()
            ->assertSee('Banana Bread', false)
            ->assertSee('Family favourite.', false)
            ->assertSee('Mash bananas.', false);
    }

    public function test_public_route_emits_og_tags_with_image(): void
    {
        $this->recipe->forceFill(['share_token' => 'ogimgtoken1234567890ab'])->save();
        RecipeImage::create([
            'id' => (string) Str::uuid(),
            'recipe_id' => $this->recipe->id,
            'path' => 'recipes/banana.jpg',
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        $this->recipe->forceFill(['image_path' => 'recipes/banana.jpg'])->save();

        $response = $this->get('/r/ogimgtoken1234567890ab')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('property="og:type" content="article"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('Banana Bread', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('recipes/banana.jpg', $html);
    }

    public function test_public_route_renders_allergens(): void
    {
        $this->recipe->forceFill(['share_token' => 'allergentkn1234567890a'])->save();
        $peanuts = Allergen::where('slug', 'peanuts')->whereNull('family_id')->firstOrFail();
        $this->recipe->allergens()->attach($peanuts->id, [
            'id' => (string) Str::uuid(),
            'presence' => AllergenPresence::Contains->value,
            'source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $this->parent->id,
            'confirmed_at' => now(),
        ]);

        $response = $this->get('/r/allergentkn1234567890a')->assertOk();

        $this->assertStringContainsString('Contains', $response->getContent());
        $this->assertStringContainsString('Peanuts', $response->getContent());
    }

    public function test_public_route_attribution_anonymous_by_default(): void
    {
        $this->recipe->forceFill([
            'share_token' => 'attribtoken12345678901',
            'share_visible_attribution' => false,
        ])->save();

        $html = $this->get('/r/attribtoken12345678901')->assertOk()->getContent();
        $this->assertStringNotContainsString('The Ellis Family', $html);
        $this->assertStringContainsString('Shared via', $html);
    }

    public function test_public_route_attribution_visible_when_opted_in(): void
    {
        $this->recipe->forceFill([
            'share_token' => 'attribtoken22345678901',
            'share_visible_attribution' => true,
        ])->save();

        $html = $this->get('/r/attribtoken22345678901')->assertOk()->getContent();
        $this->assertStringContainsString('The Ellis Family', $html);
        $this->assertStringContainsString('Shared by', $html);
    }

    public function test_public_route_404_on_unknown_token(): void
    {
        $this->get('/r/this-token-does-not-exist')->assertNotFound();
    }

    public function test_public_route_does_not_require_authentication(): void
    {
        $this->recipe->forceFill(['share_token' => 'guesttoken1234567890ab'])->save();

        // No Sanctum::actingAs — public access
        $this->get('/r/guesttoken1234567890ab')->assertOk();
    }
}
