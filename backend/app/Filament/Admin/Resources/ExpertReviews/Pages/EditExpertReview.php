<?php

namespace App\Filament\Admin\Resources\ExpertReviews\Pages;

use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditExpertReview extends EditRecord
{
    protected static string $resource = ExpertReviewResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]); // filled in by Task 9
    }
}
