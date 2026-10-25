<?php

namespace App\Console\Commands;

use App\Enums\AssetFileRole;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Marketplace;
use App\Models\MarketplaceCategory;
use App\Services\Metadata\GenerationException;
use App\Services\Metadata\GenerationRequest;
use App\Services\Metadata\GenerationResult;
use App\Services\Metadata\ImagePreparer;
use App\Services\Metadata\MetadataGeneratorFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Runs several models on the same images and writes a side-by-side report (KB-37): tokens and cost
 * per image as billed, how often the answer broke the limits, and an HTML page to judge the texts.
 * Nothing is saved to the works.
 */
class EvaluateMetadataModels extends Command
{
    protected $signature = 'metadata:evaluate
        {--model=* : Models to compare (default: config metadata.evaluation_models)}
        {--driver= : Provider (default: config metadata.driver)}
        {--asset=* : Works by ulid}
        {--limit=20 : Latest works with a preview, when no --asset or --dir is given}
        {--dir= : Images from a folder instead of works}
        {--effort= : Reasoning effort override (empty string turns it off)}
        {--detail= : Image detail override: low | high | auto}';

    protected $description = 'Compare metadata generation models on the same images';

    public function handle(MetadataGeneratorFactory $factory, ImagePreparer $images): int
    {
        $samples = $this->samples($images);

        if (! $samples) {
            $this->error('No images to evaluate.');

            return self::FAILURE;
        }

        $models = $this->option('model') ?: config('metadata.evaluation_models');
        $overrides = array_filter([
            'reasoning_effort' => $this->option('effort'),
            'image_detail' => $this->option('detail'),
        ], fn ($value) => $value !== null);

        $this->info(sprintf('%d images × %d models', count($samples), count($models)));

        $results = [];

        foreach ($models as $model) {
            $generator = $factory->make($this->option('driver'), ['model' => $model, ...$overrides]);

            foreach ($samples as $index => $sample) {
                try {
                    $results[$model][$index] = $generator->generate($sample['request']);
                } catch (GenerationException $e) {
                    $results[$model][$index] = $e->getMessage();
                }

                $this->output->write('.');
            }

            $this->output->writeln(" {$model}");
        }

        $this->summary($models, $results, count($samples));
        $path = $this->report($samples, $models, $results, $overrides);
        $this->info("Report: {$path}");

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, image: string, request: GenerationRequest}>
     */
    protected function samples(ImagePreparer $images): array
    {
        if ($dir = $this->option('dir')) {
            $categories = $this->allCategories(MediaType::Photo);

            return collect(glob(rtrim($dir, '/').'/*.{jpg,jpeg,JPG,JPEG,png,PNG,webp}', GLOB_BRACE) ?: [])
                ->map(function (string $file) use ($images, $categories) {
                    $image = $images->fromBytes(file_get_contents($file));

                    return [
                        'name' => basename($file),
                        'image' => $image,
                        'request' => new GenerationRequest($image, MediaType::Photo, basename($file), categories: $categories[0], categoriesMax: $categories[1]),
                    ];
                })->values()->all();
        }

        $assets = Asset::query()->with('user')
            ->whereHas('files', fn ($query) => $query->where('role', AssetFileRole::Preview))
            ->when($this->option('asset'), fn ($query, $ulids) => $query->whereIn('ulid', $ulids), fn ($query) => $query->latest()->limit((int) $this->option('limit')))
            ->get();

        return $assets->map(function (Asset $asset) use ($images) {
            $image = $images->fromAsset($asset);

            // Every marketplace's categories rather than the owner's: category choice is part of the comparison.
            [$categories, $max] = $this->allCategories($asset->type);

            return [
                'name' => $asset->original_filename,
                'image' => $image,
                'request' => new GenerationRequest($image, $asset->type, $asset->original_filename, $asset->captured_at, $asset->location, $categories, $max),
            ];
        })->values()->all();
    }

    /**
     * Categories of every marketplace that has a list, for the work type.
     *
     * @return array{0: array<string, array<string, string>>, 1: array<string, int>}
     */
    protected function allCategories(MediaType $type): array
    {
        $categories = [];
        $max = [];

        foreach (Marketplace::query()->whereHas('categories')->get() as $marketplace) {
            $categories[$marketplace->code] = MarketplaceCategory::query()->where('marketplace_id', $marketplace->id)
                ->forMediaType($type)->orderBy('name')->pluck('name', 'external_id')->map(fn ($name) => (string) $name)->all();
            $max[$marketplace->code] = (int) ($marketplace->metadata_rules['categories_max'] ?? 1);
        }

        return [array_filter($categories), $max];
    }

    /**
     * @param  list<string>  $models
     * @param  array<string, array<int, GenerationResult|string>>  $results
     */
    protected function summary(array $models, array $results, int $count): void
    {
        $rows = [];

        foreach ($models as $model) {
            $ok = array_filter($results[$model], fn ($result) => $result instanceof GenerationResult);
            $n = max(count($ok), 1);
            $sum = fn (callable $value) => array_sum(array_map($value, $ok));
            $cost = $sum(fn (GenerationResult $r) => $r->cost() ?? 0);

            $rows[] = [
                $model,
                count($ok).'/'.$count,
                round($sum(fn ($r) => $r->usage->input) / $n),
                round($sum(fn ($r) => $r->usage->output) / $n),
                round($sum(fn ($r) => $r->usage->reasoning) / $n),
                round($sum(fn ($r) => count($r->keywords)) / $n),
                count(array_filter($ok, fn ($r) => $r->fixes)),
                isset(config('metadata.prices')[$model]) ? '$'.number_format($cost / $n * 1000, 2) : 'no price',
                round($sum(fn ($r) => $r->durationMs) / $n / 1000, 1).' s',
            ];
        }

        $this->table(['Model', 'OK', 'Input', 'Output', 'Reasoning', 'Keywords', 'Fixed', '$ / 1000', 'Time'], $rows);
    }

    /**
     * @param  list<array{name: string, image: string, request: GenerationRequest}>  $samples
     * @param  list<string>  $models
     * @param  array<string, array<int, GenerationResult|string>>  $results
     * @param  array<string, mixed>  $overrides
     */
    protected function report(array $samples, array $models, array $results, array $overrides): string
    {
        $dir = 'metadata-evaluations/'.now()->format('Y-m-d_His');
        $disk = Storage::disk('local');

        $json = [];

        foreach ($samples as $index => $sample) {
            foreach ($models as $model) {
                $result = $results[$model][$index];
                $json[$sample['name']][$model] = $result instanceof GenerationResult ? $result->toArray() : ['error' => $result];
            }
        }

        $disk->put("{$dir}/results.json", json_encode(['overrides' => $overrides, 'results' => $json], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $disk->put("{$dir}/report.html", view('dev.metadata-evaluation', [
            'samples' => $samples,
            'models' => $models,
            'results' => $results,
            'overrides' => $overrides,
        ])->render());

        return $disk->path("{$dir}/report.html");
    }
}
