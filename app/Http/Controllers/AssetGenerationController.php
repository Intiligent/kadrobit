<?php

namespace App\Http\Controllers;

use App\Enums\GenerationTrigger;
use App\Exceptions\ExceptionWithMessage;
use App\Http\Requests\Assets\GenerateMetadataBatchRequest;
use App\Http\Requests\Assets\GenerateMetadataRequest;
use App\Http\Resources\AssetGenerationResource;
use App\Http\Resources\AssetResource;
use App\Jobs\GenerateAssetMetadata;
use App\Models\Asset;
use App\Models\AssetGeneration;
use App\Services\Metadata\AssetMetadataService;
use App\Services\Metadata\GenerationException;
use App\Services\Metadata\GenerationLimitReached;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Metadata generation on request (KB-38): the history of a work, a new generation, filling several works
 * in the queue, and restoring a generation from the history without paying again.
 */
class AssetGenerationController extends Controller
{
    /** History entries returned for a work, newest first. */
    protected const HISTORY_SIZE = 20;

    public function __construct(protected AssetMetadataService $metadata) {}

    public function index(Request $request, string $asset): JsonResponse
    {
        $work = $request->user()->ownAsset($asset);

        return response()->json([
            'data' => AssetGenerationResource::collection($work->generations()->latest('id')->limit(self::HISTORY_SIZE)->get()),
            'meta' => ['remaining' => $this->metadata->remaining($request->user())],
        ]);
    }

    /**
     * Generates now and replaces the requested fields: the user asked, so their edits are replaced too.
     */
    public function store(GenerateMetadataRequest $request, string $asset): JsonResponse
    {
        $work = $request->user()->ownAsset($asset);
        $this->ensureAvailable();

        try {
            $generation = $this->metadata->generate($work, GenerationTrigger::Manual, waiting: true);
        } catch (GenerationException $e) {
            Log::warning("Metadata generation failed for work {$work->ulid}: {$e->getMessage()}");

            throw new ExceptionWithMessage(__('library.generation.failed'), 502, $e);
        }

        return $this->answer($request, $this->metadata->apply($work, $generation, $request->fields()), $generation, 201);
    }

    /**
     * Restores a generation from the history into the requested fields; nothing is generated or paid.
     */
    public function apply(GenerateMetadataRequest $request, string $asset, AssetGeneration $generation): JsonResponse
    {
        $work = $request->user()->ownAsset($asset);

        abort_unless($generation->asset_id === $work->id, 404);

        return $this->answer($request, $this->metadata->apply($work, $generation, $request->fields()), $generation);
    }

    /**
     * Queues filling for several works: only their empty fields, as after an upload.
     */
    public function batch(GenerateMetadataBatchRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAvailable();
        $works = $user->assets()->whereIn('ulid', $request->input('assets'))->get();

        if ($works->count() > $this->metadata->remaining($user)) {
            throw new GenerationLimitReached;
        }

        $works->each(fn (Asset $work) => GenerateAssetMetadata::dispatch($work, GenerationTrigger::Manual));

        return response()->json(['data' => ['queued' => $works->count()]], 202);
    }

    /**
     * Without a configured provider a generation can never succeed: say so instead of queuing or retrying.
     */
    protected function ensureAvailable(): void
    {
        if (! $this->metadata->available()) {
            throw new ExceptionWithMessage(__('library.generation.unavailable'), 503);
        }
    }

    protected function answer(Request $request, Asset $work, AssetGeneration $generation, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => AssetResource::make($work->load(['files', 'folder:id,ulid']))->resolve($request),
            'generation' => AssetGenerationResource::make($generation)->resolve($request),
            'meta' => ['remaining' => $this->metadata->remaining($request->user())],
        ], $status);
    }
}
