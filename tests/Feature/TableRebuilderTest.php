<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Database\TableRebuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TableRebuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function define(bool $withCode): \Closure
    {
        return function (Blueprint $table) use ($withCode) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            if ($withCode) {
                $table->string('code', 16)->nullable();
            }

            $table->enum('status', ['new', 'done'])->default('new');
            $table->string('name');
            $table->timestampsTz();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'status']);
        };
    }

    /** @return list<string> */
    protected function objectNames(string $table): array
    {
        $names = [
            ...array_column(DB::select('SELECT conname AS name FROM pg_constraint WHERE conrelid = ?::regclass', [$table]), 'name'),
            ...array_column(DB::select('SELECT indexname AS name FROM pg_indexes WHERE tablename = ?', [$table]), 'name'),
            DB::scalar('SELECT pg_get_serial_sequence(?, ?)', [$table, 'id']),
        ];
        sort($names);

        return array_values(array_unique($names));
    }

    public function test_a_column_goes_into_its_place_and_the_rows_and_names_stay(): void
    {
        $user = User::factory()->create();
        Schema::create('rebuild_samples', $this->define(false));
        // The names a table created with the new definition has, to compare with.
        Schema::create('rebuild_expected', $this->define(true));
        $expected = str_replace('rebuild_expected', 'rebuild_samples', $this->objectNames('rebuild_expected'));

        foreach (['first', 'second', 'third'] as $name) {
            DB::table('rebuild_samples')->insert(['user_id' => $user->id, 'name' => $name, 'status' => 'done']);
        }
        // The last id is gone, but the sequence has passed it: it is never given again.
        DB::table('rebuild_samples')->where('name', 'third')->delete();

        app(TableRebuilder::class)->rebuild('rebuild_samples', $this->define(true));

        $this->assertSame(['id', 'user_id', 'code', 'status', 'name', 'created_at', 'updated_at'], Schema::getColumnListing('rebuild_samples'));
        $this->assertSame(
            [[1, 'first', 'done', null], [2, 'second', 'done', null]],
            DB::table('rebuild_samples')->orderBy('id')->get()->map(fn ($row) => [$row->id, $row->name, $row->status, $row->code])->all(),
        );
        $this->assertSame(4, DB::table('rebuild_samples')->insertGetId(['user_id' => $user->id, 'name' => 'fourth']));
        $this->assertSame($expected, $this->objectNames('rebuild_samples'));
        $this->assertFalse(Schema::hasTable('rebuild_samples__new'));
        $this->assertFalse(Schema::hasTable('rebuild_samples__old'));

        // Constraints still hold on the rebuilt table.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('rebuild_samples')->insert(['user_id' => $user->id, 'name' => 'first']);
    }

    public function test_a_table_other_tables_reference_is_not_rebuilt(): void
    {
        Schema::create('rebuild_samples', $this->define(false));
        Schema::create('rebuild_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rebuild_sample_id')->constrained();
        });

        $this->expectException(RuntimeException::class);

        try {
            app(TableRebuilder::class)->rebuild('rebuild_samples', $this->define(true));
        } finally {
            $this->assertFalse(Schema::hasColumn('rebuild_samples', 'code'));
        }
    }

    public function test_asset_listings_have_the_external_id_after_the_account(): void
    {
        $columns = Schema::getColumnListing('asset_listings');

        $this->assertSame('external_id', $columns[array_search('marketplace_account_id', $columns, true) + 1]);
    }
}
