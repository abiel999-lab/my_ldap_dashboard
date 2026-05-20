<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\QueueJobResource\Pages;
use App\Models\Operations\QueueJob;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class QueueJobResource extends Resource
{
    protected static ?string $model = QueueJob::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Queue Jobs';

    protected static ?string $modelLabel = 'Queue Job';

    protected static ?string $pluralModelLabel = 'Queue Jobs';

    protected static ?int $navigationSort = 40;

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
                Section::make('Queue Job')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('queue')->label('Queue')->badge(),
                        TextEntry::make('attempts')->label('Attempts'),
                        TextEntry::make('created_at_human')->label('Created At')->placeholder('N/A'),
                        TextEntry::make('available_at_human')->label('Available At')->placeholder('N/A'),
                        TextEntry::make('reserved_at_human')->label('Reserved At')->placeholder('Not reserved'),
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query)
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('queue')
                    ->label('Queue')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payload_preview')
                    ->label('Job')
                    ->searchable()
                    ->limit(70),

                TextColumn::make('attempts')
                    ->label('Attempts')
                    ->sortable(),

                TextColumn::make('created_at_human')
                    ->label('Created At'),

                TextColumn::make('available_at_human')
                    ->label('Available At'),

                TextColumn::make('reserved_at_human')
                    ->label('Reserved At')
                    ->placeholder('Not reserved'),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQueueJobs::route('/'),
            'view' => Pages\ViewQueueJob::route('/{record}'),
        ];
    }
}
