<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\BulkLdapOperationResource\Pages;
use App\Models\Directory\LdapConnection;
use App\Models\Operations\BulkLdapOperation;
use App\Services\Operations\BulkLdapUserOperationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;
use Throwable;

class BulkLdapOperationResource extends Resource
{
    protected static ?string $model = BulkLdapOperation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    
    protected static bool $shouldRegisterNavigation = false;
protected static string|UnitEnum|null $navigationGroup = '2. OPERATIONS';

    protected static ?string $navigationLabel = 'Bulk LDAP Operations';

    protected static ?string $modelLabel = 'Bulk LDAP Operation';

    protected static ?string $pluralModelLabel = 'Bulk LDAP Operations';

    protected static ?int $navigationSort = 46;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bulk Operation')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->default('Bulk Create 1000 Test Users'),

                        Select::make('ldap_connection_id')
                            ->label('LDAP Connection')
                            ->required()
                            ->options(fn (): array => LdapConnection::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live(),

                        Select::make('operation_type')
                            ->label('Operation Type')
                            ->required()
                            ->options([
                                'bulk_create_test_users' => 'Bulk Create Test Users',
                                'bulk_delete_test_users' => 'Bulk Delete Test Users',
                            ])
                            ->default('bulk_create_test_users')
                            ->helperText('Create = add generated users. Delete = delete generated users by UID prefix inside Target OU.'),

                        TextInput::make('target_ou_dn')
                            ->label('Target OU DN')
                            ->required()
                            ->default(function (): string {
                                $connection = LdapConnection::query()->where('is_default', true)->first() ?: LdapConnection::query()->first();

                                return $connection ? 'ou=people,'.$connection->base_dn : 'ou=people,dc=petra,dc=ac,dc=id';
                            })
                            ->columnSpanFull(),

                        TextInput::make('uid_prefix')
                            ->label('UID Prefix')
                            ->required()
                            ->default('testuser'),

                        TextInput::make('start_number')
                            ->label('Start Number')
                            ->numeric()
                            ->required()
                            ->default(1),

                        TextInput::make('user_count')
                            ->label('User Count')
                            ->numeric()
                            ->required()
                            ->default(1000)
                            ->helperText('Start with 100 or 1000 first. Max guard is 10000.'),

                        TextInput::make('number_padding')
                            ->label('Number Padding')
                            ->numeric()
                            ->required()
                            ->default(4),

                        TextInput::make('email_domain')
                            ->label('Email Domain')
                            ->required()
                            ->default('petra.ac.id'),

                        TextInput::make('display_name_prefix')
                            ->label('Display Name Prefix')
                            ->required()
                            ->default('Bulk Test User'),

                        Toggle::make('safe_mode')
                            ->label('Safe Mode')
                            ->default(true)
                            ->disabled(),

                        Toggle::make('approval_required')
                            ->label('Approval Required')
                            ->default(true),

                        KeyValue::make('default_attributes')
                            ->label('Extra Default Attributes')
                            ->keyLabel('Attribute')
                            ->valueLabel('Value')
                            ->columnSpanFull(),

                        KeyValue::make('metadata')
                            ->label('Metadata')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Operation')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('operation_type')->label('Type')->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'draft' => 'gray',
                                'previewed', 'success' => 'success',
                                'queued', 'running', 'partial_success' => 'warning',
                                'failed', 'preview_failed' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('ldapConnection.name')->label('LDAP Connection')->placeholder('N/A'),
                        TextEntry::make('target_ou_dn')->label('Target OU DN')->columnSpanFull(),
                        TextEntry::make('uid_prefix')->label('UID Prefix'),
                        TextEntry::make('user_count')->label('User Count'),
                        TextEntry::make('counter_summary')->label('Counter Summary')->columnSpanFull(),
                        TextEntry::make('message')->label('Message')->columnSpanFull()->placeholder('N/A'),
                        TextEntry::make('error_message')->label('Error')->columnSpanFull()->placeholder('N/A'),
                    ])
                    ->columns(3),

                Section::make('Safety')
                    ->schema([
                        IconEntry::make('safe_mode')->label('Safe')->boolean(),
                        IconEntry::make('dry_run')->label('Dry')->boolean(),
                        IconEntry::make('destructive')->label('Danger')->boolean(),
                        IconEntry::make('approval_required')->label('Approval Required')->boolean(),
                    ])
                    ->columns(4),

                Section::make('Counters')
                    ->schema([
                        TextEntry::make('total_items')->label('Total'),
                        TextEntry::make('pending_items')->label('Pending'),
                        TextEntry::make('running_items')->label('Running'),
                        TextEntry::make('success_items')->label('Success'),
                        TextEntry::make('failed_items')->label('Failed'),
                        TextEntry::make('already_applied_items')->label('Already Applied'),
                        TextEntry::make('conflict_items')->label('Conflict'),
                        TextEntry::make('processed_items')->label('Processed'),
                    ])
                    ->columns(4),

                Section::make('Timeline / Links')
                    ->schema([
                        TextEntry::make('operation_job_id')->label('Operation Job ID')->placeholder('N/A'),
                        TextEntry::make('previewed_at')->label('Previewed At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('queued_at')->label('Queued At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('started_at')->label('Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('failed_at')->label('Failed At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->limit(36),

                TextColumn::make('operation_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'previewed', 'success' => 'success',
                        'queued', 'running', 'partial_success' => 'warning',
                        'failed', 'preview_failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('ldapConnection.name')
                    ->label('Connection')
                    ->limit(20),

                TextColumn::make('target_ou_dn')
                    ->label('Target OU')
                    ->searchable()
                    ->limit(42),

                TextColumn::make('total_items')->label('Total')->sortable(),
                TextColumn::make('success_items')->label('Success')->sortable(),
                TextColumn::make('failed_items')->label('Failed')->sortable(),
                TextColumn::make('already_applied_items')->label('Already')->sortable(),

                IconColumn::make('safe_mode')->label('Safe')->boolean(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'previewed' => 'Previewed',
                        'queued' => 'Queued',
                        'running' => 'Running',
                        'success' => 'Success',
                        'partial_success' => 'Partial Success',
                        'failed' => 'Failed',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn (BulkLdapOperation $record): bool => in_array($record->status, ['draft', 'previewed'], true) && (int) $record->processed_items === 0)
                    ->requiresConfirmation()
                    ->modalHeading('Generate bulk LDAP preview?')
                    ->modalDescription('This generates item rows only. LDAP data will not be changed.')
                    ->action(function (BulkLdapOperation $record): void {
                        try {
                            $result = app(BulkLdapUserOperationService::class)->previewCreateTestUsers($record->fresh());

                            Notification::make()
                                ->title($result['ok'] ? 'Preview generated' : 'Preview rejected')
                                ->body($result['message'])
                                ->color($result['ok'] ? 'success' : 'warning')
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Preview failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('queueApply')
                    ->label('Queue Apply')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (BulkLdapOperation $record): bool => $record->status === 'previewed' && (int) $record->processed_items === 0)
                    ->requiresConfirmation()
                    ->modalHeading('Queue bulk LDAP operation?')
                    ->modalDescription('This will run the selected bulk operation through Laravel Queue. This can only be used once for a fresh preview.')
                    ->modalSubmitActionLabel('Queue Apply')
                    ->action(function (BulkLdapOperation $record): void {
                        try {
                            $result = app(BulkLdapUserOperationService::class)->queueApply($record->fresh(), false);

                            Notification::make()
                                ->title($result['ok'] ? 'Bulk operation queued' : 'Queue rejected')
                                ->body($result['message'])
                                ->color($result['ok'] ? 'success' : 'warning')
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Queue failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('retryFailed')
                    ->label('Retry Failed')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (BulkLdapOperation $record): bool => in_array($record->status, ['failed', 'partial_success'], true) && ($record->failed_items > 0 || $record->pending_items > 0))
                    ->requiresConfirmation()
                    ->modalHeading('Retry failed/pending bulk LDAP items?')
                    ->modalDescription('Success and already_applied items will not be executed again.')
                    ->modalSubmitActionLabel('Retry Failed Only')
                    ->action(function (BulkLdapOperation $record): void {
                        try {
                            $result = app(BulkLdapUserOperationService::class)->queueApply($record->fresh(), true);

                            Notification::make()
                                ->title($result['ok'] ? 'Retry queued' : 'Retry rejected')
                                ->body($result['message'])
                                ->color($result['ok'] ? 'success' : 'warning')
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Retry failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBulkLdapOperations::route('/'),
            'create' => Pages\CreateBulkLdapOperation::route('/create'),
            'view' => Pages\ViewBulkLdapOperation::route('/{record}'),
            'edit' => Pages\EditBulkLdapOperation::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

}
