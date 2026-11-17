import { useState } from 'react';
import { Alert, Button, Input, Label, TitledGreyBox } from '@pterodactyl/sdk';
import { http, httpErrorToHuman } from './api';

interface Result {
    ok: boolean;
    message: string;
    warning: string | null;
}

const column = { display: 'flex', flexDirection: 'column', gap: '1rem' } as const;

/**
 * Test connection for the DYLARIS Gateway settings, on the admin Extensions page. Blank fields mean the saved values;
 * the backend sends the saved key only to the saved URL, never to one typed here.
 */
export default function ConnectionTest() {
    const [url, setUrl] = useState('');
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<Result | null>(null);

    const test = async () => {
        setBusy(true);
        try {
            const { data } = await http.post<{ data: Result }>('/api/admin/extensions/dylaris-gateway/test-connection', {
                provider_url: url.trim() || null,
                api_key: key.trim() || null,
            });
            setResult(data.data);
        } catch (cause) {
            setResult({ ok: false, message: httpErrorToHuman(cause), warning: null });
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
                <p>Checks a provider URL and API key before or after saving them in the extension settings.</p>
                <div>
                    <Label htmlFor='dg-test-url'>Provider URL</Label>
                    <Input id='dg-test-url' value={url} placeholder='The saved URL' onChange={(event) => setUrl(event.target.value)} />
                </div>
                <div>
                    <Label htmlFor='dg-test-key'>API key</Label>
                    <Input
                        id='dg-test-key'
                        type='password'
                        autoComplete='new-password'
                        value={key}
                        placeholder='The saved key, for the saved URL only'
                        onChange={(event) => setKey(event.target.value)}
                    />
                </div>
                <div>
                    <Button isLoading={busy} onClick={test}>
                        Test connection
                    </Button>
                </div>
                {result?.warning && <Alert type='warning'>{result.warning}</Alert>}
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
