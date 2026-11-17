<div class="flex flex-col gap-6">
    @if ($problem)
        <x-filament::callout color="danger" heading="The gateway could not be loaded" :description="$problem" />
    @endif

    @if ($notice)
        <x-filament::callout color="warning" :heading="$notice" />
    @endif

    @if ($snippet)
        <x-filament::section heading="Start the link on this node"
            description="Save this as docker-compose.yml on the node and run docker compose up -d. The link key is shown only now: it is not stored and cannot be shown again.">
            <div x-data class="flex flex-col gap-3">
                <pre x-ref="snippet" class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100">{{ $snippet }}</pre>
                <div>
                    <x-filament::button color="gray" x-on:click="navigator.clipboard.writeText($refs.snippet.innerText); $tooltip('Copied')">
                        Copy
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Connection">
        <div class="flex flex-col gap-4">
            @if (($state?->linkId ?? '') !== '')
                <p class="text-sm">Connected as <code>{{ $state->linkId }}</code> in region <strong>{{ $state->region }}</strong>. {{ $kitStatus }}</p>
            @else
                <p class="text-sm">Not connected. Pick the region and confirm the Wings interface IP, then connect.</p>
            @endif

            {{ $this->form }}

            <div class="flex flex-wrap gap-3">
                {{-- Rendering an action does not check visible(); without these checks all of them always show. --}}
                @foreach ([$this->connectAction, $this->saveModesAction, $this->disconnectAction] as $action)
                    @if ($action->isVisible())
                        {{ $action }}
                    @endif
                @endforeach
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Servers using the gateway">
        @if ($servers === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">No server on this node uses the gateway.</p>
        @else
            <ul class="flex flex-col gap-2 text-sm">
                @foreach ($servers as $s)
                    <li>
                        <strong>{{ $s['name'] }}</strong>: {{ $s['mode'] }}, <code>{{ $s['address'] }}</code>
                        @if ($s['error'])
                            <span class="text-danger-600 dark:text-danger-400">{{ $s['error'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</div>
