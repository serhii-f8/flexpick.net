<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Pages;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEmailLogs extends ListRecords
{
    protected static string $resource = AuditEmailLogResource::class;
}
