<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs;

use App\Filament\Admin\Resources\AuditEmailLogs\Pages\ListAuditEmailLogs;
use App\Mail\StoredAuditEmail;
use App\Models\AuditEmailLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

class AuditEmailLogResource extends Resource
{
    protected static ?string $model = AuditEmailLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    public static function getNavigationGroup(): ?string
    {
        return __('Audits');
    }

    public static function getModelLabel(): string
    {
        return __('Audit Email');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recipient')->searchable(),
                TextColumn::make('mailable')->label(__('Notification'))->badge()->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AuditEmailLog::STATUS_DELIVERED => 'success',
                        AuditEmailLog::STATUS_SENT => 'info',
                        AuditEmailLog::STATUS_PENDING => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('attempts'),
                TextColumn::make('sent_at')->label(__('Last attempt'))->dateTime(config('app.datetime_format'))->sortable(),
                TextColumn::make('last_error')->limit(60)->placeholder('—')->tooltip(fn (AuditEmailLog $record): ?string => $record->last_error),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    AuditEmailLog::STATUS_PENDING => __('Pending'),
                    AuditEmailLog::STATUS_SENT => __('Sent'),
                    AuditEmailLog::STATUS_DELIVERED => __('Delivered'),
                    AuditEmailLog::STATUS_BOUNCED => __('Bounced'),
                    AuditEmailLog::STATUS_FAILED => __('Failed'),
                ]),
                SelectFilter::make('mailable')
                    ->options(fn (): array => AuditEmailLog::query()->distinct()->pluck('mailable', 'mailable')->all()),
            ])
            ->recordActions([
                Action::make('resend')
                    ->label(__('Resend'))
                    ->visible(fn (AuditEmailLog $record): bool => $record->body !== '')
                    ->requiresConfirmation()
                    ->modalDescription(fn (AuditEmailLog $record): string => __('This email was last sent to :recipient on :date. Sending again may duplicate it in their inbox.', [
                        'recipient' => $record->recipient,
                        'date' => $record->sent_at?->format(config('app.datetime_format')) ?? __('unknown'),
                    ]))
                    ->action(function (AuditEmailLog $record): void {
                        Mail::to($record->recipient)->send(
                            new StoredAuditEmail($record->subject, $record->body)
                        );

                        $record->update([
                            'attempts' => $record->attempts + 1,
                            'sent_at' => now(),
                            'status' => AuditEmailLog::STATUS_SENT,
                        ]);

                        Notification::make()->title(__('Email resent'))->success()->send();
                    }),
            ])
            ->defaultSort('sent_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEmailLogs::route('/'),
        ];
    }
}
