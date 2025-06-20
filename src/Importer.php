<?php

namespace Statamic\Importer;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use Statamic\Importer\Exceptions\JobBatchesTableMissingException;
use Statamic\Importer\Imports\Import;
use Statamic\Importer\Jobs\ImportItemJob;
use Statamic\Importer\Jobs\UpdateCollectionTreeJob;
use Statamic\Importer\Sources\Csv;
use Statamic\Importer\Sources\Xml;

class Importer
{
    protected static array $transformers = [];

    public static function run(Import $import): void
    {
        $import->batchIds([])->save();
        Cache::forget("importer.{$import->id}.parents");

        if (! Schema::connection(config('queue.batching.database', env('DB_CONNECTION', 'sqlite')))->hasTable(config('queue.batching.table', 'job_batches'))) {
            throw new JobBatchesTableMissingException;
        }

        $items = match ($import->get('type')) {
            'csv' => (new Csv($import))->getItems($import->get('path')),
            'xml' => (new Xml($import))->getItems($import->get('path')),
        };

        $items
            ->chunk(500)
            ->each(function (LazyCollection $chunk) use ($import) {
                Bus::batch($chunk->map(fn (array $item) => new ImportItemJob($import, $item)))
                    ->before(fn (Batch $batch) => $import->batchIds([...$import->batchIds(), $batch->id])->save())
                    ->finally(function (Batch $batch) use ($import) {
                        if ($import->allBatchesHaveFinished() && $import->get('destination.type') === 'entries') {
                            UpdateCollectionTreeJob::dispatch($import);
                        }
                    })
                    ->dispatch();
            });
    }

    public static function getTransformer(string $fieldtype): ?string
    {
        return static::$transformers[$fieldtype] ?? null;
    }

    public static function registerTransformer(string $fieldtype, string $class): void
    {
        static::$transformers[$fieldtype] = $class;
    }
}
