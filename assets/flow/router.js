import {createMemoryHistory, createRouter, createWebHashHistory, createWebHistory} from 'vue-router';

// Define default options
const defaultFlowRouterOptions = {
    routes: [],
    mode: 'history',
    base: null,
};

window.FlowRouterOptions = window.FlowRouterOptions || defaultFlowRouterOptions;

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

            // Iterate through routes and create route configurations
            for (const route of flowRouterOptions.routes) {
                const routeName = route.name;
                const routeComponent = app.component(route.component);

                if (!routeComponent) {
                    console.error(`Component not found for route '${routeName}'`);
                } else {
                    routerOptions.routes.push({
                        path: route.path,
                        component: routeComponent,
                        name: routeName,
                        props: route.props,
                    });
                }
            }

            app.use(createRouter(routerOptions));
        },
    };
}
