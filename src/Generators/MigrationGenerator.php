<?php

namespace Chand335\SchemaSync\Generators;

use Illuminate\Support\Str;

class MigrationGenerator
{
    public function generate(string $sql): string
    {
        $directory = database_path('migrations');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $timestamp = now()->format('Y_m_d_His');
        $uuid = Str::uuid();
        $filename = sprintf('%s_schema_sync_%s.php', $timestamp, $uuid);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        $contents = $this->buildMigrationContents($sql);
        file_put_contents($path, $contents);

        return $path;
    }

    protected function buildMigrationContents(string $sql): string
    {
        $indentedSql = $this->indentSql($sql);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
{$indentedSql}SQL);
    }

    public function down(): void
    {
        // Intentionally left empty. Use version control to rollback schema changes if required.
    }
};
PHP;
    }

    protected function indentSql(string $sql): string
    {
        $lines = explode("\n", trim($sql));
        $indented = array_map(fn ($line) => '            '.$line, $lines);

        return implode("\n", $indented)."\n";
    }
}
