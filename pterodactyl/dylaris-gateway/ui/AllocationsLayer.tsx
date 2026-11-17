import type { ViewProps } from '@pterodactyl/sdk';

const LOOPBACK = '127.0.0.1';

/**
 * A layer on the server's allocation list. The gateway's 127.0.0.1 allocation belongs to the gateway mode:
 * "Make Primary" or deleting it there would break the route, so it is left out of the list and named below it.
 * The panel cannot hide one row's buttons without a patch; leaving the row out is what a layer can do.
 */
export default function AllocationsLayer({ model, Default, className, children }: ViewProps<'server.allocations'>) {
    const loopbacks = model.allocations.filter((a) => a.ip === LOOPBACK);
    if (loopbacks.length === 0) {
        return <Default className={className}>{children}</Default>;
    }

    return (
        <>
            <Default model={{ ...model, allocations: model.allocations.filter((a) => a.ip !== LOOPBACK) }} className={className}>
                {children}
            </Default>
            <p className='mt-3 text-sm text-muted-foreground'>
                {loopbacks.map((a) => `${LOOPBACK}:${a.port}${a.primary ? ' (primary)' : ''}`).join(', ')} is used by the DYLARIS
                gateway and is not listed here. Change it with the server&apos;s Gateway page.
            </p>
        </>
    );
}
