<?php

namespace Dylaris\GatewayPelican\Filament\Admin\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use Dylaris\GatewayPanel\DomainList;
use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPelican\ErrorText;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The panel-wide list of address domains: the provider's official domains (they arrive on their own) and own domains,
 * added and verified here. A switch per domain: on = users may pick it, off = admins only. A node offers the domains
 * of its own region and those without one.
 */
class GatewayDomains extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::World;

    protected static ?string $navigationLabel = 'Gateway domains';

    protected static ?string $title = 'Gateway domains';

    protected static ?string $slug = 'gateway-domains';

    protected string $view = 'dylaris-gateway::gateway-domains';

    /** Why the list could not be synced on opening, shown above it. */
    public ?string $problem = null;

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.advanced');
    }

    public static function canAccess(): bool
    {
        return user()?->can('update plugin') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            app(DomainList::class)->ensureFresh();
        } catch (\Throwable $e) {
            $this->problem = ErrorText::for($e, true);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(TablerIcon::Refresh)
                ->color('gray')
                ->authorize(fn () => static::canAccess())
                ->action(function () {
                    $this->run(fn (DomainList $l) => $l->sync(), 'The domain list is up to date.');
                }),
            Action::make('addOwn')
                ->label('Add own domain')
                ->icon(TablerIcon::Plus)
                ->authorize(fn () => static::canAccess())
                ->modalHeading('Add an own domain')
                ->modalDescription('The domain is claimed at DYLARIS for this account. Publish the TXT record shown in the list, then verify it. Users can pick it once it is verified and switched on. Players reach it once it also has a CNAME to the gateway, as for any own domain.')
                ->schema([
                    TextInput::make('domain')
                        ->label('Domain')
                        ->placeholder('play.example.com')
                        ->required()
                        ->maxLength(253),
                ])
                ->action(function (array $data) {
                    $this->run(fn (DomainList $l) => $l->addOwn((string) $data['domain']), 'Domain added. Publish its TXT record, then verify it.');
                }),
        ];
    }

    public function toggleAction(): Action
    {
        return Action::make('toggle')
            ->label(fn (array $arguments) => ($arguments['on'] ?? false) ? 'Switch on' : 'Switch off')
            ->button()
            ->size('sm')
            ->color('gray')
            ->authorize(fn () => static::canAccess())
            ->action(function (array $arguments) {
                $on = (bool) ($arguments['on'] ?? false);
                $this->run(
                    fn (DomainList $l) => $l->setEnabled((string) ($arguments['domain'] ?? ''), $on),
                    $on ? 'Users can pick this domain now.' : 'Only admins can pick this domain now. Existing addresses keep working.',
                );
            });
    }

    public function verifyAction(): Action
    {
        return Action::make('verify')
            ->label('Verify')
            ->button()
            ->size('sm')
            ->authorize(fn () => static::canAccess())
            ->action(function (array $arguments) {
                $this->run(fn (DomainList $l) => $l->verifyOwn((string) ($arguments['domain'] ?? '')));
            });
    }

    public function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->button()
            ->size('sm')
            ->color('danger')
            ->authorize(fn () => static::canAccess())
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Remove ' . ($arguments['domain'] ?? '') . '?')
            ->modalDescription('An own domain is released at DYLARIS too; that is refused while a route still uses it. Existing addresses are not changed here.')
            ->action(function (array $arguments) {
                $this->run(fn (DomainList $l) => $l->remove((string) ($arguments['domain'] ?? '')), 'Domain removed from the list.');
            });
    }

    protected function getViewData(): array
    {
        $store = app(StateStore::class);

        return ['domains' => $store->domains(), 'sync' => $store->domainSync()];
    }

    /** Runs one list change and says how it went; a returned string is the sentence. */
    private function run(callable $change, ?string $success = null): void
    {
        try {
            $result = $change(app(DomainList::class));
        } catch (\Throwable $e) {
            Notification::make()->title(ErrorText::for($e, true))->danger()->send();

            return;
        }
        $this->problem = null;
        Notification::make()->title(is_string($result) ? $result : (string) $success)->success()->send();
    }

    public static function syncLine(DomainSync $s): string
    {
        if ($s->syncedAt === null) {
            return 'Not synced with DYLARIS yet.';
        }
        $at = \Illuminate\Support\Carbon::createFromTimestamp($s->syncedAt);

        return 'Last synced with DYLARIS ' . $at->diffForHumans() . ' (' . $at->format('Y-m-d H:i T') . '). The list syncs every hour.';
    }
}
