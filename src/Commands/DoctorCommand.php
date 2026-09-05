<?php

namespace Shwaeki\DynamicTable\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\TableEstimator;
use Shwaeki\DynamicTable\Support\TableRegistry;
use Shwaeki\DynamicTable\Support\Theme;
use Throwable;

/**
 * What will hurt in production, said before it does.
 *
 * The performance and configuration documentation describes a set of things a
 * table should not do — sort by an unindexed column, offer views without the
 * migration, count a table too large to count. Documentation is a thing people
 * read once. This is the same set of statements, checked.
 *
 * It reports; it never changes anything. Every finding names the table, says
 * what is wrong in one line, and says what to do about it — a report that only
 * says "slow" is a report nobody acts on.
 */
class DoctorCommand extends Command
{
    protected $signature = 'dynamic-table:doctor {table? : One table key, instead of all of them}';

    protected $description = 'Check every registered table for the things that hurt in production';

    /** @var list<array{level: string, table: string, message: string, fix: string}> */
    protected array $findings = [];

    public function handle(TableRegistry $registry): int
    {
        $only = $this->argument('table');
        $keys = $only !== null ? [$only] : array_keys($registry->all());

        if ($keys === []) {
            $this->components->warn('No tables are registered. Check dynamic-table.tables.paths.');

            return self::SUCCESS;
        }

        $this->checkThemes();

        foreach ($keys as $key) {
            try {
                $table = $registry->resolve((string) $key);
            } catch (Throwable $exception) {
                $this->add('error', (string) $key, $exception->getMessage(), 'Fix the table class, or remove it from the registry.');

                continue;
            }

            $this->inspect($table);
        }

        return $this->report();
    }

    protected function inspect(DynamicTable $table): void
    {
        $key = $table->key();

        try {
            $meta = $table->metadata();
        } catch (Throwable $exception) {
            $this->add('error', $key, 'Metadata could not be read: '.$exception->getMessage(), 'Check $model and the database connection.');

            return;
        }

        $indexed = [];

        foreach ($meta->fields as $name => $field) {
            if ($field->indexed) {
                $indexed[$name] = true;
            }
        }

        // The default sort runs on every first paint of this table, so an
        // unindexed column here is the single most expensive mistake available.
        foreach (array_keys($table->defaultSort()) as $field) {
            $column = $table->columnFor(str_replace('.', '__', (string) $field));
            $name = (string) ($column?->field->column ?? $column?->field->name ?? '');

            if ($column === null || $column->isRelational() || $column->field->isAggregate() || $name === '') {
                continue;
            }

            if (! isset($indexed[$name])) {
                $this->add(
                    'warning',
                    $key,
                    "The default sort is on [{$name}], which has no index.",
                    "Add an index on {$meta->table}.{$name}, or sort by a column that has one.",
                );
            }
        }

        // Search is a LIKE across these on every keystroke that gets past the
        // debounce. An index does not help a leading wildcard, but the absence
        // of one on a large table is still worth knowing about.
        $unindexed = [];

        foreach ($table->searchablePaths() as $path) {
            $column = $table->columnFor(str_replace('.', '__', $path));
            $name = (string) ($column?->field->column ?? $column?->field->name ?? '');

            if ($column !== null && ! $column->isRelational() && $name !== '' && ! isset($indexed[$name])) {
                $unindexed[] = $name;
            }
        }

        /*
         * Only where it costs something.
         *
         * Nearly every table searches a name column, and nearly no name column
         * is indexed — reporting that on a table of two hundred rows would put
         * a line against almost every table in the application and teach
         * people to skim past all of them. Above ten thousand rows the scan is
         * a real cost and the line is a real finding.
         */
        if ($unindexed !== [] && $table->hasFeature(Feature::SEARCH) && $this->rows($table) > 10000) {
            $this->add(
                'note',
                $key,
                'Searchable columns with no index: '.implode(', ', $unindexed).'.',
                'Consider a fulltext index, or fewer $searchable columns.',
            );
        }

        $this->checkViews($table);
        $this->checkSize($table);
        $this->checkReorder($table, $indexed);
        $this->checkPins($table);
    }

    /** Saved views need a table, and a missing one fails at the moment a reader opens the picker. */
    protected function checkViews(DynamicTable $table): void
    {
        if (! $table->hasFeature(Feature::SAVED_VIEWS)) {
            return;
        }

        $views = (string) config('dynamic-table.views.table', 'dynamic_table_views');

        try {
            $exists = Schema::hasTable($views);
        } catch (Throwable) {
            return;   // No database to ask. Not this command's problem to report.
        }

        if (! $exists) {
            $this->add(
                'error',
                $table->key(),
                "saved_views is enabled but the [{$views}] table does not exist.",
                'Run php artisan migrate, or switch the feature off.',
            );
        }
    }

    /**
     * A table that counts what it cannot afford to count.
     *
     * The estimate is the same one the package already uses to decide whether
     * to count at all, so this asks the question the runtime asks — it just
     * says the answer out loud, once, instead of paying for it on every
     * request.
     */
    protected function checkSize(DynamicTable $table): void
    {
        if (! $table->countsRows()) {
            return;
        }

        $threshold = (int) config('dynamic-table.pagination.count_threshold', 250000);
        $rows = $this->rows($table);

        if ($threshold > 0 && $rows > $threshold) {
            $this->add(
                'warning',
                $table->key(),
                "About {$rows} rows, and every page still runs a COUNT(*).",
                "Set \$pagination to 'simple' or 'infinite', or leave it as 'auto' so the package decides.",
            );
        }
    }

