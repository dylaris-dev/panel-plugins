import { useCallback, useEffect, useState } from 'react';
import {
    Alert,
    Button,
    Checkbox,
    Code,
    CopyOnClick,
    Dialog,
    Input,
    Label,
    Select,
    Spinner,
    TitledGreyBox,
    useCurrentResource,
} from '@pterodactyl/sdk';
import { call, http, httpErrorToHuman, MODE_LABELS } from './api';

interface NodeState {
    connected: boolean;
    link_id: string | null;
    region: string | null;
    target_host: string;
    allowed_modes: string[];
    kit_status: string | null;
    regions: string[];
    notice: string | null;
    problem: string | null;
    servers: { name: string; mode: string; address: string; error: string | null }[];
    snippet?: string;
}

const column = { display: 'flex', flexDirection: 'column', gap: '1rem' } as const;
const row = { display: 'flex', flexWrap: 'wrap', gap: '0.75rem', alignItems: 'center' } as const;

/** The Gateway tab on the admin node page: one link kit per node. Mirrors the Pelican plugin's node tab. */
export default function NodeGateway() {
    const resource = useCurrentResource();
    const nodeId = resource?.kind === 'admin.node' ? resource.resource.attributes.id : null;
    const base = `/api/admin/extensions/dylaris-gateway/nodes/${nodeId}`;

    const [state, setState] = useState<NodeState | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    // The link key arrives in the connect response only and lives in this component until the page is left.
    const [snippet, setSnippet] = useState<string | null>(null);
    const [region, setRegion] = useState('');
    const [target, setTarget] = useState('');
    const [modes, setModes] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<'connect' | 'disconnect' | null>(null);

    const apply = useCallback((s: NodeState) => {
        setState(s);
        setRegion(s.region ?? s.regions[0] ?? '');
        setTarget(s.target_host);
        setModes(s.allowed_modes);
    }, []);

    useEffect(() => {
        if (nodeId === null) return;
        http.get<{ data: NodeState }>(base)
            .then(({ data }) => apply(data.data))
            .catch((cause) => setLoadError(httpErrorToHuman(cause)));
    }, [base, nodeId, apply]);

    const run = async (request: () => Promise<{ data: { data: NodeState; message: string | null } }>) => {
        setBusy(true);
        const result = await call(request());
        setBusy(false);
        setConfirm(null);
        if (result) {
            apply(result.data);
            setSnippet((shown) => result.data.snippet ?? (result.data.connected ? shown : null));
        }
    };

    if (loadError) return <Alert type='danger' title='The gateway could not be loaded'>{loadError}</Alert>;
    if (!state) return <Spinner centered size='large' />;

    const toggleMode = (value: string, on: boolean) =>
        setModes((current) => (on ? [...current, value] : current.filter((m) => m !== value)));

    return (
        <div style={column}>
            {state.problem && (
                <Alert type='danger' title='The gateway could not be loaded'>
                    {state.problem}
                </Alert>
            )}

            {snippet && (
                <TitledGreyBox title='Start the link on this node'>
                    <div style={column}>
                        <p>
                            Save this as docker-compose.yml on the node and run docker compose up -d. The link key is
                            shown only now: it is not stored and cannot be shown again.
                        </p>
                        <Code>
                            <pre style={{ overflowX: 'auto', margin: 0 }}>{snippet}</pre>
                        </Code>
                        <div>
                            <CopyOnClick text={snippet}>
                                <Button isSecondary>Copy</Button>
                            </CopyOnClick>
                        </div>
                    </div>
                </TitledGreyBox>
            )}

            <TitledGreyBox title='Connection'>
                <div style={column}>
                    {state.connected ? (
                        <p>
                            Connected as <Code>{state.link_id}</Code> in region <strong>{state.region}</strong>.{' '}
                            {state.kit_status}
                        </p>
                    ) : (
                        <p>Not connected. Pick the region and confirm the Wings interface IP, then connect.</p>
                    )}
                    {state.notice && <Alert type='warning'>{state.notice}</Alert>}

                    <div>
                        <Label htmlFor='dg-region'>Region</Label>
                        <Select
                            id='dg-region'
                            value={region}
                            disabled={state.connected || state.regions.length === 0}
                            options={(state.connected ? [state.region ?? ''] : state.regions).map((r) => ({ value: r, label: r }))}
                            onChange={(value) => setRegion(String(value))}
                        />
                        <p className='input-help'>
                            Where players connect to this node's servers. Changing the region later means retiring this
                            node's link kit, and link kits are limited.
                        </p>
                    </div>

                    <div>
                        <Label htmlFor='dg-target'>Wings interface IP</Label>
                        <Input
                            id='dg-target'
                            value={target}
                            disabled={state.connected}
                            onChange={(event) => setTarget(event.target.value)}
                        />
                        <p className='input-help'>The docker.network.interface value from this node's Wings config.</p>
                    </div>

                    <fieldset>
                        <Label as='legend'>Allowed modes</Label>
                        <div style={row}>
                            {Object.entries(MODE_LABELS).map(([value, label]) => (
                                <label key={value} style={row}>
                                    <Checkbox checked={modes.includes(value)} onChange={(on) => toggleMode(value, on)} />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <p className='input-help'>
                            The modes server owners may choose on this node. Admins can always choose any mode.
                        </p>
                    </fieldset>

                    <div style={row}>
                        {!state.connected && (
                            <Button
                                disabled={busy || region === '' || modes.length === 0}
                                onClick={() => setConfirm('connect')}
                            >
                                Connect node
                            </Button>
                        )}
                        {state.connected && (
                            <>
                                <Button
                                    isSecondary
                                    isLoading={busy}
                                    disabled={modes.length === 0}
                                    onClick={() => run(() => http.put(`${base}/modes`, { allowed_modes: modes }))}
                                >
                                    Save allowed modes
                                </Button>
                                <Button.Danger disabled={busy} onClick={() => setConfirm('disconnect')}>
                                    Disconnect node
                                </Button.Danger>
                            </>
                        )}
                    </div>
                </div>
            </TitledGreyBox>

            <TitledGreyBox title='Servers using the gateway'>
                {state.servers.length === 0 ? (
                    <p>No server on this node uses the gateway.</p>
                ) : (
                    <ul style={column}>
                        {state.servers.map((s) => (
                            <li key={s.name}>
                                <strong>{s.name}</strong>: {s.mode}, <Code>{s.address}</Code>
                                {s.error && <Alert type='danger'>{s.error}</Alert>}
                            </li>
                        ))}
                    </ul>
                )}
            </TitledGreyBox>

            <Dialog.Confirm
                open={confirm === 'connect'}
                onClose={() => setConfirm(null)}
                pending={busy}
                title='Connect this node to the gateway?'
                confirm='Connect node'
                onConfirmed={() =>
                    run(() => http.post(`${base}/connect`, { region, target_host: target.trim(), allowed_modes: modes }))
                }
            >
                The region cannot be changed later without retiring this node's link kit. The link key is shown once,
                right after connecting.
            </Dialog.Confirm>
            <Dialog.Confirm
                open={confirm === 'disconnect'}
                onClose={() => setConfirm(null)}
                pending={busy}
                title='Disconnect this node?'
                confirm='Disconnect node'
                onConfirmed={() => run(() => http.post(`${base}/disconnect`))}
            >
                This revokes the node's link kit at the provider. The link on the node stops working.
            </Dialog.Confirm>
        </div>
    );
}
