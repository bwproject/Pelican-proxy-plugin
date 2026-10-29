<?php

namespace BWProject\BWProxy;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
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

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            $panel->discoverPages(
                in: plugin_path('bwproxy', 'src/Filament/Server/Pages'),
                for: 'BWProject\\BWProxy\\Filament\\Server\\Pages',
            );
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return ['agent_timeout' => config('bwproxy.agent_timeout', 10)];
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('BW Proxy Agent')
                ->description('Токены узлов задаются в .env панели в BWPROXY_NODE_TOKENS как JSON-объект с ключом node_id. Один уникальный случайный токен на каждый узел.')
                ->schema([
                    Select::make('agent_timeout')
                        ->label('Таймаут агента (секунды)')
                        ->options(['5' => '5', '10' => '10', '15' => '15', '30' => '30'])
                        ->required()
                        ->default('10'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $timeout = (int) ($data['agent_timeout'] ?? 10);
        if (! in_array($timeout, [5, 10, 15, 30], true)) {
            Notification::make()->title('Недопустимый таймаут')->danger()->send();
            return;
        }

        $this->writeToEnvironment(['BWPROXY_AGENT_TIMEOUT' => $timeout]);
        Notification::make()->title('Настройки сохранены')->success()->send();
    }
}
