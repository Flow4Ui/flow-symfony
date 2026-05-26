import {createMemoryHistory, createRouter, createWebHashHistory, createWebHistory} from 'vue-router';

// Define default options
const defaultFlowRouterOptions = {
    routes: [],
    mode: 'history',
    base: null,
};

try {
    window.FlowRouterOptions = window.FlowRouterOptions || defaultFlowRouterOptions;
} catch (error) {
    // Some browser automation contexts expose a non-extensible window object.
}

function normalizeRoute(route, app) {
    const routeName = route.name;
    const routeComponent = app.component(route.component);

    if (!routeComponent) {
        console.error(`Component not found for route '${routeName}'`);
        return null;
    }

    const normalizedRoute = {
        path: route.path,
        component: routeComponent,
        name: routeName,
        props: route.props,
    };

    if (route.meta !== undefined) {
        normalizedRoute.meta = route.meta;
    }

    if (Array.isArray(route.children) && route.children.length > 0) {
        normalizedRoute.children = route.children
            .map((child) => normalizeRoute(child, app))
            .filter((child) => child !== null);
    }

    return normalizedRoute;
}

export function createFlowRouter(flowRouterOptions = {}) {
    flowRouterOptions = {
        ...defaultFlowRouterOptions,
        ...flowRouterOptions,
    };

    return {
        install(app, options) {
            const routerOptions = {
                history: null,
                routes: [],
            };

            // Determine the router history mode
            if (flowRouterOptions.mode === 'history') {
                routerOptions.history = createWebHistory(flowRouterOptions.base);
            } else if (flowRouterOptions.mode === 'hash') {
                routerOptions.history = createWebHashHistory(flowRouterOptions.base);
            } else {
                routerOptions.history = createMemoryHistory(flowRouterOptions.base);
            }

            for (const route of flowRouterOptions.routes) {
                const normalizedRoute = normalizeRoute(route, app);

                if (normalizedRoute !== null) {
                    routerOptions.routes.push(normalizedRoute);
                }
            }

            app.use(createRouter(routerOptions));
        },
    };
}
