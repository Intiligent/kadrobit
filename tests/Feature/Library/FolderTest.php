<?php

namespace Tests\Feature\Library;

use App\Enums\AssetStatus;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Folder;
use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class FolderTest extends TestCase
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

    protected function folder(string $name, ?Folder $parent = null, ?User $user = null, int $position = 0): Folder
    {
        return ($user ?? $this->user)->folders()->create(['name' => $name, 'parent_id' => $parent?->id, 'position' => $position]);
    }

    protected function asset(?Folder $folder = null, ?User $user = null): Asset
    {
        return ($user ?? $this->user)->assets()->create([
            'folder_id' => $folder?->id,
            'type' => MediaType::Photo,
            'status' => AssetStatus::Draft,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'checksum' => hash('sha256', Str::random()),
        ]);
    }

    /**
     * Names of the answered flat list, in tree order.
     */
    protected function names($response): array
    {
        return array_column($response->json('data'), 'name');
    }

    public function test_folder_is_created_last_among_its_siblings(): void
    {
        $travel = $this->folder('Travel', position: 0);
        $this->folder('Iceland', $travel, position: 0);

        $response = $this->postJson('/api/folders', ['name' => '  Norway   fjords ', 'parent_id' => $travel->ulid])
            ->assertCreated();

        $created = Folder::where('ulid', $response->json('meta.id'))->sole();
        $this->assertSame('Norway fjords', $created->name);
        $this->assertSame($travel->id, $created->parent_id);
        $this->assertSame(1, $created->position);
        $this->assertSame(['Iceland', 'Norway fjords'], $travel->children()->ordered()->pluck('name')->all());
        $this->assertContains(['id' => $created->ulid, 'parent_id' => $travel->ulid, 'name' => 'Norway fjords'], $response->json('data'));
    }

    public function test_top_level_folder_needs_no_parent(): void
    {
        $this->postJson('/api/folders', ['name' => 'Food'])->assertCreated();

        $this->assertNull($this->user->folders()->sole()->parent_id);
    }

    public function test_sibling_names_differ_ignoring_case(): void
    {
        $travel = $this->folder('Travel');
        $this->folder('Iceland', $travel);

        $this->postJson('/api/folders', ['name' => 'travel'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/folders', ['name' => 'ICELAND', 'parent_id' => $travel->ulid])->assertUnprocessable()->assertJsonValidationErrors('name');

        // The same name is fine elsewhere in the tree and in another user's library.
        $this->postJson('/api/folders', ['name' => 'Iceland'])->assertCreated();
        $this->folder('Travel', user: User::factory()->create());
    }

    public function test_names_compare_like_the_database_index_beyond_ascii(): void
    {
        $this->folder('Путешествия');

        $this->postJson('/api/folders', ['name' => 'путешествия'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_database_rejects_duplicate_top_level_names(): void
    {
        $this->folder('Travel');

        $this->expectException(QueryException::class);
        $this->folder('TRAVEL');
    }

    public function test_name_is_required_and_limited(): void
    {
        $this->postJson('/api/folders', ['name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/folders', ['name' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_parent_must_be_an_own_folder(): void
    {
        $foreign = $this->folder('Not mine', user: User::factory()->create());

        $this->postJson('/api/folders', ['name' => 'Inside', 'parent_id' => $foreign->ulid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_folder_is_renamed(): void
    {
        $food = $this->folder('Food');
        $this->folder('Travel');

        $this->patchJson("/api/folders/{$food->ulid}", ['name' => 'Food & drink'])->assertOk();
        $this->assertSame('Food & drink', $food->fresh()->name);

        // Its own name in another case is not a clash.
        $this->patchJson("/api/folders/{$food->ulid}", ['name' => 'FOOD & DRINK'])->assertOk();
        $this->patchJson("/api/folders/{$food->ulid}", ['name' => 'travel'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_folder_moves_into_another_folder_and_back_to_the_top(): void
    {
        $travel = $this->folder('Travel', position: 0);
        $iceland = $this->folder('Iceland', position: 1);
        $this->asset($iceland);

        $response = $this->patchJson("/api/folders/{$iceland->ulid}", ['parent_id' => $travel->ulid])->assertOk();

        $this->assertSame($travel->id, $iceland->fresh()->parent_id);
        $this->assertContains(['id' => $iceland->ulid, 'parent_id' => $travel->ulid, 'name' => 'Iceland'], $response->json('data'));
        // Works go with their folder.
        $this->assertSame(1, $iceland->assets()->count());

        $this->patchJson("/api/folders/{$iceland->ulid}", ['parent_id' => null, 'position' => 0])->assertOk();
        $this->assertNull($iceland->fresh()->parent_id);
        $this->assertSame(['Iceland', 'Travel'], $this->user->folders()->ordered()->pluck('name')->all());
    }

    public function test_folder_cannot_go_into_itself_or_a_subfolder(): void
    {
        $travel = $this->folder('Travel');
        $iceland = $this->folder('Iceland', $travel);
        $glaciers = $this->folder('Glaciers', $iceland);

        $this->patchJson("/api/folders/{$travel->ulid}", ['parent_id' => $travel->ulid])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/folders/{$travel->ulid}", ['parent_id' => $glaciers->ulid])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertNull($travel->fresh()->parent_id);
    }

    public function test_moving_next_to_a_folder_with_the_same_name_is_refused(): void
    {
        $travel = $this->folder('Travel');
        $this->folder('Iceland', $travel);
        $iceland = $this->folder('Iceland');

        $this->patchJson("/api/folders/{$iceland->ulid}", ['parent_id' => $travel->ulid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_reordering_renumbers_the_siblings(): void
    {
        $a = $this->folder('A', position: 0);
        $this->folder('B', position: 1);
        $this->folder('C', position: 2);

        $response = $this->patchJson("/api/folders/{$a->ulid}", ['position' => 2])->assertOk();

        $this->assertSame(['B', 'C', 'A'], $this->names($response));
        $this->assertSame([0, 1, 2], $this->user->folders()->ordered()->pluck('position')->all());

        // Past the end means last.
        $this->patchJson("/api/folders/{$a->ulid}", ['position' => 0])->assertOk();
        $this->patchJson("/api/folders/{$a->ulid}", ['position' => 99])->assertOk();
        $this->assertSame(['B', 'C', 'A'], $this->user->folders()->ordered()->pluck('name')->all());
    }

    public function test_deleting_a_folder_removes_subfolders_and_leaves_works_unsorted(): void
    {
        $travel = $this->folder('Travel');
        $iceland = $this->folder('Iceland', $travel);
        $food = $this->folder('Food');
        $work = $this->asset($iceland);

        $response = $this->deleteJson("/api/folders/{$travel->ulid}")->assertOk();

        $this->assertSame(['Food'], $this->names($response));
        $this->assertModelMissing($iceland);
        $this->assertModelExists($food);
        $this->assertNull($work->fresh()->folder_id);
    }

    public function test_moving_into_a_folder_that_is_gone_is_refused(): void
    {
        $food = $this->folder('Food');
        $gone = $this->folder('Gone');
        $ulid = $gone->ulid;
        $gone->delete();

        $this->patchJson("/api/folders/{$food->ulid}", ['parent_id' => $ulid])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertNull($food->fresh()->parent_id);
    }

    public function test_another_users_folder_is_not_found(): void
    {
        $foreign = $this->folder('Not mine', user: User::factory()->create());

        $this->patchJson("/api/folders/{$foreign->ulid}", ['name' => 'Mine'])->assertNotFound();
        $this->deleteJson("/api/folders/{$foreign->ulid}")->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_folders_need_a_confirmed_email(): void
    {
        Passport::actingAs(User::factory()->unverified()->create());

        $this->postJson('/api/folders', ['name' => 'Food'])->assertForbidden();
    }

    public function test_works_move_into_a_folder_together(): void
    {
        $folder = $this->folder('Iceland');
        $first = $this->asset();
        $second = $this->asset();
        $foreign = $this->asset(user: User::factory()->create());

        $this->postJson('/api/assets/move', ['assets' => [$first->ulid, $second->ulid, $foreign->ulid], 'folder_id' => $folder->ulid])
            ->assertOk()
            ->assertJsonPath('data.moved', 2);

        $this->assertSame($folder->id, $first->fresh()->folder_id);
        $this->assertSame($folder->id, $second->fresh()->folder_id);
        $this->assertNull($foreign->fresh()->folder_id);

        $this->postJson('/api/assets/move', ['assets' => [$first->ulid], 'folder_id' => null])->assertOk();
        $this->assertNull($first->fresh()->folder_id);
    }

    public function test_works_move_only_into_own_folders(): void
    {
        $work = $this->asset();
        $foreign = $this->folder('Not mine', user: User::factory()->create());

        $this->postJson('/api/assets/move', ['assets' => [$work->ulid], 'folder_id' => $foreign->ulid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folder_id');
        $this->postJson('/api/assets/move', ['assets' => [$work->ulid]])->assertUnprocessable()->assertJsonValidationErrors('folder_id');
    }

    public function test_work_moves_through_its_update(): void
    {
        $folder = $this->folder('Iceland');
        $work = $this->asset();

        $this->patchJson("/api/assets/{$work->ulid}", ['folder_id' => $folder->ulid])
            ->assertOk()
            ->assertJsonPath('data.folder_id', $folder->ulid);

        $this->patchJson("/api/assets/{$work->ulid}", ['folder_id' => null])->assertOk()->assertJsonPath('data.folder_id', null);
    }
}
