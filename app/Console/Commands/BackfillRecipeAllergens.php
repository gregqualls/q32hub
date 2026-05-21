<?php

namespace App\Console\Commands;

use App\Jobs\BackfillRecipeAllergens as BackfillJob;
use App\Models\Family;
use App\Models\Recipe;
use App\Services\RecipeImportService;
use Illuminate\Console\Command;

class BackfillRecipeAllergens extends Command
{
    protected $signature = 'recipes:backfill-allergens
                            {--family= : Limit to a single family ID}
                            {--force : Re-tag even recipes that already have allergens}
                            {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Queue an AI allergen pass over recipes that have no allergen tags yet. Idempotent unless --force.';

    public function handle(RecipeImportService $service): int
    {
        $familyId = $this->option('family');
        $force = (bool) $this->option('force');

        if ($this->option('sync')) {
            // Inline mode is mostly a debugging tool — run each job in-process
            // and skip the queue entirely. Bypasses RateLimited middleware.
            $query = Recipe::query();
            if ($familyId) {
                $query->where('family_id', $familyId);
            }
            if (! $force) {
                $query->doesntHave('allergens');
            }
            $total = (clone $query)->count();
            if ($total === 0) {
                $this->info('No recipes need a backfill.');

                return self::SUCCESS;
            }

            $this->info("Processing {$total} recipe(s) inline...");
            $bar = $this->output->createProgressBar($total);
            $bar->start();
            $query->chunkById(100, function ($chunk) use ($bar, $service, $force) {
                foreach ($chunk as $recipe) {
                    (new BackfillJob((string) $recipe->id, (string) $recipe->family_id, $force))->handle($service);
                    $bar->advance();
                }
            });
            $bar->finish();
            $this->newLine(2);
            $this->info('Backfill complete.');

            return self::SUCCESS;
        }

        // Queued path: delegate to the service so the API endpoint and this
        // command can't drift. Without --family, iterate all families.
        if ($familyId) {
            $total = $service->queueAllergenBackfill($familyId, $force);
            $this->info($total === 0
                ? 'No recipes need a backfill.'
                : "Queued {$total} recipe(s) for AI allergen tagging.");
        } else {
            $total = 0;
            Family::query()->orderBy('id')->chunkById(50, function ($families) use (&$total, $service, $force) {
                foreach ($families as $family) {
                    $total += $service->queueAllergenBackfill((string) $family->id, $force);
                }
            });
            $this->info($total === 0
                ? 'No recipes need a backfill.'
                : "Queued {$total} recipe(s) across all families.");
        }

        if ($total > 0) {
            $this->info('Run your queue worker to process the jobs.');
        }

        return self::SUCCESS;
    }
}
