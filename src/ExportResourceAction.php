<?php

namespace Allanvb\NovaExports;

use Allanvb\NovaExports\Exceptions\RangeColumnNotDateException;
use Allanvb\NovaExports\Exceptions\ColumnNotFoundException;
use Allanvb\NovaExports\Exceptions\EmptyDataException;
use App\Nova\Resource as NovaResource;
use Brightspot\Nova\Tools\DetachedActions\DetachedAction;
use Generator;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kpolicar\DateRange\DateRange;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Heading;
use OptimistDigital\MultiselectField\Multiselect;
use Rap2hpoutre\FastExcel\Facades\FastExcel;
use ReflectionClass;
use ReflectionException;
use Throwable;

class ExportResourceAction extends DetachedAction
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @var string
     */
    public $confirmButtonText = 'Export';

    /**
     * @var string
     */
    public $confirmText = 'Are you sure you want to perform export action ?';

    /**
     * @var string
     */
    public $icon = 'hero-download';

    /**
     * @var string
     */
    private $resourceName;

    /**
     * @var Model|null
     */
    private $model;

    /**
     * @var Collection
     */
    private $tableColumns;

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
     * @var Builder
     */
    private $queryBuilder;

    /**
     * @var bool
     */
    private $usesDateRange = false;

    /**
     * @var string
     */
    private $rangeColumn = 'created_at';

    /**
     * ExportResourceAction constructor.
     * @param NovaResource $novaResource
     * @throws ReflectionException
     */
    public function __construct(NovaResource $novaResource)
    {
        $this->resourceName = (new ReflectionClass($novaResource))
            ->getShortName();

        $this->model = $novaResource->resource;

        $this->tableColumns = collect(
            Schema::getColumnListing(
                $this->model->getTable()
            )
        );

        $this->queryBuilder = DB::table(
            $this->model->getTable()
        );

        $this->extraClassesWithDefault('bg-info');
    }

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Export ' . Str::plural($this->resourceName);
    }

    /**
     * @param ActionFields $fields
     * @return array|string[]
     */
    public function handle(ActionFields $fields): array
    {
        try {
            $columns = $this->getBuiltColumnList($fields);

            Storage::disk('public')->makeDirectory('exports');
            $path = Storage::disk('public')->path(
                $this->generateFileName(true)
            );

            FastExcel::data(
                $this->getQueryData(
                    $columns,
                    $fields
                )
            )->export($path);

            return DetachedAction::download(
                Storage::disk('public')->url(
                    $this->generateFileName(true)
                ),
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
     * @return ExportResourceAction
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
                'Column ' . $this->rangeColumn . ' does not exists in ' . $this->model->getTable() . ' table'
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
     * @param bool $withPath
     * @return string
     */
    protected function generateFileName(bool $withPath = false): string
    {
        $fileName = Str::plural($this->resourceName) . '_' . now()->format('m_d_Y') . '.xlsx';

        if ($withPath) {
            $fileName = 'exports/' . $fileName;
        }

        return $fileName;
    }

    /**
     * @param array $columns
     * @param ActionFields $fields
     * @return Generator|array|null
     */
    protected function getQueryData(array $columns, ActionFields $fields)
    {
        $this->queryBuilder = $this->queryBuilder->select($columns);

        if ($this->usesDateRange) {
            $from = $fields->get('from');
            $to = $fields->get('to');

            if ($from && $to) {
                $this->queryBuilder = $this->queryBuilder
                    ->whereBetween(
                        $this->rangeColumn,
                        [
                            Carbon::parse($from)->startOfDay(),
                            Carbon::parse($to)->endOfDay()
                        ]
                    );
            } elseif ($from && is_null($to)) {
                $this->queryBuilder = $this->queryBuilder
                    ->whereDate(
                        $this->rangeColumn,
                        Carbon::parse($from)->startOfDay()
                    );
            }

            $this->queryBuilder = $this->queryBuilder->orderBy($this->rangeColumn);
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
