<h2 align="center">
    Laravel Nova package to export resources
</h2>

<p align="center">
    <a href="https://packagist.org/packages/allanvb/nova-exports"><img src="https://img.shields.io/packagist/v/allanvb/nova-exports?color=orange&style=flat-square" alt="Packagist Version"></a>
    <a href="https://packagist.org/packages/allanvb/nova-exports"><img src="https://img.shields.io/github/last-commit/allanvb/nova-exports?color=blue&style=flat-square" alt="GitHub last commit"></a>
    <a href="https://packagist.org/packages/allanvb/nova-exports"><img src="https://img.shields.io/packagist/l/allanvb/nova-exports?color=brightgreen&style=flat-square" alt="License"></a>
</p>

This package adds and universal export action to your nova application.

## Requirements

- `laravel/nova: ^3.0`
- `gobrightspot/nova-detached-actions: ^1.1`
- `optimistdigital/nova-multiselect-field: ^2.0`
- `kpolicar/nova-date-range: dev-master`
- `rap2hpoutre/fast-excel: ^2.5`


## Usage

To use the export action, you must add it to `fields` method of your resource. 

```php
use Allanvb\NovaExports\ExportResourceAction;

public function actions(Request $request): array
{
    return [
        new ExportResourceAction($this),
    ];
}
```

#### Available methods

- `only([])` - Define whitelist of fields that can be exported.                                                                                            
- `except([])` - Excludes the given fields from exporting list. 
- `withUserSelection()` - Enables multi-select field that allow user to select the fields when exporting.
- `usesDateRange($columnName)` - Enables date range field that allow user to select the range of dates when exporting. Method accepts a *(string)* column name.
- `usesGenerator()` - Enables cursor usage when getting data from database. 

You are also able to use all of [Nova Action](https://nova.laravel.com/docs/3.0/actions/defining-actions.html) methods, and all of [Detached Actions](https://github.com/gobrightspot/nova-detached-actions#display-on-different-screens) methods on `ExportResourceAction`.

## Exceptions

The package can throw the following exceptions:

| Exception                       | Reason                                     |
| ------------------------------- | ------------------------------------------ |
| *ColumnNotFoundException*       | Column does not exist in given table.      |
| *EmptyDataException*            | No records to export.                      |
| *RangeColumnNotDateException*   | Given column for date range is not a date. |

## To do

- [x] Export single resource
- [x] Implement user selection export
- [x] Implement generator on exporting
- [ ] Add option to export to PDF
- [ ] Add Eloquent relations export
- [ ] Add way to perform joins on export

## License

The MIT License (MIT). Please see [License File](LICENCE) for more information.
