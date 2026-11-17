import { definePterodactylExtension } from '@pterodactyl/sdk';
import AllocationsLayer from './AllocationsLayer';
import ConnectionTest from './ConnectionTest';

export default definePterodactylExtension({
    setup({ slots, screens, views }) {
        // The Gateway tab on the admin node page (manifest: area admin, parent admin.node).
        screens.register('node-gateway', () => import('./NodeGateway'));
        // A screen of its own, gated by ext.dylaris-gateway.manage only (manifest); the Network page needs allocation.read.
        screens.register('server-gateway', () => import('./ServerGateway'));
        views.override('server.allocations', AllocationsLayer);
        // The auto-rendered settings form cannot host a button, so the connection test sits on the Extensions page.
        // `.before`: an `.after` slot renders below the page footer.
        slots.register('panel.extensions.before', ConnectionTest);
    },
});
