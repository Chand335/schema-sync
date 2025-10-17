<?php

namespace Chand335\SchemaSync\Commands;

use Chand335\SchemaSync\Generators\AlterSqlGenerator;
use Chand335\SchemaSync\Generators\MigrationGenerator;
use Chand335\SchemaSync\Services\SchemaComparator;
use Illuminate\Console\Command;

class SchemaSyncCommand extends Command
{
    protected $signature = 'schema:sync
        {source : Source database connection name}
        {target : Target database connection name}
        {--ignore= : Comma separated list of tables to ignore}
        {--output : Write SQL output to a timestamped file}
        {--migration : Generate a Laravel migration file with the ALTER statements}';

    protected $description = 'Compare two database schemas and generate SQL to synchronise them.';

    public function __construct(
        protected SchemaComparator $comparator,
        protected AlterSqlGenerator $sqlGenerator,
        protected MigrationGenerator $migrationGenerator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = $this->argument('source');
        $target = $this->argument('target');

        $ignoreTables = $this->resolveIgnoreTables();

        $diff = $this->comparator->compare($source, $target, $ignoreTables);
        $statements = $this->sqlGenerator->generate($diff);

        if (empty($statements)) {
            $this->info('Schemas are already in sync.');
            return self::SUCCESS;
        }

        $sql = implode("\n", array_map(fn (string $statement) => rtrim($statement, ';').';', $statements));
        $this->line($sql);

        if ($this->option('output')) {
            $filePath = $this->writeOutputFile($source, $target, $sql);
            $this->info("SQL written to {$filePath}");
        }

        if ($this->option('migration')) {
            $path = $this->migrationGenerator->generate($sql);
            $this->info("Migration written to {$path}");
        }

        return self::SUCCESS;
    }

    protected function resolveIgnoreTables(): array
    {
        $configIgnore = config('schema-sync.default_ignore_tables', []);
        $optionIgnore = $this->option('ignore');
        $optionTables = [];

        if (is_string($optionIgnore) && $optionIgnore !== '') {
            $optionTables = array_filter(array_map('trim', explode(',', $optionIgnore)));
        }

        return array_values(array_unique(array_filter(array_merge($configIgnore, $optionTables))));
    }

    protected function writeOutputFile(string $source, string $target, string $sql): string
    {
        $directory = base_path(config('schema-sync.output_path', 'database/schema-sync'));
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $timestamp = now()->format('Ymd_His');
        $filename = sprintf('%s_to_%s_%s.sql', $source, $target, $timestamp);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        file_put_contents($path, $sql.PHP_EOL);

        return $path;
    }
}
