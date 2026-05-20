<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Directory\LdapConnection;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LdapHealthWidget extends TableWidget
{
    protected static ?string $heading = 'LDAP Health Status';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LdapConnection::query()
                    ->latest('is_default')
                    ->latest('is_active')
                    ->orderBy('name')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Connection')
                    ->searchable()
                    ->weight('semibold'),

                TextColumn::make('environment_label')
                    ->label('Environment')
                    ->badge(),

                TextColumn::make('host')
                    ->label('Host'),

                TextColumn::make('port')
                    ->label('Port'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),

                TextColumn::make('last_health_status')
                    ->label('Health')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('Not checked'),

                TextColumn::make('last_health_checked_at')
                    ->label('Last Checked')
                    ->dateTime()
                    ->placeholder('Never'),

                TextColumn::make('last_health_message')
                    ->label('Message')
                    ->limit(65)
                    ->placeholder('No message'),
            ])
            ->paginated(false);
    }
}
