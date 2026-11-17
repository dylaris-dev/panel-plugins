import { http, httpErrorToHuman, toast } from '@pterodactyl/sdk';

export const MODE_LABELS: Record<string, string> = { direct: 'Direct', both: 'Both', gateway_only: 'Gateway only' };

export interface Envelope<T> {
    data: T;
    message: string | null;
}

/** Runs a request, toasts the backend's sentence on success, and its refusal on failure. Null when it failed. */
export async function call<T>(request: Promise<{ data: Envelope<T> }>): Promise<Envelope<T> | null> {
    try {
        const { data } = await request;
        if (data.message) toast.success(data.message);

        return data;
    } catch (cause) {
        toast.error(httpErrorToHuman(cause));

        return null;
    }
}

export { http, httpErrorToHuman };
