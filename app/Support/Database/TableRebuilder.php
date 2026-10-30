<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Puts a new column where it belongs in a PostgreSQL table that already holds data: PostgreSQL ignores ->after() and
 * always appends, so the table is built again (docs/development.md, "Колонка в середине таблицы").
 *
 * In one transaction, with the table locked against reads and writes:
 * 1. a copy is created from the full new definition (Blueprint), under a temporary name;
 * 2. the rows are copied, every column the two tables share, and the id sequence goes on from where it was;
 * 3. the old table is renamed, the copy takes its name, the old table is dropped;
 * 4. the copy's constraints, indexes and sequences take the names they would have had on the real table.
 *
 * A table that other tables reference by foreign key is refused: those keys would have to move to the copy.
 */
class TableRebuilder
{
    /**
     * @param  Closure(Blueprint): void  $definition  The table's whole structure as it should be, as in Schema::create()
     */
    public function rebuild(string $table, Closure $definition): void
    {
        $copy = "{$table}__new";
        $old = "{$table}__old";

        DB::transaction(function () use ($table, $definition, $copy, $old) {
            $this->refuseReferenced($table);

            // Writes made during the copy would be lost: nobody reads or writes the table until the end.
            DB::statement("LOCK TABLE {$this->quote($table)} IN ACCESS EXCLUSIVE MODE");

            Schema::create($copy, $definition);

            $columns = array_values(array_intersect(Schema::getColumnListing($copy), Schema::getColumnListing($table)));
            $list = implode(', ', array_map($this->quote(...), $columns));
            DB::statement("INSERT INTO {$this->quote($copy)} ({$list}) SELECT {$list} FROM {$this->quote($table)}");

            foreach ($columns as $column) {
                $this->continueSequence($table, $copy, $column);
            }

            DB::statement("ALTER TABLE {$this->quote($table)} RENAME TO {$this->quote($old)}");
            DB::statement("ALTER TABLE {$this->quote($copy)} RENAME TO {$this->quote($table)}");
            DB::statement("DROP TABLE {$this->quote($old)}");

            $this->renameObjects($table, "{$copy}_", "{$table}_");
        });
    }

    protected function refuseReferenced(string $table): void
    {
        $references = DB::scalar('SELECT count(*) FROM pg_constraint WHERE contype = ? AND confrelid = ?::regclass AND conrelid <> confrelid', ['f', $table]);

        if ($references > 0) {
            throw new RuntimeException("Table {$table} is referenced by foreign keys of other tables: they would have to be moved to the rebuilt table.");
        }
    }

    /** The copy's sequence of the column (a serial id) takes the old one's position, so new ids never repeat. */
    protected function continueSequence(string $table, string $copy, string $column): void
    {
        $from = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', [$table, $column]);
        $to = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', [$copy, $column]);

        if ($from && $to) {
            $state = DB::selectOne("SELECT last_value, is_called FROM {$from}");
            DB::select('SELECT setval(?, ?, ?)', [$to, $state->last_value, $state->is_called]);
        }
    }

    /**
     * Constraints (primary and unique keys rename their indexes along), other indexes and sequences of the table
     * whose names start with $from.
     */
    protected function renameObjects(string $table, string $from, string $to): void
    {
        $rename = fn (string $name) => $to.substr($name, strlen($from));
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $from).'%';

        $constraints = DB::select('SELECT conname AS name FROM pg_constraint WHERE conrelid = ?::regclass AND conname LIKE ?', [$table, $prefix]);

        foreach ($constraints as $constraint) {
            DB::statement("ALTER TABLE {$this->quote($table)} RENAME CONSTRAINT {$this->quote($constraint->name)} TO {$this->quote($rename($constraint->name))}");
        }

        // Indexes left after the constraints: the plain ones (->index()).
        $indexes = DB::select('SELECT indexname AS name FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname LIKE ?', [$table, $prefix]);

        foreach ($indexes as $index) {
            DB::statement("ALTER INDEX {$this->quote($index->name)} RENAME TO {$this->quote($rename($index->name))}");
        }

        $sequences = DB::select('SELECT sequencename AS name FROM pg_sequences WHERE schemaname = current_schema() AND sequencename LIKE ?', [$prefix]);

        foreach ($sequences as $sequence) {
            DB::statement("ALTER SEQUENCE {$this->quote($sequence->name)} RENAME TO {$this->quote($rename($sequence->name))}");
        }
    }

    protected function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
