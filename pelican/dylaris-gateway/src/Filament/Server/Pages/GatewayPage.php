<?php

namespace Dylaris\GatewayPelican\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use Dylaris\GatewayPelican\Permissions;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/** The Gateway page in the server's navigation: the GatewayWidget component, which checks every action itself. */
class GatewayPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Route;

    protected static ?string $navigationLabel = 'Gateway';

    protected static ?string $title = 'Gateway';

    protected static ?string $slug = 'gateway';

    // Next to Network (7), where the server's addresses are.
    protected static ?int $navigationSort = 7;

    protected string $view = 'dylaris-gateway::gateway-page';

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && Permissions::can(Permissions::VIEW, $server);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getServer(): Server
    {
        return Filament::getTenant();
    }
}
