<?php

namespace App\Filament\Resources;

use App\Enums\FormFieldTypeEnum;
use App\Enums\LocationRequirementEnum;
use App\Filament\Resources\TicketTypeResource\Pages;
use App\Models\TicketType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TicketTypeResource extends Resource
{
    protected static ?string $model = TicketType::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 10;

    /**
     * Icons offered for a ticket type (shown on the type picker cards).
     *
     * @var array<string, string>
     */
    public const ICONS = [
        'heroicon-o-wrench-screwdriver' => 'Arıza / Bakım',
        'heroicon-o-computer-desktop' => 'Bilgi Teknolojileri',
        'heroicon-o-bolt' => 'Elektrik',
        'heroicon-o-cog-6-tooth' => 'Mekanik',
        'heroicon-o-sparkles' => 'Temizlik',
        'heroicon-o-shopping-cart' => 'Satın Alma',
        'heroicon-o-building-office-2' => 'Bina / Tesis',
        'heroicon-o-truck' => 'Lojistik',
        'heroicon-o-shield-check' => 'Güvenlik',
        'heroicon-o-question-mark-circle' => 'Diğer',
    ];

    /**
     * @var array<string, string>
     */
    public const COLORS = [
        'primary' => 'Mavi',
        'success' => 'Yeşil',
        'warning' => 'Turuncu',
        'danger' => 'Kırmızı',
        'info' => 'Açık Mavi',
        'gray' => 'Gri',
    ];

    public static function getModelLabel(): string
    {
        return __('ui.ticket_type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.ticket_types');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.request_settings');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('ui.ticket_type_information'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('ui.name'))
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->validationMessages(['required' => __('ui.required')]),

                        Forms\Components\Select::make('location_requirement')
                            ->label(__('ui.location_requirement'))
                            ->options(LocationRequirementEnum::class)
                            ->default(LocationRequirementEnum::Optional->value)
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('resolve_location_requirement')
                            ->label(__('ui.resolve_location_requirement'))
                            ->helperText(__('ui.resolve_location_requirement_help'))
                            ->options(LocationRequirementEnum::class)
                            ->default(LocationRequirementEnum::Hidden->value)
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('icon')
                            ->label(__('ui.icon'))
                            ->options(self::ICONS)
                            ->default('heroicon-o-wrench-screwdriver')
                            ->native(false),

                        Forms\Components\Select::make('color')
                            ->label(__('ui.color'))
                            ->options(self::COLORS)
                            ->default('primary')
                            ->native(false),

                        Forms\Components\Textarea::make('description')
                            ->label(__('ui.description'))
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('sort_order')
                            ->label(__('ui.sort_order'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('ui.active'))
                            ->default(true)
                            ->inline(false),

                        Forms\Components\Toggle::make('cc_required')
                            ->label(__('ui.cc_required'))
                            ->helperText(__('ui.cc_required_help'))
                            ->default(false)
                            ->inline(false),
                    ]),

                Forms\Components\Section::make(__('ui.form_fields'))
                    ->description(__('ui.form_fields_help'))
                    ->icon('heroicon-o-queue-list')
                    ->schema([
                        Forms\Components\Repeater::make('form_fields')
                            ->hiddenLabel()
                            ->addActionLabel(__('ui.add_form_field'))
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->columns(3)
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label(__('ui.form_field_label'))
                                    ->required()
                                    ->maxLength(100)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                        if (blank($get('key')) && filled($state)) {
                                            $set('key', Str::slug(Str::transliterate($state), '_'));
                                        }
                                    }),

                                Forms\Components\TextInput::make('key')
                                    ->label(__('ui.form_field_key'))
                                    ->helperText(__('ui.form_field_key_help'))
                                    ->required()
                                    ->maxLength(50)
                                    ->regex('/^[a-z][a-z0-9_]*$/')
                                    ->distinct(),

                                Forms\Components\Select::make('type')
                                    ->label(__('ui.form_field_type'))
                                    ->options(FormFieldTypeEnum::class)
                                    ->default(FormFieldTypeEnum::Text->value)
                                    ->required()
                                    ->live()
                                    ->native(false),

                                Forms\Components\TagsInput::make('options')
                                    ->label(__('ui.form_field_options'))
                                    ->visible(fn (Get $get): bool => $get('type') === FormFieldTypeEnum::Select->value)
                                    ->required(fn (Get $get): bool => $get('type') === FormFieldTypeEnum::Select->value)
                                    ->live()
                                    ->columnSpan(2),

                                Forms\Components\CheckboxList::make('cc_required_options')
                                    ->label(__('ui.form_field_cc_required_options'))
                                    ->helperText(__('ui.form_field_cc_required_options_help'))
                                    ->options(fn (Get $get): array => collect($get('options') ?? [])->mapWithKeys(fn ($option): array => [$option => $option])->all())
                                    ->visible(fn (Get $get): bool => $get('type') === FormFieldTypeEnum::Select->value && filled($get('options')))
                                    ->columns(3)
                                    ->columnSpanFull(),

                                Forms\Components\Toggle::make('required')
                                    ->label(__('ui.form_field_required'))
                                    ->default(false)
                                    ->inline(false),

                                Forms\Components\TextInput::make('help')
                                    ->label(__('ui.form_field_help'))
                                    ->maxLength(200)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('teams'))
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->icon(fn (TicketType $record): string => $record->icon ?: 'heroicon-o-rectangle-stack')
                    ->iconColor(fn (TicketType $record): string => $record->color ?: 'primary')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('location_requirement')
                    ->label(__('ui.location_requirement'))
                    ->badge(),

                Tables\Columns\TextColumn::make('form_fields')
                    ->label(__('ui.form_fields'))
                    ->state(fn (TicketType $record): int => count($record->form_fields ?? []))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('teams_count')
                    ->label(__('ui.teams_count'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('ui.active'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('ui.sort_order'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('ui.active')),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * A type that already has teams or tickets is deactivated, never deleted.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record)
            && ! $record->teams()->exists()
            && ! $record->tickets()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTicketTypes::route('/'),
            'create' => Pages\CreateTicketType::route('/create'),
            'view' => Pages\ViewTicketType::route('/{record}'),
            'edit' => Pages\EditTicketType::route('/{record}/edit'),
        ];
    }
}
