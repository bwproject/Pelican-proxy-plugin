<?php

namespace BWProject\BWProxy;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BWProxyPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'bwproxy';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'mode' => config('bwproxy.mode', 'off'),
            'socks5_host' => config('bwproxy.socks5_host', ''),
            'socks5_port' => config('bwproxy.socks5_port', 1080),
            'socks5_username' => config('bwproxy.socks5_username', ''),
            'socks5_password' => config('bwproxy.socks5_password', ''),
            'awg_interface' => config('bwproxy.awg_interface', 'awg0'),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Режим исходящего трафика')
                ->description('Эти настройки сохраняются в .env панели. Сами по себе они не меняют маршрутизацию Docker-контейнеров: для этого требуется настройка узла Pelican/Wings.')
                ->schema([
                    Select::make('mode')
                        ->label('Режим')
                        ->options([
                            'off' => 'Отключено',
                            'socks5' => 'SOCKS5 с логином и паролем',
                            'amneziawg' => 'AmneziaWG (маршрутизация на узле)',
                        ])
                        ->required()
                        ->default('off'),
                ]),
            Section::make('SOCKS5')
                ->description('Используется только приложениями, которые умеют работать через SOCKS5, либо настроенным proxychains-wrapper.')
                ->schema([
                    TextInput::make('socks5_host')
                        ->label('Адрес прокси')
                        ->placeholder('proxy.example.com или IP')
                        ->maxLength(255)
                        ->visible(fn ($get) => $get('mode') === 'socks5'),
                    TextInput::make('socks5_port')
                        ->label('Порт')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->required(fn ($get) => $get('mode') === 'socks5')
                        ->visible(fn ($get) => $get('mode') === 'socks5'),
                    TextInput::make('socks5_username')
                        ->label('Логин')
                        ->maxLength(255)
                        ->visible(fn ($get) => $get('mode') === 'socks5'),
                    TextInput::make('socks5_password')
                        ->label('Пароль')
                        ->password()
                        ->revealable()
                        ->maxLength(1024)
                        ->visible(fn ($get) => $get('mode') === 'socks5'),
                ]),
            Section::make('AmneziaWG')
                ->description('Укажите уже созданный и поднятый интерфейс AmneziaWG на узле, например awg0. Плагин не создаёт VPN-туннель и не меняет системные маршруты.')
                ->schema([
                    TextInput::make('awg_interface')
                        ->label('Имя интерфейса')
                        ->required(fn ($get) => $get('mode') === 'amneziawg')
                        ->default('awg0')
                        ->maxLength(32)
                        ->visible(fn ($get) => $get('mode') === 'amneziawg'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $allowed = ['mode', 'socks5_host', 'socks5_port', 'socks5_username', 'socks5_password', 'awg_interface'];
        $data = Arr::only($data, $allowed);

        if (! in_array($data['mode'] ?? 'off', ['off', 'socks5', 'amneziawg'], true)) {
            Notification::make()->title('Неизвестный режим прокси')->danger()->send();
            return;
        }

        if (($data['mode'] ?? 'off') === 'socks5') {
            $port = filter_var($data['socks5_port'] ?? null, FILTER_VALIDATE_INT);
            if (! is_string($data['socks5_host'] ?? null) || trim($data['socks5_host']) === '' || $port === false || $port < 1 || $port > 65535) {
                Notification::make()->title('Укажите адрес SOCKS5 и корректный порт')->danger()->send();
                return;
            }
        }

        if (($data['mode'] ?? 'off') === 'amneziawg') {
            $interface = (string) ($data['awg_interface'] ?? 'awg0');
            if (! preg_match('/^[a-zA-Z0-9_.-]{1,32}$/', $interface)) {
                Notification::make()->title('Некорректное имя интерфейса AmneziaWG')->danger()->send();
                return;
            }
        }

        $env = [];
        foreach ($data as $key => $value) {
            $env['BWPROXY_' . Str::upper($key)] = is_string($value) ? trim($value) : $value;
        }
        $this->writeToEnvironment($env);

        Notification::make()
            ->title('Настройки BW Proxy сохранены')
            ->body('Для применения к игровым контейнерам отдельно настройте маршрутизацию на узле Pelican/Wings.')
            ->success()
            ->send();
    }
}
