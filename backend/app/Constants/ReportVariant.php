<?php

namespace App\Constants;

/**
 * The two reports one audit produces: the business report for the owner and
 * the developer report for their engineer. One unlock opens both.
 */
enum ReportVariant: string
{
    case BUSINESS = 'business';
    case TECHNICAL = 'technical';

    public function routeName(): string
    {
        return match ($this) {
            self::BUSINESS => 'reports.view',
            self::TECHNICAL => 'reports.view.technical',
        };
    }

    public function sampleRouteName(): string
    {
        return match ($this) {
            self::BUSINESS => 'reports.sample',
            self::TECHNICAL => 'reports.sample.technical',
        };
    }

    public function webView(): string
    {
        return 'reports.'.$this->value.'-web';
    }

    public function pdfView(): string
    {
        return 'reports.'.$this->value.'-pdf';
    }

    /** Appended to the uuid in the stored PDF's path. */
    public function pdfSuffix(): string
    {
        return $this === self::BUSINESS ? '' : '-technical';
    }

    public function pdfFilename(): string
    {
        return match ($this) {
            self::BUSINESS => 'codebase-health-business.pdf',
            self::TECHNICAL => 'codebase-health-developer.pdf',
        };
    }

    public function tabLabel(): string
    {
        return match ($this) {
            self::BUSINESS => __('Business overview'),
            self::TECHNICAL => __('Developer report'),
        };
    }
}
