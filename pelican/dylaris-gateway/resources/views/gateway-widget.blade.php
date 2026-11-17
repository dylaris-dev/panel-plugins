<x-filament-widgets::widget>
    <x-filament::section heading="Gateway">
        <div class="flex flex-col gap-4">
            @if ($notAllowed)
                <x-filament::callout color="warning" :heading="$notAllowed" />
            @endif

            @if ($state?->restartNeeded)
                <x-filament::callout color="warning" heading="Restart the server to apply the change." description="The plugin never restarts a server by itself." />
                @if ($this->restartAction->isVisible())
                    <div>{{ $this->restartAction }}</div>
                @endif
            @endif

            @if ($state?->lastError)
                <x-filament::callout color="danger" heading="Last gateway error" :description="$state->lastError" />
            @endif

            @if ($noDomains)
                <x-filament::callout color="info" :heading="$noDomains" />
            @endif

            @if ($notOffered)
                <x-filament::callout color="warning" :heading="$notOffered" />
            @endif

            @if ($statusLine)
                <p class="text-sm">{{ $statusLine }}</p>
            @endif

            {{ $this->form }}

            <div class="flex flex-wrap gap-3">
                {{-- Rendering an action does not check visible(); without these checks every action always shows. --}}
                @foreach ([$this->applyAction, $this->changeAddressAction] as $action)
                    @if ($action->isVisible())
                        {{ $action }}
                    @endif
                @endforeach
            </div>

            @if ($state?->bedrock && $this->floodgateKeyAction->isVisible())
                <div class="flex flex-col gap-2 text-sm">
                    <p class="font-medium">Set up Floodgate for Bedrock players</p>
                    <ol class="list-decimal ps-5">
                        <li>Install Floodgate on the server (on Velocity or BungeeCord, on the proxy).</li>
                        <li>Start the server once so Floodgate creates its folder, then stop it.</li>
                        <li>Download the key below and put it at plugins/floodgate/key.pem, replacing the generated one.</li>
                        <li>Start the server again.</li>
                    </ol>
                    <div>{{ $this->floodgateKeyAction }}</div>
                </div>
            @endif
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
