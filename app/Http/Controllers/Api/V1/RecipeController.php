<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipe\ImportPhotoRequest;
use App\Http\Requests\Recipe\ImportUrlRequest;
use App\Http\Requests\Recipe\StoreRecipeRequest;
use App\Http\Requests\Recipe\UpdateRecipeRequest;
use App\Http\Resources\RatingResource;
use App\Http\Resources\RecipeCookLogResource;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Services\RecipeImportService;
use App\Services\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecipeController extends Controller
{
    public function __construct(
        private RecipeService $recipeService,
        private RecipeImportService $recipeImportService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $family = $request->user()->family;

        $filters = $request->only(['search', 'tag', 'favorite', 'sort', 'per_page', 'safe_for', 'safe_for_members']);

        $recipes = $this->recipeService->searchRecipes($family, $filters);

        return RecipeResource::collection($recipes);
    }

    public function store(StoreRecipeRequest $request): JsonResponse
    {
        $this->authorize('create', Recipe::class);

        $recipe = $this->recipeService->createRecipe(
            $request->user()->family,
            $request->user(),
            $request->validated()
        );

        return response()->json(['recipe' => new RecipeResource($recipe)], 201);
    }

    public function show(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('view', $recipe);

        $recipe->load(['ingredients', 'cookLogs.user', 'ratings.user', 'tags', 'allergens', 'images', 'creator']);

        return response()->json(['recipe' => new RecipeResource($recipe)]);
    }

    public function update(UpdateRecipeRequest $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('update', $recipe);

        $recipe = $this->recipeService->updateRecipe($recipe, $request->validated(), $request->user());

        return response()->json(['recipe' => new RecipeResource($recipe)]);
    }

    public function destroy(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('delete', $recipe);

        $this->recipeService->deleteRecipe($recipe);

        return response()->json(null, 204);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $recipe = Recipe::withTrashed()
            ->where('family_id', $request->user()->family_id)
            ->findOrFail($id);

        $this->authorize('restore', $recipe);

        $this->recipeService->restoreRecipe($recipe);

        $recipe->load(['ingredients', 'tags', 'creator']);

        return response()->json(['recipe' => new RecipeResource($recipe)]);
    }

    public function toggleFavorite(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('update', $recipe);

        $recipe = $this->recipeService->toggleFavorite($recipe);

        return response()->json(['recipe' => new RecipeResource($recipe)]);
    }

    public function cookLogs(Request $request, Recipe $recipe): AnonymousResourceCollection
    {
        $this->authorize('view', $recipe);

        $logs = $recipe->cookLogs()->with('user')->paginate(20);

        return RecipeCookLogResource::collection($logs);
    }

    public function addCookLog(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('addCookLog', $recipe);

        $data = $request->validate([
            'cooked_at' => ['required', 'date'],
            'servings_made' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $log = $this->recipeService->addCookLog($recipe, $request->user(), $data);
        $log->load('user');

        return response()->json(['cook_log' => new RecipeCookLogResource($log)], 201);
    }

    public function rate(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('rate', $recipe);

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $rating = $this->recipeService->rateRecipe($recipe, $request->user(), $data['score']);
        $rating->load('user');

        return response()->json(['rating' => new RatingResource($rating)]);
    }

    public function ratings(Request $request, Recipe $recipe): AnonymousResourceCollection
    {
        $this->authorize('view', $recipe);

        $ratings = $recipe->ratings()->with('user')->get();

        return RatingResource::collection($ratings);
    }

    public function importFromUrl(ImportUrlRequest $request): JsonResponse
    {
        $preview = $request->boolean('preview');
        $family = $request->user()->family;
        $user = $request->user();

        $result = $this->recipeImportService->importFromUrl(
            $request->validated('url'),
            $family,
            $user,
            $preview,
        );

        if ($preview) {
            return response()->json($result);
        }

        return response()->json(['recipe' => new RecipeResource($result['recipe'])], 201);
    }

    public function importFromPhoto(ImportPhotoRequest $request): JsonResponse
    {
        $preview = $request->boolean('preview');
        $family = $request->user()->family;
        $user = $request->user();

        $result = $this->recipeImportService->importFromPhoto(
            $request->file('photo'),
            $family,
            $user,
            $preview,
        );

        if ($preview) {
            return response()->json($result);
        }

        return response()->json(['recipe' => new RecipeResource($result['recipe'])], 201);
    }

    public function importFromSocialMedia(Request $request): JsonResponse
    {
        $this->authorize('create', Recipe::class);

        return response()->json([
            'message' => 'Social media import coming soon',
        ], 501);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->authorize('create', Recipe::class);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:10240'],
        ]);

        $file = $request->file('image');
        $filename = Str::uuid()->toString().'.'.$file->guessExtension();
        Storage::disk('public')->putFileAs('recipes', $file, $filename);

        return response()->json([
            'image_path' => 'recipes/'.$filename,
        ], 201);
    }
}
