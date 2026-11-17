import { useState } from 'react';
import { Alert, Button, Input, Label, TitledGreyBox } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from './api';

interface Result {
    ok: boolean;
    message: string;
    provider_url?: string;
}

const column = { display: 'flex', flexDirection: 'column', gap: '1rem' } as const;

/**
 * Test connection for the DYLARIS Gateway settings, on the admin Extensions page. A blank key means the saved one; the
 * provider URL is fixed, so the key only ever goes to the DYLARIS provider.
 */
export default function ConnectionTest() {
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<Result | null>(null);

    const test = async () => {
        setBusy(true);
        try {
            const { data } = await http.post<{ data: Result }>('/api/admin/extensions/dylaris-gateway/test-connection', {
                api_key: key.trim() || null,
            });
            setResult(data.data);
        } catch (cause) {
            setResult({ ok: false, message: httpErrorToHuman(cause) });
        } finally {
            setBusy(false);
        }
    };

    return (
        // page-width is the panel's content-column utility (PageContentBlock); the spacing is its token, inline
        // because the panel's stylesheet only carries the utility classes the panel itself uses.
        <div className='page-width' style={{ marginTop: 'var(--layout-page-spacing)' }}>
        <TitledGreyBox title='DYLARIS Gateway: test connection'>
            <div style={column}>
                <p>Checks an API key before or after saving it in the extension settings. Calls go only to https://panel.dylaris.com.</p>
                <div>
                    <Label htmlFor='dg-test-key'>API key</Label>
                    <Input
                        id='dg-test-key'
                        type='password'
                        autoComplete='new-password'
                        value={key}
                        placeholder='The saved key'
                        onChange={(event) => setKey(event.target.value)}
                    />
                </div>
                <div>
                    <Button isLoading={busy} onClick={test}>
                        Test connection
                    </Button>
                </div>
                {result && (
                    <Alert type={result.ok ? 'success' : 'danger'} title={result.ok ? undefined : 'Connection test failed'}>
                        {result.message}
                    </Alert>
                )}
            </div>
        </TitledGreyBox>
        </div>
    );
}
