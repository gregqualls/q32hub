<?php

namespace App\Services;

use App\Enums\AllergenSource;
use App\Models\Allergen;
use App\Models\Family;
use App\Models\Recipe;
use App\Models\User;
use App\Rules\FractionalQuantity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecipeImportService
{
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const MAX_TOKENS = 2048;

    private const ALLERGEN_THRESHOLD_AUTO = 0.95;

    /**
     * Allergen-extraction instructions injected into both prompts.
     * Slugs are dynamic per family (Big 9 + custom rows).
     */
    private static function allergenPromptSection(array $allergenSlugs): string
    {
        $list = empty($allergenSlugs) ? '(none configured)' : '- '.implode("\n- ", $allergenSlugs);

        return <<<PROMPT

Also identify which of these allergens the recipe likely contains, based on the ingredients:
{$list}

Add to the JSON:
"allergens": [
  {"slug": "peanuts", "presence": "contains", "confidence": 0.98},
  {"slug": "milk", "presence": "may_contain", "confidence": 0.6}
]

Allergen rules:
- Only use slugs from the list above. Do not invent slugs.
- presence: "contains" when the allergen is an explicit ingredient. "may_contain" only for clear cross-contamination cues (e.g. "made in a facility that also processes peanuts").
- confidence: 0.0–1.0, your estimate of how likely the allergen is present given the ingredient list. Be conservative — a 1.0 means absolutely certain.
- Omit anything below ~0.3 confidence. Empty array if none detected.
- For wheat/gluten check explicitly: flour, bread, pasta, soy sauce, beer.
- For milk check: butter, cream, cheese, yogurt, ghee.
- For eggs check: mayo, custard, baked goods using eggs as a binder.
PROMPT;
    }

    private static function urlSystemPrompt(array $allergenSlugs): string
    {
        return <<<'PROMPT'
You are a recipe extraction assistant. Extract a structured recipe from the following web page content.

Return a JSON object with EXACTLY these fields:
{
  "title": "Recipe title (string, required)",
  "description": "Short description (string, optional)",
  "servings": "Number of servings (integer, optional)",
  "prep_time": "Prep time in minutes (integer, optional)",
  "cook_time": "Cook time in minutes (integer, optional)",
  "ingredients": [
    {
      "name": "Ingredient name only — NO quantity or unit here (string, required)",
      "quantity": "Numeric amount as decimal string, e.g. '2', '0.5', '1.5' (string, optional)",
      "unit": "Unit of measure only, e.g. 'cups', 'tbsp', 'oz' (string, optional)",
      "preparation": "Prep method only, e.g. 'diced', 'minced', 'melted' (string, optional)"
    }
  ],
  "instructions": ["Step 1 text", "Step 2 text", "..."]
}

CRITICAL ingredient rules — failure to follow these will break the app:
- The `name` field must contain ONLY the ingredient name. Never put a quantity or unit in `name`.
- CORRECT: "2 cups diced onion" → name: "onion", quantity: "2", unit: "cups", preparation: "diced"
- WRONG:   "2 cups diced onion" → name: "2 cups diced onion", quantity: null, unit: null
- CORRECT: "1/2 cup butter, melted" → name: "butter", quantity: "0.5", unit: "cup", preparation: "melted"
- CORRECT: "3 large eggs" → name: "eggs", quantity: "3", unit: null, preparation: null
- Convert fractions to decimals in the quantity field: 1/2 → "0.5", 1/4 → "0.25", 3/4 → "0.75"

Other rules:
- Extract ONLY recipe information. Ignore ads, navigation, comments.
- If a field is not found, set it to null (except title and instructions which are required).
- Instructions should be clean step strings without numbering prefixes.
- Return valid JSON only. No markdown, no explanation.
PROMPT
            .self::allergenPromptSection($allergenSlugs);
    }

    private static function photoPrompt(array $allergenSlugs): string
    {
        return <<<'PROMPT'
Extract a structured recipe from this image. Return JSON with:
{
  "title": "Recipe title",
  "description": "Short description",
  "servings": 4,
  "prep_time": 15,
  "cook_time": 30,
  "ingredients": [
    {"name": "ingredient name only", "quantity": "2", "unit": "cups", "preparation": "diced"}
  ],
  "instructions": ["Step 1", "Step 2"]
}

CRITICAL: The `name` field must contain ONLY the ingredient name — never the quantity or unit.
- CORRECT: "2 cups flour" → name: "flour", quantity: "2", unit: "cups"
- WRONG:   "2 cups flour" → name: "2 cups flour", quantity: null
Convert fractions to decimals: 1/2 → "0.5", 1/4 → "0.25", 3/4 → "0.75".
If any field cannot be determined, set it to null.
Return valid JSON only.
PROMPT
            .self::allergenPromptSection($allergenSlugs);
    }

    public function __construct(
        private RecipeService $recipeService,
        private AiUsageService $usage,
    ) {}

    /**
     * Import a recipe from a URL.
     *
     * @return array<string, mixed>
     */
    public function importFromUrl(string $url, Family $family, User $user, bool $preview = false): array
    {
        $html = $this->fetchUrl($url);

        $data = $this->parseJsonLd($html);
        $usedLlm = false;

        if ($data === null) {
            $data = $this->extractViaLlm($html, $family);
            $usedLlm = true;
        }

        $this->validateExtracted($data);

        // JSON-LD paths skip the LLM entirely, so they have no allergen data.
        // Run an ingredient-only allergen pass so the import is consistent.
        // Silently no-ops if the family has no AI configured (BYOK off, etc.).
        if (! $usedLlm && empty($data['allergens']) && ! empty($data['ingredients'])) {
            try {
                $data['allergens'] = $this->extractAllergensFromIngredients($data['ingredients'], $family);
            } catch (\Throwable $e) {
                Log::info('RecipeImportService: allergen pass skipped after JSON-LD', ['reason' => $e->getMessage()]);
                $data['allergens'] = [];
            }
        }

        $data['source_url'] = $url;
        $data['source_type'] = 'url';

        // Extract and store the recipe image
        $imageUrl = $data['image_url'] ?? $this->extractOgImage($html);
        unset($data['image_url']);
        if ($imageUrl) {
            $storedPath = $this->downloadAndStoreImage($imageUrl);
            if ($storedPath) {
                $data['image_path'] = $storedPath;
            }
        }

        // Check for duplicate source_url within this family
        $existing = Recipe::where('family_id', $family->id)
            ->where('source_url', $url)
            ->first();

        if ($existing) {
            $data['duplicate_of'] = $existing->id;
            $data['duplicate_title'] = $existing->title;
        }

        if ($preview) {
            return $data;
        }

        return $this->persistImport($data, $family, $user);
    }

    /**
     * Import a recipe from a photo.
     *
     * @return array<string, mixed>
     */
    public function importFromPhoto(UploadedFile $photo, Family $family, User $user, bool $preview = false): array
    {
        $data = $this->extractFromPhoto($photo, $family);

        $this->validateExtracted($data);

        $data['source_type'] = 'photo';

        // Store the image now — whether preview or not — so the form can display it
        // and it gets included in the final save payload without re-uploading.
        $imagePath = $this->storeRecipeImage($photo);
        $data['image_path'] = $imagePath;

        if ($preview) {
            return $data;
        }

        return $this->persistImport($data, $family, $user);
    }

    /**
     * Fetch raw HTML from a URL with SSRF protection.
     *
     * Resolves DNS once and validates the IP before making the request.
     * The request is then pinned to the validated IP via cURL's RESOLVE option
     * to prevent DNS rebinding (TOCTOU) attacks.
     */
    private function fetchUrl(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'http' ? 80 : 443);

        // Resolve DNS once and validate the IP
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // gethostbyname returns the input if resolution fails
            throw new HttpException(422, "Couldn't fetch that URL. Check the link and try again.");
        }

        if (! $this->isPublicIp($ip)) {
            throw new HttpException(422, "Couldn't fetch that URL. Check the link and try again.");
        }

        try {
            // Pin DNS resolution to the validated IP to prevent rebinding
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])->withOptions([
                'resolve' => ["{$host}:{$port}:{$ip}"],
            ])->timeout(15)->get($url);
        } catch (ConnectionException $e) {
            throw new HttpException(422, "Couldn't fetch that URL. Check the link and try again.");
        }

        if ($response->failed()) {
            $status = $response->status();
            // 402 / 403 / 429 typically mean the site is blocking scrapers
            if (in_array($status, [401, 402, 403, 429], true)) {
                throw new HttpException(422, "This site blocks automated imports (HTTP {$status}). Try a different recipe site, or use \"From Photo\" with a screenshot.");
            }
            if ($status === 404) {
                throw new HttpException(422, 'Recipe page not found (404). Double-check the URL.');
            }

            throw new HttpException(422, "Couldn't fetch that URL (HTTP {$status}). Try a different recipe site, or use \"From Photo\".");
        }

        return $response->body();
    }

    /**
     * Attempt to parse schema.org/Recipe JSON-LD from HTML.
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonLd(string $html): ?array
    {
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches);

        foreach ($matches[1] as $raw) {
            $decoded = json_decode(trim($raw), true);

            if (! is_array($decoded)) {
                continue;
            }

            // Handle @graph wrapper
            $candidates = isset($decoded['@graph']) ? $decoded['@graph'] : [$decoded];

            foreach ($candidates as $item) {
                $type = $item['@type'] ?? '';
                if (is_array($type)) {
                    $type = implode(',', $type);
                }

                if (stripos($type, 'Recipe') !== false) {
                    return $this->mapJsonLdToInternal($item);
                }
            }
        }

        return null;
    }

    /**
     * Map schema.org Recipe fields to our internal format.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapJsonLdToInternal(array $item): array
    {
        $ingredients = [];
        foreach ($item['recipeIngredient'] ?? [] as $line) {
            $cleaned = $this->cleanText((string) $line);
            if ($cleaned !== '') {
                $ingredients[] = $this->parseIngredientString($cleaned);
            }
        }

        $instructions = [];
        $rawInstructions = $item['recipeInstructions'] ?? [];

        if (is_string($rawInstructions)) {
            $instructions = array_values(array_filter(array_map(fn ($s) => $this->cleanText($s), explode("\n", $rawInstructions))));
        } elseif (is_array($rawInstructions)) {
            foreach ($rawInstructions as $step) {
                if (is_string($step)) {
                    $instructions[] = $this->cleanText($step);
                } elseif (is_array($step)) {
                    // HowToStep or HowToSection
                    if (isset($step['itemListElement'])) {
                        foreach ($step['itemListElement'] as $subStep) {
                            $text = $subStep['text'] ?? $subStep['name'] ?? '';
                            if ($text !== '') {
                                $instructions[] = $this->cleanText((string) $text);
                            }
                        }
                    } else {
                        $text = $step['text'] ?? $step['name'] ?? '';
                        if ($text !== '') {
                            $instructions[] = $this->cleanText((string) $text);
                        }
                    }
                }
            }
        }

        return [
            'title' => $item['name'] ?? null,
            'description' => isset($item['description']) ? html_entity_decode(strip_tags((string) $item['description']), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            'servings' => $this->parseServings($item['recipeYield'] ?? null),
            'prep_time' => $this->parseIsoDuration($item['prepTime'] ?? null),
            'cook_time' => $this->parseIsoDuration($item['cookTime'] ?? null),
            'ingredients' => $ingredients,
            'instructions' => array_values(array_filter($instructions)),
            'image_url' => $this->extractJsonLdImageUrl($item['image'] ?? null),
        ];
    }

    /**
     * Strip HTML tags, decode entities, and trim a string.
     */
    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * Parse a plain ingredient string (from JSON-LD recipeIngredient lines) into
     * structured quantity / unit / name / preparation fields.
     *
     * Handles: integers, decimals, fractions (1/2), mixed numbers (1 1/2),
     * unicode fractions (½ ¼ ¾ ⅓ ⅔), and a broad list of common units.
     *
     * @return array<string, string|null>
     */
    private function parseIngredientString(string $line): array
    {
        // Unicode fraction → decimal replacements for regex matching
        $unicodeReplace = ['½' => '0.5', '¼' => '0.25', '¾' => '0.75', '⅓' => '0.333', '⅔' => '0.667', '⅛' => '0.125', '⅜' => '0.375', '⅝' => '0.625', '⅞' => '0.875'];

        // Quantity pattern: mixed number, fraction, integer+unicode, pure unicode, decimal, integer
        $qtyPat = '(?:\d+\s+\d+\/\d+|\d+\/\d+|\d+[½¼¾⅓⅔⅛⅜⅝⅞]|[½¼¾⅓⅔⅛⅜⅝⅞]|\d+(?:\.\d+)?)';

        $units = [
            'cups?', 'c', 'tablespoons?', 'tbsps?', 'tbs?', 'T',
            'teaspoons?', 'tsps?', 'ts?',
            'pounds?', 'lbs?',
            'ounces?', 'oz',
            'grams?', 'g', 'kilograms?', 'kg',
            'milliliters?', 'ml', 'mL', 'liters?', 'l', 'L',
            'pints?', 'pt', 'quarts?', 'qt', 'gallons?', 'gal',
            'cloves?', 'slices?', 'cans?', 'packages?', 'pkgs?',
            'bunches?', 'stalks?', 'sprigs?', 'pinch(?:es)?', 'dash(?:es)?',
            'handfuls?', 'pieces?', 'strips?', 'sticks?', 'heads?',
            'inches?', 'in',
        ];
        $unitPat = '(?:'.implode('|', $units).')';

        // Try: [quantity] [unit] [rest]
        if (preg_match("/^({$qtyPat})\s+({$unitPat})\.?\s+(.+)$/iu", $line, $m)) {
            $rawQty = trim($m[1]);
            $unit = rtrim(trim($m[2]), '.');
            $rest = trim($m[3]);
            [$name, $prep] = $this->splitNamePrep($rest);

            return [
                'name' => $name,
                'quantity' => $this->parseFractionString($rawQty, $unicodeReplace),
                'unit' => $unit,
                'preparation' => $prep,
            ];
        }

        // Try: [quantity] [rest] (no unit)
        if (preg_match("/^({$qtyPat})\s+(.+)$/iu", $line, $m)) {
            $rawQty = trim($m[1]);
            $rest = trim($m[2]);
            [$name, $prep] = $this->splitNamePrep($rest);

            return [
                'name' => $name,
                'quantity' => $this->parseFractionString($rawQty, $unicodeReplace),
                'unit' => null,
                'preparation' => $prep,
            ];
        }

        // No quantity — just the ingredient line
        [$name, $prep] = $this->splitNamePrep($line);

        return ['name' => $name, 'quantity' => null, 'unit' => null, 'preparation' => $prep];
    }

    /**
     * Split "chicken breast, cubed" into ["chicken breast", "cubed"].
     * If no comma, preparation is null.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitNamePrep(string $text): array
    {
        $parts = array_map('trim', explode(',', $text, 2));

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * Convert a raw quantity string (may contain unicode fractions or slash fractions)
     * to a decimal string suitable for the quantity field.
     *
     * @param  array<string, string>  $unicodeReplace
     */
    private function parseFractionString(string $raw, array $unicodeReplace): string
    {
        // Replace unicode fractions with decimals first
        $normalized = strtr($raw, $unicodeReplace);

        $float = FractionalQuantity::parseToFloat($normalized);

        if ($float === null) {
            return $raw; // Return as-is if unparseable
        }

        // Return integer string if whole number, decimal otherwise
        return $float == floor($float) ? (string) (int) $float : (string) $float;
    }

    /**
     * Post-process LLM ingredient output: if the model put the full ingredient
     * string in `name` with no quantity/unit, re-parse it.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function normalizeLlmIngredients(array $parsed): array
    {
        if (empty($parsed['ingredients']) || ! is_array($parsed['ingredients'])) {
            return $parsed;
        }

        $parsed['ingredients'] = array_map(function (mixed $ing) {
            if (! is_array($ing)) {
                return $ing;
            }

            // If quantity and unit are both absent but name looks like a full ingredient
            // string (starts with a number or unicode fraction), re-parse it.
            $hasQty = ! empty($ing['quantity']);
            $hasUnit = ! empty($ing['unit']);
            $name = trim((string) ($ing['name'] ?? ''));

            if (! $hasQty && ! $hasUnit && $name !== '') {
                $looksLikeFull = preg_match('/^[\d½¼¾⅓⅔⅛⅜⅝⅞]/u', $name);
                if ($looksLikeFull) {
                    $reparsed = $this->parseIngredientString($name);
                    // Only apply if we actually extracted something
                    if ($reparsed['quantity'] !== null || $reparsed['unit'] !== null) {
                        return array_merge($ing, $reparsed);
                    }
                }
            }

            return $ing;
        }, $parsed['ingredients']);

        return $parsed;
    }

    /**
     * Parse ISO 8601 duration (e.g. PT30M, PT1H30M) to integer minutes.
     */
    private function parseIsoDuration(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        $str = (string) $value;

        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?/', $str, $m)) {
            $hours = (int) ($m[1] ?? 0);
            $minutes = (int) ($m[2] ?? 0);
            $total = ($hours * 60) + $minutes;

            return $total > 0 ? $total : null;
        }

        return null;
    }

    /**
     * Parse recipeYield to an integer servings count.
     */
    private function parseServings(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        preg_match('/\d+/', (string) $value, $m);

        return isset($m[0]) ? (int) $m[0] : null;
    }

    /**
     * Strip non-recipe HTML and call Claude to extract structured data.
     *
     * @return array<string, mixed>
     */
    private function extractViaLlm(string $html, Family $family): array
    {
        $cleaned = $this->stripNonContentHtml($html);
        // Truncate to ~10k chars to avoid huge token counts
        $cleaned = mb_substr($cleaned, 0, 10000);

        $apiKey = $this->resolveApiKey($family);
        $model = $this->resolveModel($family);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post(self::ANTHROPIC_API_URL, [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => self::urlSystemPrompt($this->familyAllergenSlugs($family)),
            'messages' => [
                ['role' => 'user', 'content' => $cleaned],
            ],
        ]);

        if ($response->failed()) {
            Log::error('RecipeImportService: Anthropic API error (URL import)', [
                'status' => $response->status(),
            ]);
            throw new HttpException(500, 'Something went wrong with recipe extraction. Please try again.');
        }

        $this->trackUsage($family, $response->json('usage'));

        return $this->parseLlmResponse($response->json());
    }

    /**
     * Send photo to Claude Vision and extract recipe data.
     *
     * @return array<string, mixed>
     */
    private function extractFromPhoto(UploadedFile $photo, Family $family): array
    {
        $apiKey = $this->resolveApiKey($family);
        $model = $this->resolveModel($family);

        $base64 = base64_encode(file_get_contents($photo->getRealPath()));
        $mediaType = $photo->getMimeType() ?? 'image/jpeg';

        // HEIC isn't directly supported by Claude Vision — treat as jpeg
        if (in_array($mediaType, ['image/heic', 'image/heif'])) {
            $mediaType = 'image/jpeg';
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post(self::ANTHROPIC_API_URL, [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mediaType,
                                'data' => $base64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => self::photoPrompt($this->familyAllergenSlugs($family)),
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('RecipeImportService: Anthropic API error (photo import)', [
                'status' => $response->status(),
            ]);
            throw new HttpException(500, 'Something went wrong with recipe extraction. Please try again.');
        }

        $this->trackUsage($family, $response->json('usage'));

        return $this->parseLlmResponse($response->json());
    }

    /**
     * Slugs available to this family for AI allergen tagging. Global Big 9 +
     * the family's custom rows. Stable order so prompt caching has a chance.
     */
    private function familyAllergenSlugs(Family $family): array
    {
        return Allergen::availableToFamily((string) $family->id)
            ->orderByDesc('is_big_nine')
            ->orderBy('slug')
            ->pluck('slug')
            ->all();
    }

    /**
     * Run AI allergen extraction over a free-form ingredient list. Used by the
     * artisan backfill to scan existing recipes without re-fetching their HTML.
     * Returns an array of `{slug, presence, confidence}` or [].
     *
     * @param  array<int, array{name?: string, quantity?: string, unit?: string, preparation?: string}>  $ingredients
     * @return array<int, array{slug: string, presence: string, confidence: float}>
     */
    public function extractAllergensFromIngredients(array $ingredients, Family $family): array
    {
        $slugs = $this->familyAllergenSlugs($family);
        if (empty($slugs) || empty($ingredients)) {
            return [];
        }

        $apiKey = $this->resolveApiKey($family);
        $model = $this->resolveModel($family);

        $lines = collect($ingredients)
            ->map(fn ($i) => trim(($i['quantity'] ?? '').' '.($i['unit'] ?? '').' '.($i['name'] ?? '').($i['preparation'] ? ', '.$i['preparation'] : '')))
            ->filter()
            ->implode("\n");

        $system = 'You are an allergen-detection assistant. Identify allergens present in a recipe given its ingredient list. '.self::allergenPromptSection($slugs).
            ' Return JSON with EXACTLY one field: {"allergens": [...]}';

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post(self::ANTHROPIC_API_URL, [
            'model' => $model,
            'max_tokens' => 512,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $lines]],
        ]);

        if ($response->failed()) {
            Log::error('RecipeImportService: allergen-only extraction failed', ['status' => $response->status()]);

            return [];
        }

        $this->trackUsage($family, $response->json('usage'));

        $parsed = $this->parseLlmResponse($response->json());

        return $this->normalizeAllergens($parsed['allergens'] ?? [], $slugs);
    }

    /**
     * Validate AI-returned allergens against the family's slug list. Drops
     * unknown slugs, invalid presence values, malformed confidence scores.
     *
     * @param  array<mixed>  $raw
     * @param  array<int, string>  $allowedSlugs
     * @return array<int, array{slug: string, presence: string, confidence: float}>
     */
    private function normalizeAllergens(array $raw, array $allowedSlugs): array
    {
        $allowed = array_flip($allowedSlugs);
        $out = [];

        foreach ($raw as $entry) {
            $slug = $entry['slug'] ?? null;
            $presence = $entry['presence'] ?? null;
            $confidence = $entry['confidence'] ?? null;

            if (! is_string($slug) || ! isset($allowed[$slug])) {
                continue;
            }
            if (! in_array($presence, ['contains', 'may_contain'], true)) {
                continue;
            }
            if (! is_numeric($confidence)) {
                continue;
            }
            $confidence = max(0.0, min(1.0, (float) $confidence));

            $out[] = ['slug' => $slug, 'presence' => $presence, 'confidence' => $confidence];
        }

        return $out;
    }

    /**
     * Persist a normalized allergen list onto a recipe with AI provenance.
     * Confidence ≥ ALLERGEN_THRESHOLD_AUTO → source = ai_auto. Otherwise
     * source = ai_suggested. Idempotent on (recipe, allergen, presence).
     *
     * Returns the number of rows written.
     *
     * @param  array<int, array{slug: string, presence: string, confidence: float}>  $allergens
     */
    public function persistAiAllergens(Recipe $recipe, array $allergens): int
    {
        if (empty($allergens)) {
            return 0;
        }

        $slugToId = Allergen::availableToFamily($recipe->family_id)
            ->pluck('id', 'slug');

        $now = now();
        $written = 0;
        $seen = [];

        foreach ($allergens as $entry) {
            $allergenId = $slugToId[$entry['slug']] ?? null;
            if (! $allergenId) {
                continue;
            }
            $dedupeKey = $allergenId.'|'.$entry['presence'];
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $source = $entry['confidence'] >= self::ALLERGEN_THRESHOLD_AUTO
                ? AllergenSource::AiAuto->value
                : AllergenSource::AiSuggested->value;

            // Skip if a row with this (recipe, allergen, presence) already exists —
            // preserves human edits and keeps the operation safe to re-run.
            $exists = DB::table('recipe_allergens')
                ->where('recipe_id', $recipe->id)
                ->where('allergen_id', $allergenId)
                ->where('presence', $entry['presence'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('recipe_allergens')->insert([
                'id' => (string) Str::uuid(),
                'recipe_id' => $recipe->id,
                'allergen_id' => $allergenId,
                'presence' => $entry['presence'],
                'source' => $source,
                'confidence' => $entry['confidence'],
                'confirmed_by' => null,
                'confirmed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * Record an Anthropic call against the family's daily usage cap. Mirrors
     * ChatController's pattern — skip when the family pays its own bill
     * (BYOK / self-hosted) so we don't count requests Kinhold isn't paying for.
     *
     * @param  array<string, mixed>|null  $usage
     */
    private function trackUsage(Family $family, ?array $usage): void
    {
        if (! $this->usage->shouldEnforce($family)) {
            return;
        }

        $this->usage->recordMessage(
            $family,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
        );
    }

    /**
     * Parse the Anthropic API response and decode the JSON recipe.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    private function parseLlmResponse(array $responseData): array
    {
        $text = $responseData['content'][0]['text'] ?? '';

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);

        $parsed = json_decode(trim($text), true);

        if (! is_array($parsed)) {
            throw new HttpException(422, "Couldn't extract a recipe — try manual entry?");
        }

        return $this->normalizeLlmIngredients($parsed);
    }

    /**
     * Validate that minimum required fields are present.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateExtracted(array $data): void
    {
        if (empty($data['title'])) {
            throw new HttpException(422, "Couldn't extract a recipe — try manual entry?");
        }

        $instructions = $data['instructions'] ?? [];
        if (empty($instructions) || ! is_array($instructions)) {
            throw new HttpException(422, "Couldn't extract a recipe — try manual entry?");
        }
    }

    /**
     * Persist the imported recipe via RecipeService.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function persistImport(array $data, Family $family, User $user): array
    {
        $recipeData = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'servings' => isset($data['servings']) ? (int) $data['servings'] : null,
            'prep_time_minutes' => isset($data['prep_time']) ? (int) $data['prep_time'] : null,
            'cook_time_minutes' => isset($data['cook_time']) ? (int) $data['cook_time'] : null,
            'source_url' => $data['source_url'] ?? null,
            'source_type' => $data['source_type'] ?? 'url',
            'image_path' => $data['image_path'] ?? null,
            'instructions' => $data['instructions'] ?? [],
            'ingredients' => $data['ingredients'] ?? [],
        ];

        $recipe = $this->recipeService->createRecipe($family, $user, $recipeData);

        // AI-suggested allergens get persisted with their provenance. The recipe
        // detail view surfaces them as "AI tagged" until a human confirms (or
        // the user edits the recipe via the form, which rewrites as human_confirmed).
        $allergens = $this->normalizeAllergens($data['allergens'] ?? [], $this->familyAllergenSlugs($family));
        if (! empty($allergens)) {
            $this->persistAiAllergens($recipe, $allergens);
            $recipe->load('allergens');
        }

        return ['recipe' => $recipe];
    }

    /**
     * Store uploaded recipe photo to the recipes disk.
     */
    private function storeRecipeImage(UploadedFile $photo): string
    {
        $filename = Str::uuid()->toString().'.'.$photo->guessExtension();

        return Storage::disk('public')->putFileAs('recipes', $photo, $filename);
    }

    /**
     * Extract the image URL from a JSON-LD image field.
     * The field can be a string URL, an ImageObject array, or an array of either.
     */
    private function extractJsonLdImageUrl(mixed $image): ?string
    {
        if (empty($image)) {
            return null;
        }

        if (is_string($image)) {
            return $image;
        }

        if (is_array($image)) {
            // Array of images — take the first usable one
            $first = $image[0] ?? $image;

            if (is_string($first)) {
                return $first ?: null;
            }

            if (is_array($first)) {
                return $first['url'] ?? null;
            }
        }

        return null;
    }

    /**
     * Extract the og:image URL from HTML meta tags.
     */
    private function extractOgImage(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
            return $m[1] ?: null;
        }

        // Also try reversed attribute order
        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $html, $m)) {
            return $m[1] ?: null;
        }

        return null;
    }

    /**
     * Download a remote image and store it to the public recipes disk.
     * Returns the stored path, or null on failure.
     */
    private function downloadAndStoreImage(string $imageUrl): ?string
    {
        try {
            $parsed = parse_url($imageUrl);
            $host = $parsed['host'] ?? '';

            if (empty($host)) {
                return null;
            }

            $ip = gethostbyname($host);
            if ($ip === $host || ! $this->isPublicIp($ip)) {
                return null;
            }

            $response = Http::withOptions([
                'resolve' => ["{$host}:443:{$ip}", "{$host}:80:{$ip}"],
            ])->timeout(10)->get($imageUrl);

            if ($response->failed()) {
                return null;
            }

            // Refuse images over 5 MB to prevent disk exhaustion
            if (strlen($response->body()) > 5_242_880) {
                return null;
            }

            $contentType = $response->header('Content-Type') ?: 'image/jpeg';
            $ext = match (true) {
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'gif') => 'gif',
                str_contains($contentType, 'webp') => 'webp',
                default => 'jpg',
            };

            $filename = Str::uuid()->toString().'.'.$ext;
            Storage::disk('public')->put('recipes/'.$filename, $response->body());

            return 'recipes/'.$filename;
        } catch (\Throwable $e) {
            Log::warning('RecipeImportService: Failed to download recipe image', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Strip navigation, footer, header, aside, and ad-related elements from HTML.
     */
    private function stripNonContentHtml(string $html): string
    {
        // Remove script and style blocks
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html) ?? $html;

        // Remove structural noise elements
        foreach (['nav', 'footer', 'aside', 'header'] as $tag) {
            $html = preg_replace("/<{$tag}\b[^>]*>.*?<\/{$tag}>/si", '', $html) ?? $html;
        }

        // Strip remaining tags and decode entities
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse whitespace
        $text = preg_replace('/\s{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Resolve the Anthropic API key (platform key → family BYOK).
     */
    private function resolveApiKey(Family $family): string
    {
        // Platform key first
        $platformKey = config('kinhold.chatbot.api_key');
        if (! empty($platformKey)) {
            return $platformKey;
        }

        // BYOK fallback
        $settings = $family->settings ?? [];
        $aiMode = $settings['ai_mode'] ?? 'kinhold';

        if ($aiMode === 'byok' && ! empty($settings['ai_api_key'])) {
            $providerSlug = $settings['ai_provider'] ?? 'anthropic';

            if ($providerSlug === 'anthropic') {
                try {
                    return decrypt($settings['ai_api_key']);
                } catch (\Throwable $e) {
                    Log::warning('RecipeImportService: Failed to decrypt family AI API key');
                }
            }
        }

        throw new HttpException(
            500,
            'No AI API key configured. Ask a parent to add one in Settings > API Configuration.'
        );
    }

    /**
     * Resolve the model to use for extraction.
     */
    private function resolveModel(Family $family): string
    {
        $settings = $family->settings ?? [];

        return $settings['ai_model'] ?? config('kinhold.chatbot.model', 'claude-sonnet-4-5-20250929');
    }

    /**
     * Check if an IP address is public (not private, not reserved, not loopback).
     */
    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
