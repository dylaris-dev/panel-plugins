import { useCallback, useEffect, useState } from 'react';
import {
    Alert,
    Button,
    Code,
    CopyOnClick,
    Dialog,
    Input,
    Label,
    queryClient,
    Select,
    ServerContentBlock,
    Switch,
    TitledGreyBox,
    toast,
    useCurrentServerRequired,
    useServerPermission,
} from '@pterodactyl/sdk';
import { call, http, httpErrorToHuman } from './api';

/** Must match Permissions::MANAGE on the backend, which checks it again on every request. */
const PERMISSION = 'ext.dylaris-gateway.manage';

interface ServerState {
    mode: string;
    modes: { value: string; label: string }[];
    not_offered: string | null;
    first_enable: boolean;
    label: string;
    address: string | null;
    real_ip: boolean;
    bedrock: boolean;
    restart_needed: boolean;
    last_error: string | null;
    status: string | null;
}

const column = { display: 'flex', flexDirection: 'column', gap: '1rem' } as const;

/**
 * After a mode change the panel's allocation list is stale; its query key is the generated
 * `clientListServerAllocations` one, which the SDK does not wrap.
 */
const refreshAllocations = (uuid: string) =>
    queryClient.invalidateQueries({
        predicate: ({ queryKey }) => {
            const key = queryKey[0] as { _id?: string; path?: { server_uuid?: string } } | undefined;
            return key?._id === 'clientListServerAllocations' && key.path?.server_uuid === uuid;
        },
    });

/**
 * The server's Gateway page, a screen of its own (extension.json): its nav entry and route need only
 * ext.dylaris-gateway.manage, where the Network page also needs allocation.read. Mirrors the Pelican widget.
 */
