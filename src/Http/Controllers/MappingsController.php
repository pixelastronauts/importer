<?php

namespace Statamic\Importer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Facades;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Importer\Http\Requests\MappingsRequest;
use Statamic\Importer\Importer;
use Statamic\Importer\Sources\Csv;
use Statamic\Importer\Sources\Xml;

class MappingsController extends CpController
{
    public function __invoke(MappingsRequest $request)
    {
        $blueprint = $this->getBlueprint($request);

        $row = match ($request->type) {
            'csv' => (new Csv([]))->getItems($request->path)->first(),
            'xml' => (new Xml([]))->getItems($request->path)->first(),
        };

        return [
            'fields' => $blueprint->fields()->all()
                ->map(function (Field $field) use ($request, $row) {
                    $fields = [];

                    if ($transformer = Importer::getTransformer($field->type())) {
                        $fields = (new $transformer(field: $field))->fieldItems();
                    }

                    $blueprint = Facades\Blueprint::makeFromFields([
                        'key' => [
                            'type' => 'select',
                            'hide_display' => true,
                            'options' => collect($row)->map(fn ($value, $key) => [
                                'key' => $key,
                                'value' => "<{$key}>: {$value}",
                            ])->values(),
                            'clearable' => true,
                        ],
                        ...$fields
                    ]);

                    return [
                        'type' => $field->type(),
                        'handle' => $field->handle(),
                        'display' => $field->display(),
                        'config' => $field->config(),
                        'fields' => $blueprint->fields()->toPublishArray(),
                        'meta' => $blueprint->fields()->meta(),
                        'values' => $blueprint->fields()
                            ->addValues(Arr::get($request->mappings, $field->handle()) ?? [])
                            ->values()->all(),
                    ];
                })
                ->unique('handle')
                ->values(),
            'unique_keys' => $blueprint->fields()->all()
                ->filter(fn ($field) => in_array($field->type(), ['text', 'integer', 'slug']))
                ->map(fn ($field) => ['handle' => $field->handle(), 'display' => $field->display()])
                ->values()
        ];
    }

    protected function getBlueprint(Request $request): Blueprint
    {
        if ($request->destination['type'] === 'entries') {
            return Collection::find($request->destination['collection'])->entryBlueprint();
        }

        if ($request->destination['type'] === 'terms') {
            return Taxonomy::find($request->destination['taxonomy'])->termBlueprint();
        }

        if ($request->destination['type'] === 'users') {
            return User::blueprint();
        }
    }

    protected function getRelatedFieldOptions(Field $field): array
    {
        if ($field->type() === 'entries') {
            return [
                'collections' => Collection::all()->map->handle()->values(),
            ];
        }

        if ($field->type() === 'terms') {
            return [
                'taxonomies' => Taxonomy::all()->map->handle()->values(),
            ];
        }

        if ($field->type() === 'users') {
            return [];
        }
    }
}
