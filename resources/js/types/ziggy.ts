import { Config, RouteParams } from 'ziggy-js';

// Ziggy also takes a lone id or a list of ids for the route's parameters, e.g. route('campaigns.show', 4).
type RouteParamValue = string | number;

declare global {
    function route(): Config;
    function route(name: string, params?: RouteParams<typeof name> | RouteParamValue | RouteParamValue[], absolute?: boolean): string;
}

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}
