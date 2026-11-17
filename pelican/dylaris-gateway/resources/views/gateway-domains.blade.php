<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        @if ($problem)
            <x-filament::callout color="danger" heading="The domain list could not be synced" :description="$problem" />
        @endif

        @if ($sync->error)
            <x-filament::callout color="warning" heading="The last sync with DYLARIS failed"
                :description="$sync->error . ' The list below is the last one that synced.'" />
        @endif

        <x-filament::section heading="Address domains"
            description="The domains users pick from for a server's gateway address, for every node of this panel. A node offers the domains of its own region and those without a region. Switched off: admins only. Changing this never changes an existing address.">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ \Dylaris\GatewayPelican\Filament\Admin\Pages\GatewayDomains::syncLine($sync) }}</p>

            @if ($domains === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">No domains yet. Refresh to load the DYLARIS domains, or add an own domain.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-start font-medium">Domain</th>
                                <th class="px-3 py-2 text-start font-medium">Region</th>
                                <th class="px-3 py-2 text-start font-medium">Status</th>
                                <th class="px-3 py-2 text-start font-medium">Users</th>
                                <th class="px-3 py-2"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($domains as $d)
                                <tr class="border-b border-gray-200 align-top dark:border-white/10" wire:key="domain-{{ $d->domain }}">
                                    <td class="px-3 py-3">
                                        <code>{{ $d->domain }}</code>
                                        @if ($d->own() && !$d->gone && $d->ownState !== 'verified' && $d->txtValue !== '')
                                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                Publish this TXT record, then verify:<br>
                                                Name <code class="select-all">{{ $d->txtName }}</code><br>
                                                Value <code class="select-all">{{ $d->txtValue }}</code>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">{{ $d->region ?? 'Every region' }}</td>
                                    <td class="px-3 py-3">
                                        <x-filament::badge :color="$d->gone ? 'danger' : ($d->usable() ? 'success' : 'warning')">
                                            {{ $d->status() }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="px-3 py-3">{{ $d->enabled ? 'On' : 'Admins only' }}</td>
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @if (!$d->gone)
                                                {{ ($this->toggleAction)(['domain' => $d->domain, 'on' => !$d->enabled]) }}
                                            @endif
                                            @if ($d->own() && !$d->gone && $d->ownState !== 'verified')
                                                {{ ($this->verifyAction)(['domain' => $d->domain]) }}
                                            @endif
                                            @if ($d->own() || $d->gone)
                                                {{ ($this->removeAction)(['domain' => $d->domain]) }}
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
