# Schema Sync

Schema Sync is a Laravel package that compares two database connections, highlights schema differences, and generates SQL or migrations to synchronise the target schema with the source.

## Installation

```bash
composer require chand335/schema-sync
```

Publish the configuration to customise ignore lists or output paths:

```bash
php artisan vendor:publish --provider="Chand335\\SchemaSync\\SchemaSyncServiceProvider" --tag="config"
```

## Usage

Run the Artisan command with the source and target connection names as defined in `config/database.php`:

```bash
php artisan schema:sync source target
```

### Options

- `--ignore=` – comma separated list of tables to ignore in addition to the defaults defined in `config/schema-sync.php`.
- `--output` – write the generated SQL to `database/schema-sync/{source}_to_{target}_YYYYMMDD_HHMM.sql`.
- `--migration` – create a timestamped migration file containing the generated SQL (uses `Str::uuid()` for uniqueness).

The command prints the generated SQL to the console by default. Use the `--output` or `--migration` flags to persist the output.

## Extensibility

The package is structured with dedicated services for comparison and SQL generation to make future enhancements—such as dry runs, selective syncing, or UI integrations—straightforward.
