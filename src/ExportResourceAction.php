<?php

namespace Allanvb\NovaExports;

use Allanvb\NovaExports\Exceptions\ColumnNotFoundException;
use Allanvb\NovaExports\Exceptions\EmptyDataException;
use Allanvb\NovaExports\Exceptions\RangeColumnNotDateException;
use Brightspot\Nova\Tools\DetachedActions\DetachedAction;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kpolicar\DateRange\DateRange;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Heading;
use OptimistDigital\MultiselectField\Multiselect;
use Rap2hpoutre\FastExcel\Facades\FastExcel;
use Throwable;

class ExportResourceAction extends NovaExportAction
{
    /**
     * @var string
     */
    private $rangeColumn = 'created_at';

    /**
     * @var Collection
     */
    private $only;

    /**
     * @var Collection
     */
    private $except;

    /**
     * @var bool
     */
    private $userSelection = false;

    /**
     * @var array
     */
    private $actionFields = [];

    /**
     * @var bool
     */
    private $usesGenerator = false;

    /**
     * @var bool
     */
    private $usesDateRange = false;

    /**
     * @var bool
     */
    private $usesOwnQuery = false;

    /**
     * @param ActionFields $fields
     * @return array|string[]
     */
    public function handle(ActionFields $fields): array
    {
        try {
            $columns = $this->getBuiltColumnList($fields);

            FastExcel::data(
                $this->getQueryData(
                    $columns,
                    $fields
                )
            )->export(
                $this->generateExportPath()
            );

            $filePath = $this->resolveStorage(
                $this->generateFileName(true)
            );

            return DetachedAction::download(
                $filePath,
                $this->generateFileName()
            );
        } catch (Throwable $exception) {
            $this->fail($exception);
            return DetachedAction::danger('An error occurred while exporting data. ' . $exception->getMessage());
        }
    }

    /**
     * Return the list of fields rendered on action call
     *
     * @return array
     */
    public function fields(): array
    {
        if ($this->usesDateRange) {
            $this->actionFields[] = Heading::make(
                'Select the date or date range. <br> Leave empty to export all.'
            )->asHtml();
            $this->actionFields[] = DateRange::make('Date range', ['from', 'to']);
        }

        return $this->actionFields;
    }

    /**
     * Set only given columns
     *
     * @param array $columns
     * @return $this
     */
    public function only(array $columns = []): ExportResourceAction
    {
        $this->only = collect($columns);

        return $this;
    }

    /**
     * Set columns except given
     *
     * @param array $columns
     * @return $this
     */
    public function except(array $columns = []): ExportResourceAction
    {
        $this->except = collect($columns);

        return $this;
    }

    /**
     * Enable user columns selection
     *
     * @return $this
     */
    public function withUserSelection(): ExportResourceAction
    {
        $this->userSelection = true;
        $columns = $this->tableColumns;

        if ($this->only) {
            $columns = $this->getOnlyColumns($columns);
        } elseif ($this->except) {
            $columns = $this->getExceptColumns($columns);
        }

        $columns = $columns->mapWithKeys(
            function ($column) {
                $prettyName = Str::title(
                    preg_replace(
                        "/[\-_]/",
                        " ",
                        $column
                    )
                );

                return [$column => $prettyName];
            }
        );

        $this->actionFields[] = Heading::make(
            'Select the fields you want to export'
        );
        $this->actionFields[] = Multiselect::make('Columns')
            ->placeholder('Columns to export')
            ->options($columns)
            ->reorderable()
            ->rules('required');

        return $this;
    }

    /**
     * Enables usage of generator when retrieve database data
     *
     * @return $this
     */
    public function usesGenerator(): ExportResourceAction
    {
        $this->usesGenerator = true;

        return $this;
    }

