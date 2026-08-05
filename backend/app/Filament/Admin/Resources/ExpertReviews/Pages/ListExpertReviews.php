<?php

namespace App\Filament\Admin\Resources\ExpertReviews\Pages;

use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Filament\ListDefaults;
use Filament\Resources\Pages\ListRecords;

class ListExpertReviews extends ListRecords
{
    use ListDefaults;

    protected static string $resource = ExpertReviewResource::class;
}
