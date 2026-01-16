<?php

namespace App\Filament\Pages\Auth;


//use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
//use Filament\Schemas\Schema;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login;
//use Filament\Pages\Auth\Login;
use Illuminate\Support\Facades\Schema;
use SensitiveParameter;


class LoginPage extends Login
{

    public function form(Form $form): Form
    {
        return $form
            ->schema([

                TextInput::make('username')
                    ->label(__('Kullanıcı Adı'))
                    ->required()
                    ->autofocus()
                    ->autocomplete(),
                TextInput::make('password')
                    ->label(__('Parola'))
                    ->required()
                    ->password()
                    ->autocomplete(),
            ]);
    }

    //protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'samaccountname' => $data['username'],
            'password' => $data['password'],
        ];
    }


}
