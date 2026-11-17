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

/** Must match Permissions on the backend, which checks each one again on every request. Mode and address include view. */
const PERMISSIONS = ['ext.dylaris-gateway.view', 'ext.dylaris-gateway.mode', 'ext.dylaris-gateway.address'];

interface DomainOption {
    value: string;
    label: string;
    custom: boolean;
}

interface ServerState {
    mode: string;
    modes: { value: string; label: string }[];
    not_offered: string | null;
    not_allowed: string | null;
    can_mode: boolean;
    can_address: boolean;
    first_enable: boolean;
    domains: DomainOption[];
    domain: string;
    label: string;
    address: string | null;
    real_ip: boolean;
    bedrock: boolean;
    restart_needed: boolean;
    last_error: string | null;
    status: string | null;
}

const column = { display: 'flex', flexDirection: 'column', gap: '1rem' } as const;
const row = { display: 'flex', flexWrap: 'wrap', gap: '0.75rem', alignItems: 'center' } as const;

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
 * The server's Gateway page, a screen of its own (extension.json): its nav entry and route need any one of the three
 * gateway permissions, where the Network page also needs allocation.read. Mirrors the Pelican Gateway page.
 */
export default function ServerGateway() {
    const server = useCurrentServerRequired();
    const allowed = useServerPermission(PERMISSIONS, true);
    const canRestart = useServerPermission('control.restart');
    // The server comes from the page, and the backend takes it from the route only.
    const base = `/api/client/servers/${server.attributes.uuid}/extensions/dylaris-gateway`;

    const [state, setState] = useState<ServerState | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [mode, setMode] = useState('direct');
    const [domain, setDomain] = useState('');
    const [label, setLabel] = useState('');
    const [busy, setBusy] = useState(false);
    /** What the chosen switch changes beyond the mode; shown in a confirm step before it runs. */
    const [warnings, setWarnings] = useState<string[] | null>(null);
    const [confirmAddress, setConfirmAddress] = useState(false);
    const [confirmRestart, setConfirmRestart] = useState(false);

    const apply = useCallback((s: ServerState, keepTyped = true) => {
        setState(s);
        setMode(s.mode);
        // Keep what the user picked and typed after a refusal.
        setDomain((picked) => (keepTyped && picked) || s.domain);
        setLabel((typed) => (keepTyped && typed) || s.label);
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

    const post = async (path: string, body: object, keepTyped = true) => {
        setBusy(true);
        const result = await call<ServerState>(http.post(base + path, body));
        setBusy(false);
        if (result) apply(result.data, keepTyped);

        return result !== null;
    };

    const chosen = state.domains.find((d) => d.value === domain);
    const gatewayMode = state.mode === 'both' || state.mode === 'gateway_only';
    const modeSelectable = state.can_mode && state.modes.length > 1;

    const switchMode = async () => {
        const body = state.first_enable && state.can_address ? { mode, domain, label: label.trim() } : { mode };
        const result = await post('/mode', body);
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

    const changeAddress = () => post('/address', { domain, label: chosen?.custom ? null : label.trim() }, false);

    const restart = async () => {
        setBusy(true);
        try {
            // The panel's own power endpoint, which checks control.restart itself.
            await http.post(`/api/client/servers/${server.attributes.uuid}/power`, { signal: 'restart' });
            toast.success('The server is restarting.');
        } catch (cause) {
            toast.error(httpErrorToHuman(cause));
        } finally {
            setBusy(false);
            setConfirmRestart(false);
        }
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

    const modeLabel = state.modes.find((m) => m.value === state.mode)?.label ?? state.mode;

    return (
        <ServerContentBlock title='Gateway'>
            <TitledGreyBox title='Gateway'>
                <div style={column}>
                    {state.not_allowed && <Alert type='warning'>{state.not_allowed}</Alert>}
                    {state.restart_needed && (
                        <Alert type='warning' title='Restart the server to apply the change.'>
                            <div style={column}>
                                <span>The extension never restarts a server by itself.</span>
                                {canRestart && (
                                    <div>
                                        <Button isLoading={busy} onClick={() => setConfirmRestart(true)}>
                                            Restart now
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </Alert>
                    )}
                    {state.not_offered && <Alert type='info'>{state.not_offered}</Alert>}
                    {state.last_error && (
                        <Alert type='danger' title='Last gateway error'>
                            {state.last_error}
                        </Alert>
                    )}
                    {state.status && <p>{state.status}</p>}

                    {modeSelectable ? (
                        <div>
                            <Label htmlFor='dg-mode'>Mode</Label>
                            <Select id='dg-mode' value={mode} options={state.modes} onChange={(value) => setMode(String(value))} />
                            <p className='input-help'>
                                Direct: players use the server's own address. Both: the own address and the gateway
                                address. Gateway only: just the gateway address, the server's port is closed.
                            </p>
                        </div>
                    ) : (
                        <p>
                            Mode: <strong>{modeLabel}</strong>
                        </p>
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

                    {state.can_address && state.domains.length > 0 && (
                        <div style={row}>
                            <div style={{ minWidth: '16rem' }}>
                                <Label htmlFor='dg-domain'>Domain</Label>
                                <Select
                                    id='dg-domain'
                                    value={domain}
                                    options={state.domains.map((d) => ({ value: d.value, label: d.label }))}
                                    onChange={(value) => setDomain(String(value))}
                                />
                            </div>
                            {!chosen?.custom && (
                                <div style={{ minWidth: '12rem' }}>
                                    <Label htmlFor='dg-label'>Address name</Label>
                                    <Input
                                        id='dg-label'
                                        value={label}
                                        maxLength={63}
                                        onChange={(event) => setLabel(event.target.value)}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                    {state.can_address && state.domains.length > 0 && (
                        <p className='input-help'>
                            {chosen?.custom
                                ? 'An own domain is the whole address.'
                                : `The address will be ${label.trim() || '<name>'}.${domain}. `}
                            {gatewayMode && ' Changing it moves the server to the new address; the old one stops working.'}
                        </p>
                    )}

                    <div style={row}>
                        {modeSelectable && (
                            <Button isLoading={busy} onClick={applyMode}>
                                Apply mode
                            </Button>
                        )}
                        {state.can_address && state.domains.length > 0 && (
                            <Button
                                isSecondary
                                disabled={busy}
                                onClick={() => (gatewayMode ? setConfirmAddress(true) : changeAddress())}
                            >
                                {gatewayMode ? 'Change address' : 'Save address'}
                            </Button>
                        )}
                    </div>

                    {state.can_mode && state.mode === 'gateway_only' && (
                        <Switch
                            checked={state.real_ip}
                            disabled={busy}
                            label='Real player IP'
                            description="Passes each player's real IP with the PROXY protocol. Turn it on in the server as well, or nobody can join. Paper: proxies.proxy-protocol: true in config/paper-global.yml. Velocity: haproxy-protocol = true in velocity.toml."
                            onChange={(on) => post('/real-ip', { enabled: on })}
                        />
                    )}

                    {state.can_mode && gatewayMode && (
                        <Switch
                            checked={state.bedrock}
                            disabled={busy}
                            label='Bedrock players'
                            description='Lets Bedrock players join through the gateway. The server needs Floodgate with the key below.'
                            onChange={(on) => post('/bedrock', { enabled: on })}
                        />
                    )}

                    {state.can_mode && state.bedrock && (
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
            <Dialog.Confirm
                open={confirmAddress}
                onClose={() => setConfirmAddress(false)}
                pending={busy}
                title='Change the gateway address?'
                confirm='Change address'
                onConfirmed={async () => {
                    setConfirmAddress(false);
                    await changeAddress();
                }}
            >
                The new address works right away and the old address {state.address} stops working: players have to use
                the new one. No restart is needed.
            </Dialog.Confirm>
            <Dialog.Confirm
                open={confirmRestart}
                onClose={() => setConfirmRestart(false)}
                pending={busy}
                title='Restart the server now?'
                confirm='Restart now'
                onConfirmed={restart}
            >
                Players on the server are disconnected while it restarts.
            </Dialog.Confirm>
        </ServerContentBlock>
    );
}
