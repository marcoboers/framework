<?php

namespace Illuminate\Foundation\Console;

use Doctrine\DBAL\Schema\Column;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use SplFileObject;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'model:show')]
class ShowModelCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'model:show {model}';

    /**
     * The name of the console command.
     *
     * This name is used to identify the command during lazy loading.
     *
     * @var string|null
     *
     * @deprecated
     */
    protected static $defaultName = 'model:show';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show information about an Eloquent model';

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'model:show {model : The model to show}
                {--database= : The database connection to use}
                {--json : Output the model as JSON}';

    /**
     * The methods that can be called in a model to indicate a relation.
     *
     * @var array
     */
    protected $relationMethods = [
        'hasMany',
        'hasManyThrough',
        'hasOneThrough',
        'belongsToMany',
        'hasOne',
        'belongsTo',
        'morphOne',
        'morphTo',
        'morphMany',
        'morphToMany',
        'morphedByMany',
    ];

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (! interface_exists('Doctrine\DBAL\Driver')) {
            return $this->components->error(
                'Displaying model information requires [doctrine/dbal].'
            );
        }

        $class = $this->qualifyModel($this->argument('model'));

        try {
            $model = $this->laravel->make($class);
        } catch (BindingResolutionException $e) {
            return $this->components->error($e->getMessage());
        }

        if ($this->option('database')) {
            $model->setConnection($this->option('database'));
        }

        $this->display(
            $class,
            $model->getConnection()->getName(),
            $model->getConnection()->getTablePrefix().$model->getTable(),
            $this->getAttributes($model),
            $this->getRelations($model),
        );
    }

    /**
     * Get the column attributes for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection
     */
    protected function getAttributes($model)
    {
        $schema = $model->getConnection()->getDoctrineSchemaManager();
        $columns = $schema->listTableColumns($model->getConnection()->getTablePrefix().$model->getTable());

        return collect($columns)
            ->values()
            ->map(fn (Column $column) => [
                'name' => $column->getName(),
                'type' => $this->getColumnType($column),
                'nullable' => ! $column->getNotnull(),
                'fillable' => $model->isFillable($column->getName()),
                'hidden' => $this->attributeIsHidden($column->getName(), $model),
                'appended' => null,
                'cast' => $this->getCastType($column->getName(), $model),
            ])
            ->merge($this->getVirtualAttributes($model, $columns));
    }

    /**
     * Get the virtual (non-column) attributes for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Doctrine\DBAL\Schema\Column[]  $columns
     * @return \Illuminate\Support\Collection
     */
    protected function getVirtualAttributes($model, $columns)
    {
        $class = new ReflectionClass($model);

        return collect($class->getMethods())
            ->filter(fn (ReflectionMethod $method) => ! $method->isStatic()
                && ! $method->isAbstract()
                && $method->getDeclaringClass()->getName() === get_class($model)
            )
            ->mapWithKeys(function (ReflectionMethod $method) use ($model) {
                if (preg_match('/^get(.*)Attribute$/', $method->getName(), $matches) === 1) {
                    return [Str::snake($matches[1]) => 'accessor'];
                } elseif ($model->hasAttributeMutator($method->getName())) {
                    return [Str::snake($method->getName()) => 'attribute'];
                } else {
                    return [];
                }
            })
            ->reject(fn ($cast, $name) => collect($columns)->has($name))
            ->map(fn ($cast, $name) => [
                'name' => $name,
                'type' => null,
                'nullable' => null,
                'fillable' => $model->isFillable($name),
                'hidden' => $this->attributeIsHidden($name, $model),
                'appended' => $model->hasAppended($name),
                'cast' => $cast,
            ])
            ->values();
    }

    /**
     * Get the relations from the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection
     */
    protected function getRelations($model)
    {
        return collect(get_class_methods($model))
            ->map(fn ($method) => new ReflectionMethod($model, $method))
            ->filter(fn (ReflectionMethod $method) => ! $method->isStatic()
                && ! $method->isAbstract()
                && $method->getDeclaringClass()->getName() === get_class($model)
            )
            ->filter(function (ReflectionMethod $method) {
                $file = new SplFileObject($method->getFileName());
                $file->seek($method->getStartLine() - 1);
                $code = '';
                while ($file->key() < $method->getEndLine()) {
                    $code .= $file->current();
                    $file->next();
                }

                return collect($this->relationMethods)
                    ->contains(fn ($relationMethod) => str_contains($code, '$this->'.$relationMethod.'('));
            })
            ->mapWithKeys(fn (ReflectionMethod $method) => [$method->getName() => $method->invoke($model)])
            ->map(fn (Relation $relation, string $name) => [
                'name' => $name,
                'type' => Str::afterLast(get_class($relation), '\\'),
                'related' => get_class($relation->getRelated()),
            ])
            ->values();
    }

    /**
     * Render the model information.
     *
     * @param  string  $class
     * @param  string  $database
     * @param  string  $table
     * @param  \Illuminate\Support\Collection  $attributes
     * @param  \Illuminate\Support\Collection  $relations
     * @return void
     */
    protected function display($class, $database, $table, $attributes, $relations)
    {
        $this->option('json')
            ? $this->displayJson($class, $database, $table, $attributes, $relations)
            : $this->displayCli($class, $database, $table, $attributes, $relations);
    }

    /**
     * Render the model information as JSON.
     *
     * @param  string  $class
     * @param  string  $database
     * @param  string  $table
     * @param  \Illuminate\Support\Collection  $attributes
     * @param  \Illuminate\Support\Collection  $relations
     * @return void
     */
    protected function displayJson($class, $database, $table, $attributes, $relations)
    {
        $this->output->writeln(
            collect([
                'class' => $class,
                'database' => $database,
                'table' => $table,
                'attributes' => $attributes,
                'relations' => $relations,
            ])->toJson()
        );
    }

    /**
     * Render the model information for the CLI.
     *
     * @param  string  $class
     * @param  string  $database
     * @param  string  $table
     * @param  \Illuminate\Support\Collection  $attributes
     * @param  \Illuminate\Support\Collection  $relations
     * @return void
     */
    protected function displayCli($class, $database, $table, $attributes, $relations)
    {
        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>'.$class.'</>');
        $this->components->twoColumnDetail('Database', $database);
        $this->components->twoColumnDetail('Table', $table);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Relations</>');

        foreach ($relations as $relation) {
            $this->components->twoColumnDetail(
                $relation['name'].' <fg=gray>'.$relation['type'].'</>',
                $relation['related']
            );
        }

        $this->newLine();

        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Attributes</>',
            'type <fg=gray>/</> <fg=yellow;options=bold>cast</>',
        );

        foreach ($attributes as $key => $attribute) {
            $first = sprintf('%s %s', $attribute['name'], collect(['nullable', 'fillable', 'hidden', 'appended'])
                ->filter(fn ($property) => $attribute[$property])
                ->map(fn ($property) => sprintf('<fg=gray>%s</>', $property))
                ->implode('<fg=gray>,</> '));

            $second = $attribute['type'];

            if ($attribute['cast']) {
                $second = '<fg=yellow;options=bold>'.$attribute['cast'].'</>';
            }

            $this->components->twoColumnDetail(
                str($first)->trim(), str($second)->when(! class_exists($attribute['cast']))->lower(),
            );
        }

        $this->newLine();
    }

    /**
     * Get the cast type for the given column.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    protected function getCastType($column, $model)
    {
        if ($model->hasGetMutator($column) || $model->hasSetMutator($column)) {
            return 'accessor';
        }

        if ($model->hasAttributeMutator($column)) {
            return 'attribute';
        }

        return $this->getCastsWithDates($model)->get($column) ?? null;
    }

    /**
     * Get the model casts, including any date casts.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection
     */
    protected function getCastsWithDates($model)
    {
        return collect([
            ...collect($model->getDates())->flip()->map(fn () => 'datetime'),
            ...$model->getCasts(),
        ]);
    }

    /**
     * Get the type of the given column.
     *
     * @param  \Doctrine\DBAL\Schema\Column  $column
     * @return string
     */
    protected function getColumnType($column)
    {
        $name = $column->getType()->getName();

        $unsigned = $column->getUnsigned() ? ' unsigned' : '';

        return sprintf('%s%s', $name, $unsigned);
    }

    /**
     * Determine if the given attribute is hidden.
     *
     * @param  string  $attribute
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function attributeIsHidden($attribute, $model)
    {
        if (count($model->getHidden()) > 0) {
            return in_array($attribute, $model->getHidden());
        }

        if (count($model->getVisible()) > 0) {
            return ! in_array($attribute, $model->getVisible());
        }

        return false;
    }

    /**
     * Qualify the given model class base name.
     *
     * @param  string  $model
     * @return string
     *
     * @see \Illuminate\Console\GeneratorCommand
     */
    protected function qualifyModel(string $model)
    {
        if (class_exists($model)) {
            return $model;
        }

        $model = ltrim($model, '\\/');

        $model = str_replace('/', '\\', $model);

        $rootNamespace = $this->laravel->getNamespace();

        if (Str::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Models'))
            ? $rootNamespace.'Models\\'.$model
            : $rootNamespace.$model;
    }
}
