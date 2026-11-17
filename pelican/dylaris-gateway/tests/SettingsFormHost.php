<?php

namespace Dylaris\GatewayPelican\Tests;

use Dylaris\GatewayPelican\DylarisGatewayPlugin;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/** Hosts the plugin settings form the way Pelican's plugin page does, so its validation runs through Filament. */
class SettingsFormHost extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill((new DylarisGatewayPlugin())->getSettingsFormData());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components((new DylarisGatewayPlugin())->getSettingsForm())->statePath('data');
    }

    public function save(): void
    {
        (new DylarisGatewayPlugin())->saveSettings($this->form->getState());
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
