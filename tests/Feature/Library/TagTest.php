<?php

namespace Tests\Feature\Library;

use App\Enums\AssetStatus;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function asset(?User $user = null): Asset
    {
        return ($user ?? $this->user)->assets()->create([
            'type' => MediaType::Photo,
            'status' => AssetStatus::Draft,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'checksum' => hash('sha256', Str::random()),
        ]);
    }

    protected function tag(string $name, ?User $user = null): Tag
    {
        return ($user ?? $this->user)->tags()->create(['name' => $name]);
    }

    protected function patchTags(Asset $asset, array $tags)
    {
        return $this->patchJson("/api/assets/{$asset->ulid}", ['tags' => $tags]);
    }

    public function test_work_gets_existing_and_new_tags(): void
    {
        $asset = $this->asset();
        $sea = $this->tag('sea');

        $this->patchTags($asset, [['id' => $sea->id], ['name' => 'Batumi']])
            ->assertOk()
            ->assertJsonPath('data.tags.0.name', 'Batumi')
            ->assertJsonPath('data.tags.1', ['id' => $sea->id, 'name' => 'sea']);

        $this->assertSame(['Batumi', 'sea'], $asset->tags()->orderBy('name')->pluck('name')->all());
        $this->assertSame(2, $this->user->tags()->count());
    }

    public function test_tags_left_out_are_taken_off_the_work_but_stay_in_the_list(): void
    {
        $asset = $this->asset();
        $sea = $this->tag('sea');
        $asset->tags()->attach([$sea->id, $this->tag('Batumi')->id]);

        $this->patchTags($asset, [['id' => $sea->id]])->assertOk()->assertJsonCount(1, 'data.tags');

        $this->assertSame(['sea'], $asset->tags()->pluck('name')->all());
        $this->assertSame(2, $this->user->tags()->count());

        $this->patchTags($asset, [])->assertOk()->assertJsonCount(0, 'data.tags');
    }

    public function test_tag_names_are_unique_ignoring_case_and_trimmed(): void
    {
        $asset = $this->asset();
        $batumi = $this->tag('Batumi');

        $this->patchTags($asset, [['name' => 'batumi'], ['name' => '  BATUMI '], ['name' => ' sea ']])
            ->assertOk()
            ->assertJsonPath('data.tags', [['id' => $batumi->id, 'name' => 'Batumi'], ['id' => Tag::where('name', 'sea')->value('id'), 'name' => 'sea']]);

        $this->assertSame(2, $this->user->tags()->count());
    }

    public function test_same_name_is_a_separate_tag_of_another_user(): void
    {
        $other = User::factory()->create();
        $this->tag('Batumi', $other);

        $this->patchTags($this->asset(), [['name' => 'Batumi']])->assertOk();

        $this->assertSame(1, $this->user->tags()->count());
        $this->assertSame(1, $other->tags()->count());
    }

    public function test_tag_name_is_checked(): void
    {
        $asset = $this->asset();

        $this->patchTags($asset, [['name' => 'sea<script>']])->assertJsonValidationErrors('tags.0.name');
        $this->patchTags($asset, [['name' => str_repeat('a', Tag::NAME_MAX + 1)]])->assertJsonValidationErrors('tags.0.name');
        $this->patchTags($asset, [['name' => '   ']])->assertJsonValidationErrors('tags.0.name');

        // Any language, digits, spaces, hyphens and underscores are fine.
        $this->patchTags($asset, [['name' => 'Батуми 2026'], ['name' => 'old-town_night'], ['name' => 'ბათუმი']])->assertOk();

        $this->assertSame(3, $this->user->tags()->count());
    }

    public function test_another_users_tag_is_not_attached(): void
    {
        $asset = $this->asset();
        $foreign = $this->tag('secret', User::factory()->create());

        $this->patchTags($asset, [['id' => $foreign->id]])->assertJsonValidationErrors('tags.0.id');

        $this->assertSame(0, $asset->tags()->count());
    }

    public function test_tags_are_saved_together_with_other_fields(): void
    {
        $asset = $this->asset();

        $this->patchJson("/api/assets/{$asset->ulid}", ['title' => 'Pier at twilight', 'tags' => [['name' => 'sea']]])
            ->assertOk()
            ->assertJsonPath('data.title', 'Pier at twilight')
            ->assertJsonPath('data.tags.0.name', 'sea');

        // Tags are not metadata for marketplaces: the edited title is marked, the tags are not a field there.
        $this->assertSame(['title' => 'user'], $asset->refresh()->metadata_sources);
    }

    public function test_tags_are_added_to_several_works_keeping_theirs(): void
    {
        $first = $this->asset();
        $second = $this->asset();
        $foreignWork = $this->asset(User::factory()->create());
        $sea = $this->tag('sea');
        $night = $this->tag('night');
        $first->tags()->attach($night->id);
        $second->tags()->attach($sea->id);

        $this->postJson('/api/assets/tags', [
            'assets' => [$first->ulid, $second->ulid, $foreignWork->ulid],
            'tags' => [['id' => $sea->id], ['name' => 'Batumi']],
        ])
            ->assertOk()
            ->assertJsonPath('data.tagged', 2)
            ->assertJsonPath('data.tags.0.name', 'Batumi')
            ->assertJsonPath('data.tags.1', ['id' => $sea->id, 'name' => 'sea']);

        $this->assertSame(['Batumi', 'night', 'sea'], $first->tags()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Batumi', 'sea'], $second->tags()->orderBy('name')->pluck('name')->all());
        $this->assertSame(0, $foreignWork->tags()->count());
    }

    public function test_bulk_tagging_checks_the_tags(): void
    {
        $asset = $this->asset();
        $foreign = $this->tag('secret', User::factory()->create());

        $this->postJson('/api/assets/tags', ['assets' => [$asset->ulid], 'tags' => []])->assertJsonValidationErrors('tags');
        $this->postJson('/api/assets/tags', ['assets' => [$asset->ulid], 'tags' => [['id' => $foreign->id]]])
            ->assertJsonValidationErrors('tags.0.id');
        $this->postJson('/api/assets/tags', ['assets' => [$asset->ulid], 'tags' => [['name' => 'a/b']]])
            ->assertJsonValidationErrors('tags.0.name');

        $this->assertSame(0, $asset->tags()->count());
    }

    public function test_library_page_lists_the_users_tags_alphabetically(): void
    {
        $asset = $this->asset();
        $sea = $this->tag('sea');
        $batumi = $this->tag('Batumi');
        $this->tag('mine', User::factory()->create());
        $asset->tags()->attach($sea->id);

        $this->actingAs($this->user, 'web')
            ->get('/library')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tags', [['id' => $batumi->id, 'name' => 'Batumi'], ['id' => $sea->id, 'name' => 'sea']])
                ->where('assets.0.tags', [['id' => $sea->id, 'name' => 'sea']]));
    }
}
