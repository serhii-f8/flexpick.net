<?php

namespace App\Mapper;

use App\Constants\OrderStatus;

class OrderStatusMapper
{
    public function mapForDisplay(string $status)
    {
        return match ($status) {
            OrderStatus::SUCCESS->value => __('Success'),
            OrderStatus::NEW->value => __('New'),
            OrderStatus::REFUNDED->value => __('Refunded'),
            OrderStatus::FAILED->value => __('Failed'),
            OrderStatus::REJECTED->value => __('Rejected'),
            default => __('Pending'),
        };
    }

    public function mapColor(string $status)
    {
        return match ($status) {
            OrderStatus::SUCCESS->value => 'success',
            OrderStatus::REJECTED->value => 'danger',
            default => 'warning',
        };
    }
}
