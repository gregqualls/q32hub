<?php

namespace App\Console\Commands;

use App\Jobs\BackfillRecipeAllergens as BackfillJob;
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

    public function handle(): int
    {
        $query = Recipe::query();

        if ($familyId = $this->option('family')) {
            $query->where('family_id', $familyId);
        }

        if (! $this->option('force')) {
            $query->doesntHave('allergens');
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No recipes need a backfill.');

            return self::SUCCESS;
        }

        $this->info("Queueing {$total} recipe(s) for AI allergen backfill...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($chunk) use ($bar) {
            foreach ($chunk as $recipe) {
                $job = new BackfillJob((string) $recipe->id, (bool) $this->option('force'));
                if ($this->option('sync')) {
                    $job->handle(app(RecipeImportService::class));
                } else {
                    BackfillJob::dispatch((string) $recipe->id, (bool) $this->option('force'));
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info($this->option('sync') ? 'Backfill complete.' : 'All jobs queued. Run your queue worker to process them.');

        return self::SUCCESS;
    }
}
