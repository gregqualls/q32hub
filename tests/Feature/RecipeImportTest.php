<?php

namespace Tests\Feature;

use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeImportTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private User $parent;

    private User $child;

    /** HTML page with a valid schema.org/Recipe JSON-LD block */
    private string $jsonLdHtml;

    /** HTML page with no JSON-LD (forces LLM fallback) */
    private string $plainHtml;

    protected function setUp(): void
    {
        parent::setUp();

        // Provide a fake API key so resolveApiKey() doesn't throw in tests
        config(['kinhold.chatbot.api_key' => 'test-api-key']);

        $this->family = Family::create([
            'name' => 'Test Family',
            'slug' => 'test-family',
            'invite_code' => 'TEST01',
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

        $this->jsonLdHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Recipe",
  "name": "Chicken Parmesan",
  "description": "Classic Italian comfort food.",
  "recipeYield": "4 servings",
  "prepTime": "PT20M",
  "cookTime": "PT30M",
  "recipeIngredient": ["2 lbs chicken breast", "1 cup breadcrumbs"],
  "recipeInstructions": [
    {"@type": "HowToStep", "text": "Preheat oven to 400F."},
    {"@type": "HowToStep", "text": "Season chicken."}
  ]
}
</script>
</head>
<body><h1>Chicken Parmesan</h1></body>
</html>
HTML;

        $this->plainHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Simple Recipe Page</title></head>
<body>
<h1>Banana Bread</h1>
<p>A delicious quick bread.</p>
<ul>
  <li>3 bananas</li>
  <li>2 cups flour</li>
</ul>
<ol>
  <li>Mash bananas.</li>
  <li>Mix in flour.</li>
  <li>Bake at 350F for 60 minutes.</li>
</ol>
</body>
</html>
HTML;
    }

    // ── URL Import — JSON-LD path ──

    public function test_url_import_with_json_ld_extracts_recipe_without_calling_recipe_llm(): void
    {
        Sanctum::actingAs($this->parent);

        // Anthropic is stubbed to a benign empty-allergens payload. The JSON-LD
        // path still parses the recipe locally; the allergen pass is the only
        // legitimate Anthropic call. If recipe extraction silently regressed to
        // an LLM call, the stub's empty payload would not contain title/servings
        // and the assertions below would fail.
        Http::fake([
            'example.com/*' => Http::response($this->jsonLdHtml, 200),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['allergens' => []])]],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Chicken Parmesan');
        $response->assertJsonPath('servings', 4);
        $response->assertJsonPath('prep_time', 20);
        $response->assertJsonPath('cook_time', 30);
        $response->assertJsonStructure(['ingredients', 'instructions']);

        // At most one Anthropic call (the allergen pass). Recipe extraction must
        // not depend on the LLM for JSON-LD inputs.
        Http::assertSentCount(2); // 1 to example.com for HTML, 1 to anthropic for allergens
    }

    // ── URL Import — LLM fallback path ──

    public function test_url_import_falls_back_to_llm_when_no_json_ld(): void
    {
        Sanctum::actingAs($this->parent);

        $llmResponseBody = json_encode([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'title' => 'Banana Bread',
                    'description' => 'A delicious quick bread.',
                    'servings' => 8,
                    'prep_time' => 10,
                    'cook_time' => 60,
                    'ingredients' => [
                        ['name' => 'bananas', 'quantity' => '3', 'unit' => null, 'preparation' => 'mashed'],
                        ['name' => 'flour', 'quantity' => '2', 'unit' => 'cups', 'preparation' => null],
                    ],
                    'instructions' => ['Mash bananas.', 'Mix in flour.', 'Bake at 350F for 60 minutes.'],
                ]),
            ]],
            'stop_reason' => 'end_turn',
        ]);

        Http::fake([
            'example.com/*' => Http::response($this->plainHtml, 200),
            'api.anthropic.com/*' => Http::response($llmResponseBody, 200),
        ]);

        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/banana-bread',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Banana Bread');
        $response->assertJsonPath('servings', 8);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com'));
    }

    // ── Photo Import ──

    public function test_photo_import_extracts_recipe_via_claude_vision(): void
    {
        Sanctum::actingAs($this->parent);
        Storage::fake('public');

        $llmResponseBody = json_encode([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'title' => 'Handwritten Pancakes',
                    'description' => null,
                    'servings' => 2,
                    'prep_time' => 5,
                    'cook_time' => 10,
                    'ingredients' => [
                        ['name' => 'flour', 'quantity' => '1', 'unit' => 'cup', 'preparation' => null],
                        ['name' => 'egg', 'quantity' => '1', 'unit' => null, 'preparation' => null],
                    ],
                    'instructions' => ['Mix ingredients.', 'Cook on griddle.'],
                ]),
            ]],
            'stop_reason' => 'end_turn',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($llmResponseBody, 200),
        ]);

        $photo = UploadedFile::fake()->image('recipe.jpg', 400, 300);

        $response = $this->postJson('/api/v1/recipes/import/photo?preview=1', [
            'photo' => $photo,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Handwritten Pancakes');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return false;
            }
            $data = json_decode($request->body(), true);
            $content = $data['messages'][0]['content'] ?? [];

            return collect($content)->contains(fn ($c) => ($c['type'] ?? '') === 'image');
        });
    }

    // ── Preview mode — no DB persistence ──

    public function test_preview_mode_returns_data_without_persisting(): void
    {
        Sanctum::actingAs($this->parent);

        Http::fake([
            'example.com/*' => Http::response($this->jsonLdHtml, 200),
        ]);

        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Chicken Parmesan');
        $this->assertDatabaseCount('recipes', 0);
    }

    // ── Save mode — creates DB record ──

    public function test_save_mode_creates_recipe_and_ingredients(): void
    {
        Sanctum::actingAs($this->parent);

        Http::fake([
            'example.com/*' => Http::response($this->jsonLdHtml, 200),
        ]);

        $response = $this->postJson('/api/v1/recipes/import/url', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('recipe.title', 'Chicken Parmesan');
        $this->assertDatabaseHas('recipes', ['title' => 'Chicken Parmesan']);
        $this->assertDatabaseCount('recipe_ingredients', 2);
    }

    // ── Rate limiting ──

    public function test_rate_limiting_blocks_after_20_requests(): void
    {
        Sanctum::actingAs($this->parent);

        Http::fake([
            'example.com/*' => Http::response($this->jsonLdHtml, 200),
        ]);

        // Make 20 successful requests
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/recipes/import/url?preview=1', [
                'url' => 'https://example.com/recipe/chicken-parmesan',
            ])->assertStatus(200);
        }

        // 21st should be rate-limited
        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ]);

        $response->assertStatus(429);
    }

    // ── Child access — configurable via family settings ──

    public function test_child_can_import_when_recipe_creation_allows_all(): void
    {
        Sanctum::actingAs($this->child);

        Http::fake([
            'example.com/*' => Http::response($this->jsonLdHtml, 200),
        ]);

        // Default is 'all' — children can create
        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ]);

        $response->assertStatus(200);
    }

    public function test_child_cannot_import_when_recipe_creation_is_parents_only(): void
    {
        Sanctum::actingAs($this->child);

        $settings = $this->family->settings ?? [];
        $settings['recipe_creation'] = ['mode' => 'parents_only'];
        $this->family->settings = $settings;
        $this->family->save();

        $response = $this->postJson('/api/v1/recipes/import/url', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ]);

        $response->assertStatus(403);
    }

    // ── Validation ──

    public function test_malformed_url_returns_422(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes/import/url', [
            'url' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['url']);
    }

    // ── Claude API failure — graceful error ──

    public function test_claude_api_failure_returns_graceful_error(): void
    {
        Sanctum::actingAs($this->parent);

        Http::fake([
            'example.com/*' => Http::response($this->plainHtml, 200),
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        $response = $this->postJson('/api/v1/recipes/import/url', [
            'url' => 'https://example.com/banana-bread',
        ]);

        $response->assertStatus(500);

        // Must NOT leak raw API error details
        $responseBody = $response->getContent();
        $this->assertStringNotContainsString('overloaded', $responseBody);
        $this->assertStringNotContainsString('anthropic.com', $responseBody);
    }

    // ── Duplicate URL detection ──

    public function test_duplicate_url_import_returns_duplicate_warning(): void
    {
        Sanctum::actingAs($this->parent);

        Http::fake([
            'example.com/*' => Http::response($this->jsonLdHtml, 200),
        ]);

        // First import — save it
        $this->postJson('/api/v1/recipes/import/url', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ])->assertStatus(201);

        // Second import of same URL — preview should flag duplicate
        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/recipe/chicken-parmesan',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['duplicate_of', 'duplicate_title']);
        $response->assertJsonPath('duplicate_title', 'Chicken Parmesan');
    }

    // ── Ingredient deduplication ──

    public function test_duplicate_ingredients_are_deduplicated_on_save(): void
    {
        Sanctum::actingAs($this->parent);

        // JSON-LD with duplicate ingredients (same name+quantity+unit)
        $htmlWithDupes = <<<'HTML'
<!DOCTYPE html>
<html><head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Recipe",
  "name": "Duplicate Ingredient Test",
  "recipeIngredient": ["2 cups flour", "1 egg", "2 cups flour", "1 cup sugar", "1 egg"],
  "recipeInstructions": [{"@type": "HowToStep", "text": "Mix everything."}]
}
</script>
</head><body></body></html>
HTML;

        Http::fake([
            'example.com/*' => Http::response($htmlWithDupes, 200),
        ]);

        $response = $this->postJson('/api/v1/recipes/import/url', [
            'url' => 'https://example.com/recipe/dupes',
        ]);

        $response->assertStatus(201);

        // Should only have 3 unique ingredients, not 5
        $this->assertDatabaseCount('recipe_ingredients', 3);
    }

    // ── Social media stub ──

    public function test_social_media_import_returns_501(): void
    {
        Sanctum::actingAs($this->parent);

        $response = $this->postJson('/api/v1/recipes/import/social', []);

        $response->assertStatus(501);
        $response->assertJsonPath('message', 'Social media import coming soon');
    }

    // ── Ingredient parsing — JSON-LD path (#160) ──

    public function test_json_ld_ingredient_strings_are_parsed_into_structured_fields(): void
    {
        Sanctum::actingAs($this->parent);

        $html = <<<'HTML'
<!DOCTYPE html><html><head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Recipe",
  "name": "Parsing Test",
  "recipeIngredient": [
    "2 cups flour",
    "1/2 cup butter",
    "3 large eggs",
    "1 tsp vanilla extract, pure"
  ],
  "recipeInstructions": [{"@type": "HowToStep", "text": "Mix everything."}]
}
</script>
</head><body></body></html>
HTML;

        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/recipe/parse-test',
        ]);

        $response->assertStatus(200);
        $ingredients = $response->json('ingredients');

        // "2 cups flour" — name must not contain the quantity
        $flour = collect($ingredients)->firstWhere('name', 'flour');
        $this->assertNotNull($flour, 'flour ingredient not found');
        $this->assertEquals('2', $flour['quantity']);
        $this->assertEquals('cups', $flour['unit']);

        // "1/2 cup butter" — fraction parsed to decimal
        $butter = collect($ingredients)->firstWhere('name', 'butter');
        $this->assertNotNull($butter, 'butter ingredient not found');
        $this->assertEquals('0.5', $butter['quantity']);
        $this->assertEquals('cup', $butter['unit']);

        // "3 large eggs" — no unit; "large" is an adjective bundled into the name
        $eggs = collect($ingredients)->firstWhere('name', 'large eggs');
        $this->assertNotNull($eggs, 'large eggs ingredient not found');
        $this->assertEquals('3', $eggs['quantity']);
        $this->assertNull($eggs['unit']);

        // "1 tsp vanilla extract, pure" — preparation split on comma
        $vanilla = collect($ingredients)->firstWhere('name', 'vanilla extract');
        $this->assertNotNull($vanilla, 'vanilla extract ingredient not found');
        $this->assertEquals('pure', $vanilla['preparation']);
    }

    // ── LLM quantity-in-name post-processing (#160) ──

    public function test_llm_quantity_in_name_is_recovered_via_post_processing(): void
    {
        Sanctum::actingAs($this->parent);

        // Simulate an LLM response where quantity ended up in the name field
        $llmResponseBody = json_encode([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'title' => 'Cheese Sauce',
                    'description' => null,
                    'servings' => 4,
                    'prep_time' => 5,
                    'cook_time' => 10,
                    'ingredients' => [
                        ['name' => '2 cups shredded cheese', 'quantity' => null, 'unit' => null, 'preparation' => null],
                        ['name' => '1/2 cup milk', 'quantity' => null, 'unit' => null, 'preparation' => null],
                    ],
                    'instructions' => ['Melt cheese into milk.'],
                ]),
            ]],
            'stop_reason' => 'end_turn',
        ]);

        Http::fake([
            'example.com/*' => Http::response('<html><body>No JSON-LD here</body></html>', 200),
            'api.anthropic.com/*' => Http::response($llmResponseBody, 200),
        ]);

        $response = $this->postJson('/api/v1/recipes/import/url?preview=1', [
            'url' => 'https://example.com/cheese-sauce',
        ]);

        $response->assertStatus(200);
        $ingredients = $response->json('ingredients');

        $cheese = collect($ingredients)->firstWhere('name', 'shredded cheese');
        $this->assertNotNull($cheese, 'shredded cheese ingredient not found after post-processing');
        $this->assertEquals('2', $cheese['quantity']);
        $this->assertEquals('cups', $cheese['unit']);

        $milk = collect($ingredients)->firstWhere('name', 'milk');
        $this->assertNotNull($milk, 'milk ingredient not found after post-processing');
        $this->assertEquals('0.5', $milk['quantity']);
        $this->assertEquals('cup', $milk['unit']);
    }
}
