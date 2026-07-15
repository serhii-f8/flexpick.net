<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use Filament\Resources\Pages\EditRecord;

class EditAuditRequest extends EditRecord
{
    protected static string $resource = AuditRequestResource::class;
}
