<?php

namespace Tests\Feature\Metadata;

use App\Enums\AssetFileRole;
use App\Enums\GenerationTrigger;
use App\Enums\MarketplaceAccountStatus;
use App\Events\EntityChanged;
use App\Jobs\GenerateAssetMetadata;
use App\Models\Asset;
use App\Models\AssetGeneration;
use App\Models\Marketplace;
use App\Models\MarketplaceCategory;
use App\Models\User;
use App\Services\Metadata\AssetMetadataService;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\TestCase;

class AssetGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        Storage::fake(AssetFileRole::Preview->disk());
        config([
            'metadata.driver' => 'openai',
            'metadata.drivers.openai.key' => 'sk-test',
            'metadata.drivers.openai.model' => 'gpt-6-luna',
            'metadata.drivers.openai.retry_delay_ms' => 0,
        ]);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function fakeModel(array $answer = []): void
    {
        Http::fake(['api.openai.com/*' => Http::response(OpenAiMetadataGeneratorTest::response($answer))]);
    }

    protected function asset(array $attributes = [], ?User $user = null): Asset
    {
        $asset = ($user ?? $this->user)->assets()->create([
            'type' => 'photo',
            'original_filename' => 'IMG_1.jpg',
            'checksum' => hash('sha256', uniqid('', true)),
            ...$attributes,
        ]);

        $image = new Imagick;
        $image->newImage(40, 30, new ImagickPixel('orange'));
        $image->setImageFormat('jpeg');
        $path = "assets/{$asset->ulid}/preview.jpg";
        Storage::disk(AssetFileRole::Preview->disk())->put($path, $image->getImageBlob());

        $asset->files()->create([
            'role' => AssetFileRole::Preview,
            'disk' => AssetFileRole::Preview->disk(),
            'path' => $path,
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
        ]);

        return $asset;
    }

    public function test_filling_writes_only_empty_fields_the_user_did_not_edit(): void
    {
        $this->fakeModel();
        $asset = $this->asset([
            'title' => 'Sunset from my camera',
            'description' => '',
            'metadata_sources' => ['title' => 'file', 'description' => 'user'],
        ]);

        GenerateAssetMetadata::dispatchSync($asset);

        $asset->refresh();
        $this->assertSame('Sunset from my camera', $asset->title);
        // Cleared by the user on purpose: not filled again.
        $this->assertSame('', (string) $asset->description);
        $this->assertSame(['sunset', 'ocean'], $asset->keywords);
        // jsonb keeps its own key order.
        $this->assertEquals(['title' => 'file', 'description' => 'user', 'keywords' => 'ai'], $asset->metadata_sources);

        $generation = AssetGeneration::sole();
        $this->assertSame($asset->id, $generation->asset_id);
        $this->assertSame(GenerationTrigger::Upload, $generation->trigger);
        $this->assertSame('openai:gpt-6-luna', $generation->model);
        $this->assertSame('Golden sunset over the ocean', $generation->result['title']);
        $this->assertSame(1800, $generation->input_tokens);
        $this->assertEqualsWithDelta(0.00029, (float) $generation->cost_usd, 1e-9);
    }

    public function test_filling_skips_a_work_with_nothing_empty(): void
    {
        Http::fake();
        $asset = $this->asset(['title' => 'A', 'description' => 'B', 'keywords' => ['c'], 'categories' => ['adobe_stock' => ['11']]]);

        GenerateAssetMetadata::dispatchSync($asset);

        Http::assertNothingSent();
        $this->assertSame(0, AssetGeneration::count());
    }

    public function test_filling_skips_a_work_where_it_could_write_nothing(): void
    {
        Http::fake();
        // The cleared description is the user's; no marketplace is turned on, so no categories can be chosen.
        $asset = $this->asset([
            'title' => 'A', 'keywords' => ['c'], 'description' => '',
            'metadata_sources' => ['title' => 'file', 'keywords' => 'file', 'description' => 'user'],
        ]);

        GenerateAssetMetadata::dispatchSync($asset);

        Http::assertNothingSent();
    }

    public function test_a_second_job_for_the_same_work_waits_and_is_limited_by_time_not_tries(): void
    {
        $job = new GenerateAssetMetadata($this->asset());

        $this->assertSame(30, $job->middleware()[0]->releaseAfter);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertTrue($job->retryUntil()->isAfter(now()->addHours(2)));
    }

    public function test_without_a_provider_key_generation_on_request_is_unavailable(): void
    {
        Http::fake();
        Queue::fake();
        config(['metadata.drivers.openai.key' => null]);
        $asset = $this->asset();

        $this->postJson("/api/assets/{$asset->ulid}/generations")->assertStatus(503)->assertJsonPath('message', __('library.generation.unavailable'));
        $this->postJson('/api/assets/generations', ['assets' => [$asset->ulid]])->assertStatus(503);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_generation_the_user_waits_for_makes_one_try(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence([
            Http::response(['error' => ['message' => 'Overloaded']], 503),
            Http::response(OpenAiMetadataGeneratorTest::response()),
        ])]);
        $asset = $this->asset();

        $this->postJson("/api/assets/{$asset->ulid}/generations")->assertStatus(502);

        Http::assertSentCount(1);
    }

    public function test_restored_categories_keep_only_connected_marketplaces_and_listed_categories(): void
    {
        $this->seed(MarketplaceSeeder::class);
        $adobe = Marketplace::where('code', 'adobe_stock')->sole();
        MarketplaceCategory::create(['marketplace_id' => $adobe->id, 'external_id' => '11', 'name' => 'Landscapes', 'media_types' => ['photo']]);
        $this->user->marketplaceAccounts()->create(['marketplace_id' => $adobe->id, 'label' => 'Adobe', 'status' => MarketplaceAccountStatus::Active]);
        $asset = $this->asset();
        $generation = $asset->generations()->make([
            'user_id' => $this->user->id, 'trigger' => 'manual', 'model' => 'm',
            // Shutterstock is not connected any more; "99" is no longer on the Adobe list.
            'result' => ['categories' => ['adobe_stock' => ['99', '11'], 'shutterstock' => ['Nature']]],
        ]);
        $generation->save();

        $this->postJson("/api/assets/{$asset->ulid}/generations/{$generation->id}/apply", ['fields' => ['categories']])
            ->assertOk()
            ->assertJsonPath('data.categories', ['adobe_stock' => ['11']]);
    }

    public function test_the_monthly_limit_stops_generation(): void
    {
        Http::fake();
        config(['metadata.monthly_limit' => 1]);
        $asset = $this->asset();
        AssetGeneration::forceCreate(['user_id' => $this->user->id, 'trigger' => 'manual', 'model' => 'm', 'result' => []]);

        GenerateAssetMetadata::dispatchSync($asset);
        $this->postJson("/api/assets/{$asset->ulid}/generations")
            ->assertStatus(429)
            ->assertJsonPath('message', 'You have used all 1 metadata generations for this month.');

        Http::assertNothingSent();
        $this->assertSame(1, AssetGeneration::count());
    }

    public function test_last_months_generations_do_not_count(): void
    {
        $this->fakeModel();
        config(['metadata.monthly_limit' => 1]);
        $asset = $this->asset();
        AssetGeneration::forceCreate(['user_id' => $this->user->id, 'trigger' => 'manual', 'model' => 'm', 'result' => [], 'created_at' => now()->subMonthNoOverflow()]);

        $this->postJson("/api/assets/{$asset->ulid}/generations")->assertCreated()->assertJsonPath('meta.remaining', 0);
    }

    public function test_generating_on_request_replaces_the_fields_even_when_edited(): void
    {
        $this->fakeModel();
        $asset = $this->asset(['title' => 'My title', 'description' => 'My text', 'metadata_sources' => ['title' => 'user', 'description' => 'user']]);

        $this->postJson("/api/assets/{$asset->ulid}/generations", ['fields' => ['title']])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Golden sunset over the ocean')
            ->assertJsonPath('data.description', 'My text')
            ->assertJsonPath('data.metadata_sources.title', 'ai')
            ->assertJsonPath('data.metadata_sources.description', 'user')
            ->assertJsonPath('generation.keywords', ['sunset', 'ocean'])
            ->assertJsonPath('meta.remaining', 999)
            ->assertJsonMissingPath('generation.cost_usd');

        $this->assertSame(GenerationTrigger::Manual, AssetGeneration::sole()->trigger);
    }

    public function test_an_empty_generated_value_never_clears_a_field(): void
    {
        $this->fakeModel(['description' => '']);
        $asset = $this->asset(['description' => 'Keep me']);

        $this->postJson("/api/assets/{$asset->ulid}/generations")->assertCreated()->assertJsonPath('data.description', 'Keep me');
    }

    public function test_a_failed_generation_answers_with_a_message_and_records_nothing(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid model']], 400)]);
        $asset = $this->asset();

        $this->postJson("/api/assets/{$asset->ulid}/generations")
            ->assertStatus(502)
            ->assertJsonPath('message', __('library.generation.failed'));

        $this->assertSame(0, AssetGeneration::count());
    }

    public function test_a_generation_is_restored_from_the_history_without_a_new_call(): void
    {
        $this->fakeModel();
        $asset = $this->asset();
        $id = $this->postJson("/api/assets/{$asset->ulid}/generations")->json('generation.id');

        $this->patchJson("/api/assets/{$asset->ulid}", ['title' => 'Broken'])->assertOk()->assertJsonPath('data.metadata_sources.title', 'user');

        $this->postJson("/api/assets/{$asset->ulid}/generations/{$id}/apply", ['fields' => ['title']])
            ->assertOk()
            ->assertJsonPath('data.title', 'Golden sunset over the ocean')
            ->assertJsonPath('data.metadata_sources.title', 'ai');

        Http::assertSentCount(1);
        $this->assertSame(1, AssetGeneration::count());
    }

    public function test_history_lists_the_newest_first_and_hides_other_works(): void
    {
        $this->fakeModel();
        $asset = $this->asset();
        $other = $this->asset();
        $this->postJson("/api/assets/{$asset->ulid}/generations");
        $second = $this->postJson("/api/assets/{$asset->ulid}/generations")->json('generation.id');
        $foreign = $this->postJson("/api/assets/{$other->ulid}/generations")->json('generation.id');

        $this->getJson("/api/assets/{$asset->ulid}/generations")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('meta.remaining', 997);

        // A generation of another work cannot be applied to this one.
        $this->postJson("/api/assets/{$asset->ulid}/generations/{$foreign}/apply")->assertNotFound();
    }

    public function test_another_users_work_is_not_found(): void
    {
        Http::fake();
        $asset = $this->asset(user: User::factory()->create());

        $this->getJson("/api/assets/{$asset->ulid}/generations")->assertNotFound();
        $this->postJson("/api/assets/{$asset->ulid}/generations")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_several_works_are_queued_for_filling(): void
    {
        Queue::fake();
        $mine = [$this->asset(), $this->asset()];
        $foreign = $this->asset(user: User::factory()->create());

        $this->postJson('/api/assets/generations', ['assets' => [$mine[0]->ulid, $mine[1]->ulid, $foreign->ulid]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', 2);

        Queue::assertPushed(GenerateAssetMetadata::class, 2);
        Queue::assertPushed(GenerateAssetMetadata::class, fn ($job) => $job->trigger === GenerationTrigger::Manual);

        config(['metadata.monthly_limit' => 1]);
        $this->postJson('/api/assets/generations', ['assets' => [$mine[0]->ulid, $mine[1]->ulid]])->assertStatus(429);
    }

    public function test_categories_fill_marketplaces_without_any(): void
    {
        $this->seed(MarketplaceSeeder::class);
        $adobe = Marketplace::where('code', 'adobe_stock')->sole();
        MarketplaceCategory::create(['marketplace_id' => $adobe->id, 'external_id' => '11', 'name' => 'Landscapes', 'media_types' => ['photo']]);
        $this->user->marketplaceAccounts()->create(['marketplace_id' => $adobe->id, 'label' => 'Adobe', 'status' => MarketplaceAccountStatus::Active]);
        $this->fakeModel();
        $asset = $this->asset(['categories' => ['shutterstock' => ['Nature']]]);

        GenerateAssetMetadata::dispatchSync($asset);

        $this->assertEquals(['shutterstock' => ['Nature'], 'adobe_stock' => ['11']], $asset->refresh()->categories);
        Http::assertSent(fn ($request) => str_contains($request['instructions'], "adobe_stock (at most 1):\n11: Landscapes"));
    }

    public function test_editing_a_field_makes_it_the_users(): void
    {
        $asset = $this->asset(['metadata_sources' => ['title' => 'ai', 'keywords' => 'ai']]);

        $this->patchJson("/api/assets/{$asset->ulid}", ['title' => 'Mine', 'is_editorial' => true])
            ->assertOk()
            ->assertJsonPath('data.metadata_sources.title', 'user')
            ->assertJsonPath('data.metadata_sources.keywords', 'ai');
    }

    public function test_status_and_flags_alone_leave_the_sources_alone(): void
    {
        $asset = $this->asset(['metadata_sources' => ['title' => 'ai']]);

        $this->patchJson("/api/assets/{$asset->ulid}", ['status' => 'archived', 'is_editorial' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.metadata_sources', ['title' => 'ai']);
    }

    public function test_a_queued_work_is_marked_until_the_job_finishes_and_the_user_is_told(): void
    {
        Event::fake([EntityChanged::class]);
        Queue::fake();
        $this->fakeModel();
        $asset = $this->asset();

        app(AssetMetadataService::class)->queue($asset, GenerationTrigger::Manual);

        $this->assertNotNull($asset->refresh()->metadata_generating_at);
        $job = Queue::pushed(GenerateAssetMetadata::class)->sole();
        app()->call([$job, 'handle']);

        $this->assertNull($asset->refresh()->metadata_generating_at);
        $this->assertSame(['sunset', 'ocean'], $asset->keywords);
        Event::assertDispatched(EntityChanged::class, fn ($event) => $event->type === 'asset' && $event->id === $asset->ulid && $event->reason === 'metadata');
    }

    public function test_a_job_leaves_the_mark_of_a_later_request_for_its_own_job(): void
    {
        Queue::fake();
        Http::fake();
        $asset = $this->asset(['title' => 'A', 'description' => 'B', 'keywords' => ['c']]);
        $service = app(AssetMetadataService::class);

        $service->queue($asset, GenerationTrigger::Upload);
        $first = Queue::pushed(GenerateAssetMetadata::class)->sole();
        $this->travel(5)->seconds();
        $service->queue($asset, GenerationTrigger::Manual);

        app()->call([$first, 'handle']);

        // The second request is still queued: the work stays "generating".
        $this->assertNotNull($asset->refresh()->metadata_generating_at);
    }

    public function test_a_work_in_the_trash_is_not_generated(): void
    {
        Event::fake([EntityChanged::class]);
        Http::fake();
        $asset = $this->asset();
        $asset->forceFill(['metadata_generating_at' => now()])->save();
        $asset->delete();

        GenerateAssetMetadata::dispatchSync(Asset::withTrashed()->find($asset->id));

        Http::assertNothingSent();
        $this->assertSame(0, AssetGeneration::count());
        $this->assertNull(Asset::withTrashed()->find($asset->id)->metadata_generating_at);
    }

    public function test_several_works_are_marked_at_once(): void
    {
        Queue::fake();
        $works = [$this->asset(), $this->asset()];

        $this->postJson('/api/assets/generations', ['assets' => [$works[0]->ulid, $works[1]->ulid]])->assertAccepted();

        $this->assertSame(2, Asset::query()->whereNotNull('metadata_generating_at')->count());
    }

    public function test_the_mark_shows_as_pending_and_a_stale_one_does_not(): void
    {
        Queue::fake();
        $asset = $this->asset();

        app(AssetMetadataService::class)->queue($asset, GenerationTrigger::Manual);

        $this->getJson("/api/assets?ids[]={$asset->ulid}")->assertJsonPath('data.0.metadata_pending', true);

        $asset->forceFill(['metadata_generating_at' => now()->subMinutes(Asset::GENERATING_STALE_MINUTES + 1)])->save();
        $this->getJson("/api/assets?ids[]={$asset->ulid}")->assertJsonPath('data.0.metadata_pending', false);
    }

    public function test_the_mark_is_cleared_when_there_is_nothing_to_write_or_the_limit_is_reached(): void
    {
        Event::fake([EntityChanged::class]);
        Http::fake();
        config(['metadata.monthly_limit' => 0]);
        $asset = $this->asset();
        $asset->forceFill(['metadata_generating_at' => now()])->save();

        GenerateAssetMetadata::dispatchSync($asset, GenerationTrigger::Upload, now());

        $this->assertNull($asset->refresh()->metadata_generating_at);
        Event::assertDispatched(EntityChanged::class);
    }

    public function test_a_job_that_gives_up_clears_the_mark(): void
    {
        Event::fake([EntityChanged::class]);
        $asset = $this->asset();
        $asset->forceFill(['metadata_generating_at' => now()])->save();

        (new GenerateAssetMetadata($asset, GenerationTrigger::Upload, now()))->failed(new RuntimeException('Invalid model'));

        $this->assertNull($asset->refresh()->metadata_generating_at);
        Event::assertDispatched(EntityChanged::class);
    }

    public function test_fresh_versions_of_own_works_only(): void
    {
        $mine = $this->asset(['title' => 'Mine']);
        $foreign = $this->asset(user: User::factory()->create());

        $this->getJson("/api/assets?ids[]={$mine->ulid}&ids[]={$foreign->ulid}&ids[]=missing")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');

        $this->getJson('/api/assets')->assertUnprocessable();
        $this->getJson('/api/assets?'.http_build_query(['ids' => array_fill(0, 101, 'x')]))->assertUnprocessable();
    }

    public function test_generation_needs_a_confirmed_email(): void
    {
        $this->user->forceFill(['email_verified_at' => null])->save();
        $asset = $this->asset();

        $this->postJson("/api/assets/{$asset->ulid}/generations")->assertForbidden();
    }
}
