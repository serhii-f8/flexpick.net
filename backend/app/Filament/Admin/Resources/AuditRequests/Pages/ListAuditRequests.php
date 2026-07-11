<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\ListDefaults;
use Filament\Resources\Pages\ListRecords;

class ListAuditRequests extends ListRecords
{
    use ListDefaults;

    protected static string $resource = AuditRequestResource::class;
}
