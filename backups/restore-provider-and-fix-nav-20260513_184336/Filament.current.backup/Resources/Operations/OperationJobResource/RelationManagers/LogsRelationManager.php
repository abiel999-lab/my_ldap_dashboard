<?php

namespace App\Filament\Resources\Operations\OperationJobResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Job Logs';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Log')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('operation_job_id')->label('Operation Job ID'),
                        TextEntry::make('operation_job_item_id')->label('Operation Job Item ID')->placeholder('N/A'),
                        TextEntry::make('level')->label('Level')->badge()->placeholder('info'),
                        TextEntry::make('message')->label('Message')->columnSpanFull(),
                        TextEntry::make('created_at')->label('Created At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),

                Section::make('Context')
                    ->schema([
                        TextEntry::make('context')
                            ->label('Context')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('operation_job_item_id')
                    ->label('Item ID')
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('message')
                    ->label('Message')
                    ->searchable()
                    ->limit(90),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