    /**
     * Roughly how many rows this table's model holds.
     *
     * The estimator, not a COUNT(*): this command should not be the most
     * expensive thing anyone runs against their production database, and every
     * question it asks the number for is a question about orders of magnitude.
     * Nought when it cannot be known, which reads as "too small to worry
     * about" everywhere it is used.
     */
    protected function rows(DynamicTable $table): int
    {
        static $memo = [];

        return $memo[$table->key()] ??= (function () use ($table): int {
            try {
                return (int) (app(TableEstimator::class)->rows($table->newModel()) ?? 0);
            } catch (Throwable) {
                return 0;
            }
        })();
    }

    /** Reordering that cannot work is worse than reordering that is switched off. */
    protected function checkReorder(DynamicTable $table, array $indexed): void
    {
        if (! $table->hasFeature(Feature::ROW_REORDER)) {
            return;
        }

        $column = $table->reorderColumn();

        if ($column === null) {
            $this->add(
                'error',
                $table->key(),
                'row_reorder is enabled but $reorderable does not name a usable column.',
                'Point $reorderable at a real, non-computed column on the model.',
            );

            return;
        }

        // defaultSort() is a map of field => direction, so the leading sort is
        // its first key.
        $sort = array_key_first($table->defaultSort());

        if ($sort !== null) {
            $sort = str_replace('.', '__', (string) $sort);
        }

        if ($sort !== $column) {
            $this->add(
                'warning',
                $table->key(),
                "Rows can only be dragged while the table is sorted by [{$column}], and it opens sorted by [".($sort ?? 'nothing').'].',
                "Set \$defaultSort = ['{$column}' => 'asc'] so the handles are there on first paint.",
            );
        }

        $definition = $table->columnFor($column);
        $name = (string) ($definition?->field->column ?? $column);

        if (! isset($indexed[$name])) {
            $this->add('note', $table->key(), "The position column [{$name}] has no index.", 'Add one: every page of this table sorts by it.');
        }
    }

    /** Pins live in the session, so a table without one cannot keep them. */
    protected function checkPins(DynamicTable $table): void
    {
        if (! $table->hasFeature(Feature::PINNED_ROWS)) {
            return;
        }

        if (config('session.driver') === 'array') {
            $this->add(
                'warning',
                $table->key(),
                'pinned_rows is enabled and the session driver is "array", which forgets everything between requests.',
                'Use a real session driver, or switch the feature off.',
            );
        }
    }

    /**
     * A theme that names a slot the template does not have.
     *
     * An unknown *feature* name throws, deliberately, because a silently
     * ignored one leaves an author looking at a table missing something they
     * asked for. A theme slot is the same mistake with the same symptom, and
     * config is not somewhere the package can throw from.
     */
    protected function checkThemes(): void
    {
        $themes = config('dynamic-table.themes');

        if (! is_array($themes)) {
            return;
        }

        // Every built-in theme's map, unioned: a slot only Bootstrap fills —
        // "wrapper" is one — is still a real slot, and reporting it against
        // the fallback theme's keys alone would call it a mistake.
        $known = [];

        foreach (Theme::ALL as $builtin) {
            $known = array_merge($known, array_keys(Theme::classes($builtin)));
        }

        $known = array_unique($known);

        foreach ($themes as $name => $slots) {
            if (! is_array($slots)) {
                continue;
            }

            $unknown = array_diff(array_keys($slots), $known, ['extends']);

            if ($unknown !== []) {
                $this->add(
                    'warning',
                    'config',
                    "Theme [{$name}] sets slots that do not exist: ".implode(', ', $unknown).'.',
                    'Check the slot names against Support\Theme; an unknown one is silently dropped.',
                );
            }
        }
    }

    protected function add(string $level, string $table, string $message, string $fix): void
    {
        $this->findings[] = ['level' => $level, 'table' => $table, 'message' => $message, 'fix' => $fix];
    }

    /** Errors first, because a report read from the top should start with what is broken. */
    protected function report(): int
    {
        if ($this->findings === []) {
            $this->components->info('Nothing to report. Every registered table looks healthy.');

            return self::SUCCESS;
        }

        $order = ['error' => 0, 'warning' => 1, 'note' => 2];

        usort($this->findings, static fn (array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);

        foreach ($this->findings as $finding) {
            $line = "[{$finding['table']}] {$finding['message']}";

            match ($finding['level']) {
                'error' => $this->components->error($line),
                'warning' => $this->components->warn($line),
                default => $this->components->info($line),
            };

            $this->line('    → '.$finding['fix']);
        }

        $errors = count(array_filter($this->findings, static fn (array $f): bool => $f['level'] === 'error'));

        $this->newLine();
        $this->line(sprintf(
            '%d finding%s, %d of them errors.',
            count($this->findings),
            count($this->findings) === 1 ? '' : 's',
            $errors,
        ));

        // Only an error fails the command: a warning is a judgement call about
        // a table someone may have every reason to keep as it is, and failing
        // CI over one would teach people to stop running this.
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
