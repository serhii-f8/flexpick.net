<?php

namespace App\Filament\Dashboard\Resources\PartnerOrderApprovals\Pages;

use App\Filament\Dashboard\Resources\PartnerOrderApprovals\PartnerOrderApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnerOrderApprovals extends ListRecords
{
    protected static string $resource = PartnerOrderApprovalResource::class;
}
