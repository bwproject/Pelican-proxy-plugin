<?php

namespace BWProject\BWProxy\Filament\Server\Pages;

use BWProject\BWProxy\Models\ServerProxySetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BWProxySettings extends Page
{
    use InteractsWithSchemas;

    protected static ?string $slug = 'bwproxy';
    protected static string|null \BackedEnum $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Прокси / VPN';
    protected static ?string $title = 'Прокси / VPN сервера';
    protected string $view = 'bwproxy::filament.server.pages.bwproxy-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $server = filament()->getTenant();
        abort_unless($server && isset($server->uuid, $server->node_id), 404);

        $setting = ServerProxySetting::query()->where('server_uuid', $server->uuid)->first();
        $this->form->fill([
            'mode' => $setting?->mode ?? 'off',
            'socks5_host' => $setting?->socks5_host ?? '',
            'socks5_port' => $setting?->socks5_port ?? 1080,
            'socks5_username' => $setting?->socks5_username ?? '',
            'socks5_password' => '',
            'awg_interface' => $setting?->awg_interface ?? 'awg0',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Режим маршрутизации')
                    ->description('Настройки применяются узловым BW Proxy Agent после опроса панели. Изменение режима может оборвать текущие соединения.')
                    ->schema([
                        Select::make('mode')
                            ->label('Исходящий трафик')
                            ->options([
                                'off' => 'Отключено',
                                'socks5' => 'SOCKS5 (только TCP)',
                                'amneziawg' => 'AmneziaWG (TCP и UDP)',
                            ])
                            ->required()
                            ->live(),
                        Placeholder::make('server_info')
                            ->label('Сервер')
                            ->content(fn () => (string) (filament()->getTenant()?->uuid ?? 'Не определён')),
                    ]),
                Section::make('SOCKS5')
                    ->description('Пароль хранится в БД в зашифрованном виде. Оставьте поле пароля пустым, чтобы сохранить текущий пароль.')
                    ->schema([
                        TextInput::make('socks5_host')->label('Хост')->maxLength(253)->visible(fn ($get) => $get('mode') === 'socks5')->required(fn ($get) => $get('mode') === 'socks5'),
                        TextInput::make('socks5_port')->label('Порт')->numeric()->minValue(1)->maxValue(65535)->visible(fn ($get) => $get('mode') === 'socks5')->required(fn ($get) => $get('mode') === 'socks5'),
                        TextInput::make('socks5_username')->label('Логин')->maxLength(255)->visible(fn ($get) => $get('mode') === 'socks5'),
                        TextInput::make('socks5_password')->label('Пароль')->password()->revealable()->maxLength(1024)->visible(fn ($get) => $get('mode') === 'socks5')->dehydrated(),
                    ]),
                Section::make('AmneziaWG')
                    ->description('Укажите интерфейс, уже настроенный и поднятый на узле, например awg0. Этот плагин не генерирует клиентский конфиг AmneziaWG.')
                    ->schema([
                        TextInput::make('awg_interface')->label('Имя интерфейса')->maxLength(32)->required(fn ($get) => $get('mode') === 'amneziawg')->visible(fn ($get) => $get('mode') === 'amneziawg'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Сохранить настройки')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $server = filament()->getTenant();
        abort_unless($server && isset($server->uuid, $server->node_id), 404);

        $data = $this->form->getState();
        $validated = Validator::make($data, [
            'mode' => ['required', Rule::in(['off', 'socks5', 'amneziawg'])],
            'socks5_host' => ['nullable', 'string', 'max:253'],
            'socks5_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'socks5_username' => ['nullable', 'string', 'max:255'],
            'socks5_password' => ['nullable', 'string', 'max:1024'],
            'awg_interface' => ['nullable', 'regex:/^[a-zA-Z0-9_.-]{1,32}$/'],
        ])->validate();

        if ($validated['mode'] === 'socks5' && (trim((string) ($validated['socks5_host'] ?? '')) === '' || empty($validated['socks5_port']))) {
            Notification::make()->title('Для SOCKS5 укажите хост и порт')->danger()->send();
            return;
        }

        $setting = ServerProxySetting::firstOrNew(['server_uuid' => (string) $server->uuid]);
        $setting->node_id = (int) $server->node_id;
        $setting->mode = $validated['mode'];
        $setting->socks5_host = trim((string) ($validated['socks5_host'] ?? '')) ?: null;
        $setting->socks5_port = $validated['socks5_port'] ?? 1080;
        $setting->socks5_username = (string) ($validated['socks5_username'] ?? '') ?: null;
        if (($validated['socks5_password'] ?? '') !== '') {
            $setting->socks5_password = $validated['socks5_password'];
        }
        $setting->awg_interface = (string) ($validated['awg_interface'] ?? 'awg0') ?: 'awg0';
        $setting->save();

        Notification::make()->title('Настройки сохранены')->body('Узел применит изменения после следующего опроса агента.')->success()->send();
    }
}
