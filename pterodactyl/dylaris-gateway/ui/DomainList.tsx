import { useCallback, useEffect, useState } from 'react';
import {
    Alert,
    Button,
    Code,
    Dialog,
    Input,
    Label,
    PageContentBlock,
    PageHeading,
    Spinner,
    Switch,
    TitledGreyBox,
    formatDate,
    fromNow,
} from '@pterodactyl/sdk';
import { call, http, httpErrorToHuman } from './api';

interface DomainRow {
    domain: string;
    own: boolean;
    enabled: boolean;
    region: string | null;
    status: string;
    usable: boolean;
    gone: boolean;
    txt_name: string;
    txt_value: string;
    can_verify: boolean;
    can_remove: boolean;
}

interface ListState {
    problem: string | null;
    synced_at: number | null;
    sync_error: string | null;
    domains: DomainRow[];
}

const base = '/api/admin/extensions/dylaris-gateway/domains';
const column = { display: 'flex', flexDirection: 'column', gap: '1rem' } as const;
const row = { display: 'flex', flexWrap: 'wrap', gap: '0.75rem', alignItems: 'center' } as const;
const cell = { padding: '0.75rem', verticalAlign: 'top', textAlign: 'left' } as const;

/**
 * The panel-wide list of address domains, an admin screen of its own (extension.json). Mirrors the Pelican plugin's
 * Gateway domains page; the backend checks every change again.
 */
export default function DomainList() {
    const [state, setState] = useState<ListState | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [own, setOwn] = useState('');
    const [removing, setRemoving] = useState<string | null>(null);

    const load = useCallback(() => {
        http.get<{ data: ListState }>(base)
            .then(({ data }) => setState(data.data))
            .catch((cause) => setLoadError(httpErrorToHuman(cause)));
    }, []);

    useEffect(load, [load]);

    const run = async (request: () => Promise<{ data: { data: ListState; message: string | null } }>) => {
        setBusy(true);
        const result = await call(request());
        setBusy(false);
        if (result) setState(result.data);

        return result !== null;
    };

    const enc = encodeURIComponent;

    return (
        <PageContentBlock title='Gateway domains'>
            <PageHeading
                title='Gateway domains'
                description="The domains users pick from for a server's gateway address, for every node of this panel."
                actions={
                    <Button isSecondary isLoading={busy} disabled={state === null} onClick={() => run(() => http.post(`${base}/refresh`))}>
                        Refresh
                    </Button>
                }
            />
            {loadError && (
                <Alert type='danger' title='The domain list could not be loaded'>
                    {loadError}
                </Alert>
            )}
            {!state && !loadError && <Spinner centered size='large' />}
            {state && (
                <div style={column}>
                    {state.problem && (
                        <Alert type='danger' title='The domain list could not be synced'>
                            {state.problem}
                        </Alert>
                    )}
                    {state.sync_error && (
                        <Alert type='warning' title='The last sync with DYLARIS failed'>
                            {state.sync_error} The list below is the last one that synced.
                        </Alert>
                    )}

                    <TitledGreyBox title='Address domains'>
                        <div style={column}>
                            <p>
                                A node offers the domains of its own region and those without a region. Switched off:
                                admins only. Changing this never changes an existing address.{' '}
                                {state.synced_at === null
                                    ? 'Not synced with DYLARIS yet.'
                                    : `Last synced with DYLARIS ${fromNow(new Date(state.synced_at * 1000))} (${formatDate(new Date(state.synced_at * 1000), 'YYYY-MM-DD HH:mm')}). The list syncs every hour.`}
                            </p>
                            {state.domains.length === 0 ? (
                                <p>No domains yet. Refresh to load the DYLARIS domains, or add an own domain.</p>
                            ) : (
                                <div style={{ overflowX: 'auto' }}>
                                    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                        <thead>
                                            <tr>
                                                <th style={cell}>Domain</th>
                                                <th style={cell}>Region</th>
                                                <th style={cell}>Status</th>
                                                <th style={cell}>Users</th>
                                                <th style={cell}>
                                                    <span className='sr-only'>Actions</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {state.domains.map((d) => (
                                                <tr key={d.domain} style={{ borderTop: '1px solid var(--color-border, rgba(127,127,127,0.25))' }}>
                                                    <td style={cell}>
                                                        <Code>{d.domain}</Code>
                                                        {d.txt_value !== '' && (
                                                            <p className='input-help'>
                                                                Publish this TXT record, then verify: name <Code>{d.txt_name}</Code>, value{' '}
                                                                <Code>{d.txt_value}</Code>
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td style={{ ...cell, whiteSpace: 'nowrap' }}>{d.region ?? 'Every region'}</td>
                                                    <td style={cell}>{d.status}</td>
                                                    <td style={cell}>
                                                        {d.gone ? (
                                                            'Cannot be picked'
                                                        ) : (
                                                            <Switch
                                                                checked={d.enabled}
                                                                disabled={busy}
                                                                label={d.enabled ? 'On' : 'Admins only'}
                                                                aria-label={`Users may pick ${d.domain}`}
                                                                onChange={(on) => run(() => http.put(`${base}/${enc(d.domain)}/enabled`, { enabled: on }))}
                                                            />
                                                        )}
                                                    </td>
                                                    <td style={cell}>
                                                        <div style={{ ...row, justifyContent: 'flex-end' }}>
                                                            {d.can_verify && (
                                                                <Button size='small' disabled={busy} onClick={() => run(() => http.post(`${base}/${enc(d.domain)}/verify`))}>
                                                                    Verify
                                                                </Button>
                                                            )}
                                                            {d.can_remove && (
                                                                <Button.Danger size='small' disabled={busy} onClick={() => setRemoving(d.domain)}>
                                                                    Remove
                                                                </Button.Danger>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </TitledGreyBox>

                    <TitledGreyBox title='Add an own domain'>
                        <form
                            style={column}
                            onSubmit={async (event) => {
                                event.preventDefault();
                                if (await run(() => http.post(base, { domain: own.trim() }))) setOwn('');
                            }}
                        >
                            <div>
                                <Label htmlFor='dg-own-domain'>Domain</Label>
                                <Input id='dg-own-domain' value={own} placeholder='play.example.com' maxLength={253} onChange={(event) => setOwn(event.target.value)} />
                                <p className='input-help'>
                                    The domain is claimed at DYLARIS for this account. Publish the TXT record shown in the
                                    list, then verify it. Users can pick it once it is verified and switched on. Players
                                    reach it once it also has a CNAME to the gateway, as for any own domain.
                                </p>
                            </div>
                            <div>
                                <Button type='submit' isLoading={busy} disabled={own.trim() === ''}>
                                    Add own domain
                                </Button>
                            </div>
                        </form>
                    </TitledGreyBox>
                </div>
            )}

            <Dialog.Confirm
                open={removing !== null}
                onClose={() => setRemoving(null)}
                pending={busy}
                title={`Remove ${removing ?? ''}?`}
                confirm='Remove'
                onConfirmed={async () => {
                    await run(() => http.delete(`${base}/${enc(removing ?? '')}`));
                    setRemoving(null);
                }}
            >
                An own domain is released at DYLARIS too; that is refused while a route still uses it. Existing addresses
                are not changed here.
            </Dialog.Confirm>
        </PageContentBlock>
    );
}
