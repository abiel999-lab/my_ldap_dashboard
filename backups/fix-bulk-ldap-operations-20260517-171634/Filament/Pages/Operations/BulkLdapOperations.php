<?php

namespace App\Filament\Pages\Operations;

use App\Services\Ldap\BulkLdapOperationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class BulkLdapOperations extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = '2. OPERATIONS';
    protected static ?string $navigationLabel = 'Bulk LDAP Operations';
    protected static ?string $title = 'Bulk LDAP Operations';
    protected static ?int $navigationSort = 75;

    protected static string $view = 'filament.pages.operations.bulk-ldap-operations';

    public ?array $data = [];
    public array $previewEntries = [];
    public ?string $previewMessage = null;

    public function mount(): void
    {
        $this->form->fill([
            'operation_name' => null,
            'ldap_connection_name' => 'Petra LDAP',
            'base_dn' => 'dc=petra,dc=ac,dc=id',
            'search_scope' => 'subtree',
            'ldap_filter' => '(objectClass=*)',
            'size_limit' => 100,
            'operation_type' => 'add_attribute',
            'objectclass_name' => null,
            'attribute_name' => null,
            'attribute_value' => null,
            'target_ou_dn' => null,
            'existing_value_behavior' => 'skip',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Source')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('operation_name')
                                ->label('Operation Name')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('ldap_connection_name')
                                ->label('LDAP Connection')
                                ->required()
                                ->helperText('Batch 1 pakai nama connection dulu. Batch berikutnya kita sambungkan ke tabel LDAP Connections yang sudah ada.'),
                        ]),

                        TextInput::make('base_dn')
                            ->label('Base DN')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Target Filter')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('search_scope')
                                ->label('Search Scope')
                                ->options([
                                    'base' => 'Base DN only',
                                    'one' => 'One level',
                                    'subtree' => 'Full subtree',
                                ])
                                ->required(),

                            TextInput::make('size_limit')
                                ->label('Size Limit')
                                ->numeric()
                                ->required()
                                ->default(100),
                        ]),

                        TextInput::make('ldap_filter')
                            ->label('LDAP Filter')
                            ->required()
                            ->placeholder('(&(objectClass=inetOrgPerson)(uid=*))')
                            ->columnSpanFull(),
                    ]),

                Section::make('Operation')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('operation_type')
                                ->label('Operation Type')
                                ->options([
                                    'add_objectclass' => 'Add ObjectClass',
                                    'delete_objectclass' => 'Delete ObjectClass',
                                    'add_attribute' => 'Add Attribute',
                                    'delete_attribute' => 'Delete Attribute',
                                    'move_ou' => 'Move to OU',
                                    'delete_entry' => 'Delete Entry',
                                ])
                                ->required()
                                ->live(),

                            Select::make('existing_value_behavior')
                                ->label('If Attribute Exists')
                                ->options([
                                    'skip' => 'Skip',
                                    'replace' => 'Replace',
                                    'append' => 'Append',
                                ])
                                ->default('skip')
                                ->visible(fn ($get) => $get('operation_type') === 'add_attribute'),
                        ]),

                        Grid::make(2)->schema([
                            TextInput::make('objectclass_name')
                                ->label('ObjectClass Name')
                                ->placeholder('inetOrgPerson / posixAccount / customObjectClass')
                                ->visible(fn ($get) => in_array($get('operation_type'), [
                                    'add_objectclass',
                                    'delete_objectclass',
                                ], true)),

                            TextInput::make('attribute_name')
                                ->label('Attribute Name')
                                ->placeholder('mail / telephoneNumber / description')
                                ->visible(fn ($get) => in_array($get('operation_type'), [
                                    'add_attribute',
                                    'delete_attribute',
                                ], true)),

                            Textarea::make('attribute_value')
                                ->label('Attribute Value')
                                ->rows(3)
                                ->visible(fn ($get) => $get('operation_type') === 'add_attribute'),

                            TextInput::make('target_ou_dn')
                                ->label('Target OU DN')
                                ->placeholder('ou=alumni,ou=people,dc=petra,dc=ac,dc=id')
                                ->visible(fn ($get) => $get('operation_type') === 'move_ou'),
                        ]),
                    ]),

                Section::make('Preview Result')
                    ->schema([
                        Placeholder::make('preview_output')
                            ->label('')
                            ->content(function () {
                                if (empty($this->previewEntries)) {
                                    return new HtmlString('<div style="opacity:.7">Belum ada preview. Klik Preview dulu.</div>');
                                }

                                $html = '<div style="display:flex;flex-direction:column;gap:8px">';
                                $html .= '<div><strong>' . e($this->previewMessage ?? 'Preview ready') . '</strong></div>';

                                foreach ($this->previewEntries as $entry) {
                                    $html .= '<div style="padding:10px;border:1px solid rgba(148,163,184,.25);border-radius:10px">';
                                    $html .= '<div><strong>DN:</strong> ' . e($entry['dn'] ?? '-') . '</div>';
                                    $html .= '<div><strong>Status:</strong> ' . e($entry['status'] ?? '-') . '</div>';
                                    $html .= '<div><strong>Action:</strong> ' . e($entry['planned_action'] ?? '-') . '</div>';
                                    $html .= '<div><strong>Reason:</strong> ' . e($entry['reason'] ?? '-') . '</div>';
                                    $html .= '</div>';
                                }

                                $html .= '</div>';

                                return new HtmlString($html);
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->color('gray')
                ->icon('heroicon-o-eye')
                ->action('preview'),

            Action::make('execute')
                ->label('Execute')
                ->color('danger')
                ->icon('heroicon-o-bolt')
                ->requiresConfirmation()
                ->modalHeading('Execute Bulk LDAP Operation?')
                ->modalDescription('Batch 1 masih mode aman. Perubahan LDAP asli belum dilakukan, hanya simpan log skipped.')
                ->action('execute'),
        ];
    }

    public function preview(): void
    {
        $state = $this->form->getState();

        $result = app(BulkLdapOperationService::class)->preview($state);

        if (! $result['ok']) {
            Notification::make()
                ->title('Preview failed')
                ->body($result['message'])
                ->danger()
                ->send();

            return;
        }

        $this->previewEntries = $result['entries'] ?? [];
        $this->previewMessage = $result['message'] ?? 'Preview ready.';

        Notification::make()
            ->title('Preview ready')
            ->body($this->previewMessage)
            ->success()
            ->send();
    }

    public function execute(): void
    {
        if (empty($this->previewEntries)) {
            Notification::make()
                ->title('Preview required')
                ->body('Klik Preview dulu sebelum Execute.')
                ->warning()
                ->send();

            return;
        }

        $state = $this->form->getState();

        $result = app(BulkLdapOperationService::class)->execute($state, $this->previewEntries);

        Notification::make()
            ->title('Execution finished')
            ->body($result['message'])
            ->success()
            ->send();
    }
}