export default function ServerGateway() {
    const server = useCurrentServerRequired();
    const allowed = useServerPermission(PERMISSION);
    // The server comes from the page, and the backend takes it from the route only.
    const base = `/api/client/servers/${server.attributes.uuid}/extensions/dylaris-gateway`;

    const [state, setState] = useState<ServerState | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [mode, setMode] = useState('direct');
    const [label, setLabel] = useState('');
    const [busy, setBusy] = useState(false);
    /** What the chosen switch changes beyond the mode; shown in a confirm step before it runs. */
    const [warnings, setWarnings] = useState<string[] | null>(null);

    const apply = useCallback((s: ServerState) => {
        setState(s);
        setMode(s.mode);
        // Keep what the user typed after a refusal.
        setLabel((typed) => typed || s.label);
    }, []);

    useEffect(() => {
        if (!allowed) return;
        http.get<{ data: ServerState }>(base)
            .then(({ data }) => apply(data.data))
            .catch((cause) => setLoadError(httpErrorToHuman(cause)));
    }, [allowed, base, apply]);

    if (!allowed) return null;
    if (loadError) {
        return (
            <ServerContentBlock title='Gateway'>
                <TitledGreyBox title='Gateway'>
                    <Alert type='danger' title='The gateway could not be loaded'>
                        {loadError}
                    </Alert>
                </TitledGreyBox>
            </ServerContentBlock>
        );
    }
    if (!state) return null;

    const post = async (path: string, body: object) => {
        setBusy(true);
        const result = await call<ServerState>(http.post(base + path, body));
        setBusy(false);
        if (result) apply(result.data);

        return result !== null;
    };

    const switchMode = async () => {
        const result = await post('/mode', state.first_enable ? { mode, label: label.trim() } : { mode });
        if (result) await refreshAllocations(server.attributes.uuid);
    };

    const applyMode = async () => {
        let found: string[] = [];
        try {
            const { data } = await http.post<{ data: { warnings: string[] } }>(`${base}/mode/preview`, { mode });
            found = data.data.warnings;
        } catch {
            // The switch itself says what is wrong.
        }
        if (found.length > 0) setWarnings(found);
        else await switchMode();
    };

    const download = async () => {
        try {
            const { data } = await http.get<Blob>(`${base}/floodgate-key`, { responseType: 'blob' });
            const url = URL.createObjectURL(data);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'key.pem';
            a.click();
            URL.revokeObjectURL(url);
        } catch (cause) {
            // A refusal comes back as a JSON blob; read it for the sentence.
            const blob = (cause as { response?: { data?: unknown } }).response?.data;
            const text = blob instanceof Blob ? await blob.text().catch(() => '') : '';
            let message = httpErrorToHuman(cause);
            try {
                message = (JSON.parse(text) as { error?: string }).error ?? message;
            } catch {
                // not JSON: keep the generic sentence
            }
            toast.error(message);
        }
    };

    const gatewayMode = state.mode === 'both' || state.mode === 'gateway_only';

    return (
        <ServerContentBlock title='Gateway'>
        <TitledGreyBox title='Gateway'>
            <div style={column}>
                {state.restart_needed && <Alert type='warning'>Restart the server to apply the change.</Alert>}
                {state.not_offered && <Alert type='info'>{state.not_offered}</Alert>}
                {state.last_error && (
                    <Alert type='danger' title='Last gateway error'>
                        {state.last_error}
                    </Alert>
                )}
                {state.status && <p>{state.status}</p>}

                <div>
                    <Label htmlFor='dg-mode'>Mode</Label>
                    <Select id='dg-mode' value={mode} options={state.modes} onChange={(value) => setMode(String(value))} />
                    <p className='input-help'>
                        Direct: players use the server's own address. Both: the own address and the gateway address.
                        Gateway only: just the gateway address, the server's port is closed.
                    </p>
                </div>

                {state.first_enable && (
                    <div>
                        <Label htmlFor='dg-label'>Address name</Label>
                        <Input id='dg-label' value={label} maxLength={63} onChange={(event) => setLabel(event.target.value)} />
                        <p className='input-help'>
                            The first part of the gateway address. It is chosen once, before the gateway is first
                            enabled.
                        </p>
                    </div>
                )}

                {state.address && (
                    <div>
                        <Label>Gateway address</Label>
                        <div>
                            <CopyOnClick text={state.address} showInNotification>
                                <Code>{state.address}</Code>
                            </CopyOnClick>
                        </div>
                    </div>
                )}

                <div>
                    <Button isLoading={busy} onClick={applyMode}>
                        Apply mode
                    </Button>
                </div>

                {state.mode === 'gateway_only' && (
                    <Switch
                        checked={state.real_ip}
                        disabled={busy}
                        label='Real player IP'
                        description="Passes each player's real IP with the PROXY protocol. Turn it on in the server as well, or nobody can join. Paper: proxies.proxy-protocol: true in config/paper-global.yml. Velocity: haproxy-protocol = true in velocity.toml."
                        onChange={(on) => post('/real-ip', { enabled: on })}
                    />
                )}

                {gatewayMode && (
                    <Switch
                        checked={state.bedrock}
                        disabled={busy}
                        label='Bedrock players'
                        description='Lets Bedrock players join through the gateway. The server needs Floodgate with the key below.'
                        onChange={(on) => post('/bedrock', { enabled: on })}
                    />
                )}

                {state.bedrock && (
                    <div style={column}>
                        <p>
                            <strong>Set up Floodgate for Bedrock players</strong>
                        </p>
                        <ol style={{ paddingInlineStart: '1.25rem', listStyle: 'decimal' }}>
                            <li>Install Floodgate on the server (on Velocity or BungeeCord, on the proxy).</li>
                            <li>Start the server once so Floodgate creates its folder, then stop it.</li>
                            <li>Download the key below and put it at plugins/floodgate/key.pem, replacing the generated one.</li>
                            <li>Start the server again.</li>
                        </ol>
                        <div>
                            <Button isSecondary onClick={download}>
                                Download Floodgate key
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </TitledGreyBox>
        <Dialog.Confirm
            open={warnings !== null}
            onClose={() => setWarnings(null)}
            pending={busy}
            title='Switch the mode?'
            confirm='Apply mode'
            onConfirmed={async () => {
                setWarnings(null);
                await switchMode();
            }}
        >
            {(warnings ?? []).join(' ')}
        </Dialog.Confirm>
        </ServerContentBlock>
    );
}
