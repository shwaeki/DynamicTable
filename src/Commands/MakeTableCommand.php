<?php

namespace Shwaeki\DynamicTable\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MakeTableCommand extends GeneratorCommand
{
    protected $name = 'make:dynamic-table';

    protected $description = 'Create a new DynamicTable class';

    protected $type = 'DynamicTable';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/table.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\DynamicTables';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $model = (string) ($this->option('model') ?: Str::of(class_basename($name))->beforeLast('Table')->singular());
        $modelClass = str_contains($model, '\\')
            ? ltrim($model, '\\')
            : $this->rootNamespace().'Models\\'.$model;

        $features = $this->option('features');
        $featureBlock = '';

        if (is_string($features) && $features !== '') {
            $list = collect(explode(',', $features))
                ->map(static fn (string $feature): string => "        '".trim($feature)."',")
                ->implode("\n");

            $featureBlock = "\n    /** @var list<string> */\n    protected array \$features = [\n{$list}\n    ];\n";
        }

        return str_replace(
            ['{{ modelClass }}', '{{ model }}', '{{ features }}'],
            [$modelClass, class_basename($modelClass), $featureBlock],
            $stub,
        );
    }

    /** @return list<array{0: string, 1: string|null, 2: int, 3: string}> */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The Eloquent model the table displays'],
            ['features', 'f', InputOption::VALUE_OPTIONAL, 'Comma separated feature list, e.g. views,export,bulk_actions'],
            ['force', null, InputOption::VALUE_NONE, 'Overwrite an existing table class'],
        ];
    }
}
