<?php

namespace App\Filament\Resources\Observability;

use App\Filament\Resources\Observability\FailedQueueJobResource\Pages;
use App\Models\Operations\FailedQueueJob;
use App\Services\Audit\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;
use Throwable;

class FailedQueueJobResource extends Resource
{
    protected static ?string $model = FailedQueueJob::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = '3. OBSERVABILITY';

    protected static ?string $navigationLabel = 'Failed Jobs';

    protected static ?string $modelLabel = 'Failed Job';

    protected static ?string $pluralModelLabel = 'Failed Jobs';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Failed Job')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('connection')->label('Connection')->badge(),
                        TextEntry::make('queue')->label('Queue')->badge(),
                        TextEntry::make('failed_at')->label('Failed At')->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Payload')
                    ->schema([
                        TextEntry::make('payload_preview')
                            ->label('Preview')
                            ->columnSpanFull(),

                        TextEntry::make('payload')
                            ->label('Raw Payload')
                            ->formatStateUsing(function ($state): string {
                                $decoded = json_decode((string) $state, true);

                                return json_encode($decoded ?: $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A';
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Exception')
                    ->schema([
                        TextEntry::make('exception')
                            ->label('Exception')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([

                \Filament\Actions\Action::make('clearAllLogs')
                    ->label('Clear All Logs')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Clear all failed jobs?')
                    ->modalDescription('Semua failed queue jobs akan dihapus.')
                    ->action(function (): void {
                        $modelClass = static::getModel();
                        $count = $modelClass::query()->count();
                        $modelClass::query()->delete();

                        \Filament\Notifications\Notification::make()
                            ->title('Failed jobs cleared')
                            ->body('Deleted rows: '.$count)
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([

                \Filament\Actions\BulkAction::make('deleteSelectedLogs')
                    ->label('Delete Selected Logs')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records): void {
                        $count = $records->count();

                        foreach ($records as $record) {
                            $record->delete();
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Selected failed jobs deleted')
                            ->body('Deleted rows: '.$count)
                            ->success()
                            ->send();
                    }),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query)
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('uuid')
                    ->label('UUID')
                    ->searchable()
                    ->limit(18),

                TextColumn::make('connection')
                    ->label('Connection')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('queue')
                    ->label('Queue')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payload_preview')
                    ->label('Job')
                    ->searchable()
                    ->limit(55),

                TextColumn::make('exception_preview')
                    ->label('Exception')
                    ->limit(70),

                TextColumn::make('failed_at')
                    ->label('Failed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),

                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Retry failed job?')
                    ->modalDescription('This will ask Laravel queue to retry this failed job. Make sure a queue worker is running.')
                    ->action(function (FailedQueueJob $record): void {
                        try {
                            Artisan::call('queue:retry', [
                                'id' => [$record->uuid],
                            ]);

                            app(AuditLogger::class)->log([
                                'module' => 'operations.queue',
                                'action' => 'retry_failed_job',
                                'status' => 'success',
                                'target_type' => FailedQueueJob::class,
                                'target_key' => (string) $record->id,
                                'request_payload' => [
                                    'uuid' => $record->uuid,
                                    'queue' => $record->queue,
                                ],
                                'stdout' => Artisan::output(),
                            ]);

                            Notification::make()
                                ->title('Failed job retry requested')
                                ->body('Laravel queue retry was executed for UUID: '.$record->uuid)
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            app(AuditLogger::class)->log([
                                'module' => 'operations.queue',
                                'action' => 'retry_failed_job',
                                'status' => 'failed',
                                'target_type' => FailedQueueJob::class,
                                'target_key' => (string) $record->id,
                                'request_payload' => [
                                    'uuid' => $record->uuid,
                                    'queue' => $record->queue,
                                ],
                                'error_message' => $exception->getMessage(),
                            ]);

                            Notification::make()
                                ->title('Failed job retry failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('forget')
                    ->label('Forget')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Forget failed job?')
                    ->modalDescription('This deletes the failed job record from failed_jobs. It does not fix the original cause.')
                    ->action(function (FailedQueueJob $record): void {
                        $snapshot = $record->toArray();

                        try {
                            Artisan::call('queue:forget', [
                                'id' => $record->uuid,
                            ]);

                            app(AuditLogger::class)->log([
                                'module' => 'operations.queue',
                                'action' => 'forget_failed_job',
                                'status' => 'success',
                                'target_type' => FailedQueueJob::class,
                                'target_key' => (string) $snapshot['id'],
                                'before_value' => $snapshot,
                                'stdout' => Artisan::output(),
                            ]);

                            Notification::make()
                                ->title('Failed job forgotten')
                                ->body('The failed job record was removed.')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            app(AuditLogger::class)->log([
                                'module' => 'operations.queue',
                                'action' => 'forget_failed_job',
                                'status' => 'failed',
                                'target_type' => FailedQueueJob::class,
                                'target_key' => (string) $snapshot['id'],
                                'before_value' => $snapshot,
                                'error_message' => $exception->getMessage(),
                            ]);

                            Notification::make()
                                ->title('Forget failed job failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailedQueueJobs::route('/'),
            'view' => Pages\ViewFailedQueueJob::route('/{record}'),
        ];
    }
}
