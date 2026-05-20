<?php

namespace App\Filament\Pages\Observability;

use App\Services\Audit\AuditLogger;
use App\Services\Observability\SystemLogService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use UnitEnum;

class SystemLogs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = '5. Observability';

    protected static ?string $navigationLabel = 'System Logs';

    protected static ?string $title = 'System Logs';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.observability.system-logs';

    public ?string $selectedLog = 'laravel';

    public ?string $logContent = null;

    public array $logRows = [];

    public function mount(): void
    {
        $this->refreshRows();
        $this->loadSelectedLog();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Log Reader')
                    ->description('Read system logs without opening terminal. Use carefully because logs may contain technical error details.')
                    ->schema([
                        Select::make('selectedLog')
                            ->label('Log File')
                            ->options(function (): array {
                                return collect(app(SystemLogService::class)->list())
                                    ->mapWithKeys(fn (array $row): array => [
                                        $row['key'] => $row['name'].' — '.$row['status'].' — '.$row['size_mb'].' MB',
                                    ])
                                    ->all();
                            })
                            ->live()
                            ->afterStateUpdated(function (): void {
                                $this->loadSelectedLog();
                            }),

                        Textarea::make('logContent')
                            ->label('Last Log Lines')
                            ->rows(24)
                            ->readOnly()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshLog')
                ->label('Refresh Log')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function (): void {
                    $this->refreshRows();
                    $this->loadSelectedLog();

                    Notification::make()
                        ->title('Log refreshed')
                        ->success()
                        ->send();
                }),

            Action::make('clearSelectedLog')
                ->label('Clear Selected Log')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Clear selected log file?')
                ->modalDescription('This will empty the selected log file. It does not delete the log file itself.')
                ->action(function (): void {
                    if (! $this->selectedLog) {
                        return;
                    }

                    $result = app(SystemLogService::class)->clear($this->selectedLog);

                    app(AuditLogger::class)->log([
                        'module' => 'observability.system_logs',
                        'action' => 'clear_log',
                        'status' => $result['ok'] ? 'success' : 'failed',
                        'target_type' => 'system_log_file',
                        'target_key' => $this->selectedLog,
                        'request_payload' => [
                            'selected_log' => $this->selectedLog,
                            'path' => $result['path'] ?? null,
                        ],
                        'before_value' => [
                            'size_before_bytes' => $result['size_before_bytes'] ?? null,
                        ],
                        'after_value' => [
                            'size_after_bytes' => $result['size_after_bytes'] ?? null,
                        ],
                        'error_message' => $result['ok'] ? null : $result['message'],
                    ]);

                    $this->refreshRows();
                    $this->loadSelectedLog();

                    Notification::make()
                        ->title($result['ok'] ? 'Log cleared' : 'Failed to clear log')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }

    public function refreshRows(): void
    {
        $this->logRows = app(SystemLogService::class)->list();
    }

    public function loadSelectedLog(): void
    {
        $this->logContent = app(SystemLogService::class)->tail((string) $this->selectedLog, 150);
    }
}
