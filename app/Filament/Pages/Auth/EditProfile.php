<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

class EditProfile extends BaseEditProfile
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('ui.edit_profile');
    }

    public static function isSimple(): bool
    {
        return false;
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->disabled()
            ->label(__('ui.full_name'))
            ->required()
            ->maxLength(255)
            ->validationMessages([
                'required' => __('validation.required', ['attribute' => __('ui.full_name')]),
                'max' => __('validation.max.string', ['attribute' => __('ui.full_name'), 'max' => 255]),
            ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->disabled()
            ->label(__('ui.email'))
            ->email()
            ->required()
            ->maxLength(50)
            ->validationMessages([
                'required' => __('validation.required', ['attribute' => __('ui.email')]),
                'email' => __('validation.email', ['attribute' => __('ui.email')]),
                'max' => __('validation.max.string', ['attribute' => __('ui.email'), 'max' => 50]),
            ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('ui.password'))
            ->placeholder(__('ui.password_placeholder'))
            ->password()
            ->minLength(8)
            ->maxLength(255)
            ->dehydrated(fn ($state): bool => filled($state))
            ->validationMessages([
                'required' => __('validation.required', ['attribute' => __('ui.password')]),
                'min' => __('validation.min.string', ['attribute' => __('ui.password'), 'min' => 8]),
                'max' => __('validation.max.string', ['attribute' => __('ui.password'), 'max' => 255]),
            ]);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('password_confirmation')
            ->label(__('ui.password_confirmation'))
            ->placeholder(__('ui.password_confirmation_placeholder'))
            ->password()
            ->requiredWith('password')
            ->same('password')
            ->validationMessages([
                'required_with' => __('validation.required_with', ['attribute' => __('ui.password_confirmation'), 'values' => __('ui.password')]),
                'same' => __('validation.same', ['attribute' => __('ui.password_confirmation'), 'other' => __('ui.password')]),
            ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('ui.user_information'))
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                    ])
                    ->columns(2),
                Section::make(__('ui.password_change'))
                    ->heading(__('ui.set_new_password'))
                    ->description(__('ui.leave_blank_if_not_changing'))
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->columns(2),
            ]);
    }
}
