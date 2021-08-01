<?php


namespace Allanvb\NovaExports;


use App\Nova\Resource as NovaResource;
use Brightspot\Nova\Tools\DetachedActions\DetachedAction;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\ActionFields;
use ReflectionClass;
use ReflectionException;

abstract class NovaExportAction extends DetachedAction
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
    public $disk = 'public';

    /**
     * @var string
     */
    protected $resourceName;

    /**
     * @var string
     */
    protected $table;

    /**
     * @var Model|null
     */
    protected $model;

    /**
     * @var Collection
     */
    protected $tableColumns;

    /**
     * @var Builder
     */
    protected $queryBuilder;

    /**
     * @var string
     */
    protected $fileName;

    /**
     * ExportResourceAction constructor.
     * @param  NovaResource  $novaResource
     * @throws ReflectionException
     */
    public function __construct(NovaResource $novaResource)
    {
        $this->resourceName = (new ReflectionClass($novaResource))
            ->getShortName();

        $this->model = $novaResource->resource;

        $this->table = $this->model->getTable();

        $this->tableColumns = collect(
            Schema::getColumnListing(
                $this->table
            )
        );

        $this->queryBuilder = DB::table(
            $this->table
        );
    }

    /**
     * @return string
     */
    public function label(): string
    {
        return $this->label ?: $this->getActionName();
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name ?: $this->getActionName();
    }

    /**
     * Set the download file name
     *
     * @param  string  $name
     * @return $this
     */
    public function fileName(string $name): self
    {
        $this->fileName = $name;

        return $this;
    }

    /**
     * @param  string  $disk
     * @return $this
     */
    public function disk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * @return string
     */
    protected function getActionName(): string
    {
        return 'Export ' . Str::plural($this->resourceName);
    }

    /**
     * @param  bool  $withPath
     * @return string
     */
    protected function generateFileName(bool $withPath = false): string
    {
        $defaultName = Str::plural($this->resourceName) . '_' . now()->format('m_d_Y_H_i');

        $name = ($this->fileName ?: $defaultName) . '.xlsx';

        if ($withPath) {
            $name = 'exports/' . $name;
        }

        return $name;
    }

    /**
     * Generates an exports path and return a path
     *
     * @return string
     */
    protected function generateExportPath(): string
    {
        Storage::disk('public')->makeDirectory('exports');

        return Storage::disk('public')->path(
            $this->generateFileName(true)
        );
    }

    /**
     * @param  string  $exportName
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function resolveStorage(string $exportName): string
    {
        if ($this->disk === 'public') {
            return Storage::disk('public')->url(
                $this->generateFileName(true)
            );
        }

        $fileStream = Storage::disk('public')
            ->get($exportName);

        $diskUpload = Storage::disk($this->disk)
            ->put(
                $exportName,
                $fileStream
            );

        if ($diskUpload) {
            Storage::disk('public')
                ->delete($exportName);
        }

        return Storage::disk($this->disk)
            ->url($exportName);
    }

    /**
     * @param  ActionFields  $fields
     * @return array
     */
    abstract protected function getBuiltColumnList(ActionFields $fields): array;

    /**
     * @param  array  $columns
     * @param  ActionFields  $fields
     * @return mixed
     */
    abstract protected function getQueryData(array $columns, ActionFields $fields);

    /**
     * @param  ActionFields  $fields
     * @return array
     */
    abstract public function handle(ActionFields $fields): array;
}