    /**
     * Enable usage of date range and enable field for it
     *
     * @param string|null $column
     * @return $this
     * @throws RangeColumnNotDateException
     * @throws ColumnNotFoundException
     */
    public function usesDateRange(?string $column = null): ExportResourceAction
    {
        $this->usesDateRange = true;

        if ($column) {
            $this->rangeColumn = $column;
        }

        if (!$this->tableColumns->contains($this->rangeColumn)) {
            throw new ColumnNotFoundException(
                'Column ' . $this->rangeColumn . ' does not exists in ' . $this->table . ' table'
            );
        }

        if (!in_array($this->rangeColumn, $this->model->getDates(), true)) {
            throw new RangeColumnNotDateException(
                'Range column must be explicitly declared as Eloquent date field'
            );
        }

        return $this;
    }

    /**
     * Add the own query
     *
     * @param callable $query
     * @return $this
     */
    public function queryBuilder(callable $query): ExportResourceAction
    {
        $this->usesOwnQuery = true;

        $this->queryBuilder = $query($this->queryBuilder);

        return $this;
    }

    /**
     * @param ActionFields $fields
     * @return array
     */
    protected function getBuiltColumnList(ActionFields $fields): array
    {
        $columns = $this->tableColumns;

        if ($this->userSelection) {
            $selectedColumns = json_decode(
                $fields->getAttributes()['columns'],
                true
            );

            $columns = collect($selectedColumns);
        }

        if ($this->only) {
            $columns = $this->getOnlyColumns($columns);
        } elseif ($this->except) {
            $columns = $this->getExceptColumns($columns);
        }

        return $columns->toArray();
    }

    /**
     * Return filtered only columns
     *
     * @param Collection $columns
     * @return Collection
     */
    protected function getOnlyColumns(Collection $columns): Collection
    {
        if ($this->only && $this->only->isNotEmpty()) {
            $columns = $columns->filter(
                function ($column) {
                    return in_array($column, $this->only->toArray(), true);
                }
            );
        }

        return $columns;
    }

    /**
     * Return filtered excepted columns
     *
     * @param Collection $columns
     * @return Collection
     */
    protected function getExceptColumns(Collection $columns): Collection
    {
        if ($this->except && $this->except->isNotEmpty()) {
            $columns = $columns->filter(
                function ($column) {
                    return !in_array($column, $this->except->toArray(), true);
                }
            );
        }

        return $columns;
    }

    /**
     * @param Builder $builder
     * @return Generator|null
     */
    protected function resourceGenerator(Builder $builder): ?Generator
    {
        foreach ($builder->cursor() as $item) {
            yield $item;
        }
    }

    /**
     * @param array $columns
     * @param ActionFields $fields
     * @return Generator|array|null
     */
    protected function getQueryData(array $columns, ActionFields $fields)
    {
        if (!$this->usesOwnQuery) {
            $columns = array_map(fn($column) => $this->table.'.'.$column, $columns);

            $this->queryBuilder = $this->queryBuilder->select($columns);
        }

        if ($this->usesDateRange) {
            $from = $fields->get('from');
            $to = $fields->get('to');

            if ($from && $to) {
                $this->queryBuilder = $this->queryBuilder
                    ->whereBetween(
                        $this->table . '.' . $this->rangeColumn,
                        [
                            Carbon::parse($from)->startOfDay(),
                            Carbon::parse($to)->endOfDay()
                        ]
                    );
            } elseif ($from && is_null($to)) {
                $this->queryBuilder = $this->queryBuilder
                    ->whereDate(
                        $this->table . '.' . $this->rangeColumn,
                        Carbon::parse($from)->startOfDay()
                    );
            }

            $this->queryBuilder = $this->queryBuilder->orderBy(
                $this->table . '.' . $this->rangeColumn
            );
        }


        if ($this->queryBuilder->count() === 0) {
            throw new EmptyDataException('No records matching selection');
        }

        if ($this->usesGenerator) {
            $data = $this->resourceGenerator(
                $this->queryBuilder
            );
        } else {
            $data = $this->queryBuilder->get();
            $data = json_decode(
                json_encode($data),
                true
            );
        }

        return $data;
    }
}

