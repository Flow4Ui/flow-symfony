import {
    createBlock,
    createCommentVNode,
    createElementBlock,
    createElementVNode,
    createTextVNode,
    createVNode,
    defineComponent,
    Fragment,
    h,
    inject,
    isReactive,
    mergeProps,
    nextTick,
    normalizeClass,
    normalizeProps,
    normalizeStyle,
    openBlock,
    reactive,
    renderList,
    renderSlot,
    resolveComponent,
    resolveDirective,
    resolveDynamicComponent,
    toDisplayString,
    toRef,
    toRefs,
    withCtx,
    withDirectives,
    withKeys,
    withModifiers,
} from 'vue';


import {debounce, throttle} from "lodash-es";


import {createFlowRouter} from "./router";

const _Vue = {
    createVNode,
    defineComponent,
    h,
    inject,
    isReactive,
    reactive,
    resolveComponent,
    resolveDirective,
    toRef,
    toRefs,
    withDirectives,
    withModifiers,
    renderList,
    renderSlot,
    openBlock,
    createBlock,
    createElementBlock,
    createElementVNode,
    createTextVNode,
    toDisplayString,
    createCommentVNode,
    withCtx,
    resolveDynamicComponent,
    mergeProps,
    withKeys,
    nextTick,
    normalizeClass,
    normalizeProps,
    normalizeStyle,
    Fragment,
}

// Check the console for the AST
function c(name, attr, children, components) {
    let type = typeof name === 'string' ? (components[name] || resolveComponent(name)) : name;
    if (typeof components[name] === 'undefined') {
        components[name] = type || name;
    }

    return createVNode(type, attr, children);
}

function wd(component, directives, appDirectives) {
    for (let i = 0; i < directives.length; i++) {
        directives[i][0] = appDirectives[directives[i][0]] || resolveDirective(directives[i][0]);
    }
    return withDirectives(component, directives);
}

window.FlowOptions = window.FlowOptions || {
    definitions: {},
    autoloadComponents: "*",
    mount: '#flow-container',
    mainComponent: null,
};

export function createFlow(flowOptions = {}) {
    flowOptions = flowOptions ? {
        autoloadComponents: null,
        whenReady: null,
        definitions: null,
        ...flowOptions
    } : window.FlowOptions;
    return {
        install(app, options) {
            let bridge = new Bridge(app);
            let promise = null;

            app.config.globalProperties.$flow = bridge;
            bridge.loadDefinitions(flowOptions.definitions);
            if (flowOptions.autoloadComponents) {
                promise = bridge.useComponents(flowOptions.autoloadComponents);
            }

            if (flowOptions.router && flowOptions.router.enabled) {
                app.use(createFlowRouter(flowOptions.router));
            }

            if (flowOptions.whenReady) {
                if (promise == null) {
                    flowOptions.whenReady(app);
                } else {
                    promise.then(flowOptions.whenReady)
                }
            }
        }
    }
}

class Batch {

    url = null;

    callId = 0;
    storeId = 0;

    promises = [];

    stores = {};
    states = {};
    components = {};


    actions = [];

    counter = 0;

    constructor(url, options = {}) {
        this.url = url;
        this.options = options;
    }

    appendInvokeAction(ctx, state, action, args) {
        let callId = this.callId = this.promises.length;
        let promise = this.appendPromise(response => response.actions[callId].return);

        this.actions.push({
            isStore: ctx.isStore,
            instanceId: ctx.instanceId,
            action,
            args,
        });
        this.appendRefresh(ctx, state, false);
        return promise;
    }

    appendRefresh(ctx, state, awaitForChanges = true, isNew = false) {
        ctx = ctx || state.flowCtx();
        const name = ctx.name;
        this.counter++;
        const instanceId = ctx.instanceId;
        let promise;

        if (ctx.isStore) {
            if (!this.stores[instanceId]) {
                this.stores[instanceId] = {name, state, isNew, ctx};
            }
        } else {
            if (!this.states[instanceId]) {
                this.states[instanceId] = {name, state, isNew, ctx};
            }
        }

        if (awaitForChanges) {
            promise = this.appendPromise(response => state);
        }
        return promise
    }

    appendPromise(handler) {
        return new Promise((resolve, reject) => {
            this.promises.push({handler, resolve, reject})
        });
    }

    async appendStoreDefinition(storeKey) {
        //todo: register store definition request
    }

    async appendStateDefinition(stateKey) {
        //todo: register state definition request
    }

    appendComponentDefinition(components) {
        let importAll = false;

        if (components === '*') {
            //todo: pass all definition request into this.definitions object
            this.components[components] = {};
            components = {[components]: {}};
        } else {
            this.components = {...this.components, ...components};
        }

        if (components['*']) {
            importAll = true;
        }

        return this.appendPromise((returnContext) => {
            if (importAll) {
                return returnContext['components'];
            }
            let componentsDef = {};
            for (const componentsKey in (importAll ? returnContext['components'] : components)) {
                componentsDef[componentsKey] = returnContext['components'][componentsKey];
            }
            return componentsDef;
        });
    }

    async execute(flow) {
        let requestContext;
        let definitions = flow.definitions;

        if (definitions) {
            requestContext = {
                stores: {},
                states: {},
                actions: this.actions,
                components: this.components,
            };

            for (const instanceId in this.states) {
                let stateName = this.states[instanceId].name;
                let stateIsNew = this.states[instanceId].isNew;
                if (!definitions || !definitions.states[stateName]) {
                    this.appendStateDefinition(stateName);
                    requestContext.states[instanceId] = this.states[instanceId];
                    continue;
                }
                const properties = definitions.states[stateName].properties;
                let sendingData = requestContext.states[instanceId] = {name: stateName, state: {}, isNew: stateIsNew};
                for (const propertiesKey in properties) {
                    let property = properties[propertiesKey];
                    if (property.store) {
                        this.appendRefresh(null, flow.useStore(property.store), false);
                    } else if (property.direction === 'Server' || property.direction === 'Booth') {
                        sendingData.state[propertiesKey] = this.states[instanceId].state[propertiesKey];
                    }
                }
            }

            for (const instanceId in this.stores) {
                let storeName = this.stores[instanceId].name;
                let storeIsNew = this.stores[instanceId].isNew;
                if (!definitions || !definitions.stores[storeName]) {
                    this.appendStoreDefinition(storeName);
                    requestContext.stores[instanceId] = this.stores[instanceId];
                    continue;
                }
                const properties = definitions.stores[storeName].properties;
                let sendingData = requestContext.stores[instanceId] = {name: storeName, state: {}, isNew: storeIsNew};
                for (const propertiesKey in properties) {
                    let property = properties[propertiesKey];
                    if (property.direction === 'Server' || property.direction === 'Booth') {
                        sendingData.state[propertiesKey] = this.stores[instanceId].state[propertiesKey];
                    }
                }
            }


        } else {
            requestContext = {
                stores: this.stores,
                states: this.states,
                actions: this.actions,
                components: this.components,
            };
        }

        let returnContext = await fetch(this.url, {
            method: 'POST',
            headers: {"Content-type": "application/json;charset=UTF-8"},
            //body: JSON.stringify(requestContext, (k, v) => v === null ? '@@@null@@@' : v).replace(JSON.stringify('@@@null@@@'), 'null'),
            body: JSON.stringify(requestContext, (k, v) => v === undefined ? null : v),
        }).then(r => r.json());

        if (typeof returnContext.definitions !== 'undefined') {
            definitions = returnContext.definitions;
            flow.definitions = {...flow.definitions, ...definitions};
        }

        // apply modifications on stores
        for (const storesKey in returnContext.stores) {
            this.assignStore(storesKey, returnContext, flow);
        }

        // apply modifications on state instances
        for (const statesKey in returnContext.states) {
            this.assignState(statesKey, returnContext, flow);
        }

        for (let i = 0; i < this.promises.length; i++) {
            this.promises[i].resolve(this.promises[i].handler(returnContext));
        }

        return true;
    }

    invokeCallback(callback, context) {
        const fnParts = callback.fn.split('.');
        const fnName = fnParts.pop();
        for (const part of fnParts) {
            context = context[part];
            if (context === undefined || context === null) {
                console.error(`Object path ${callback.fn} is not valid.`);
                return;
            }
        }
        if (typeof context[fnName] === 'function') {
            context[fnName].call(context, ...callback.args);
        } else {
            console.error(`Function ${callback.fn} is not valid.`);
        }
    }

    assignState(statesKey, returnContext, flow) {
        const assignState = flow.assignState(
            this.states[statesKey].name,
            this.states[statesKey].state,
            returnContext.states[statesKey].state
        );


        if (returnContext.states[statesKey].callbacks) {
            for (const callback of returnContext.states[statesKey].callbacks) {
                this.invokeCallback(callback, this.states[statesKey].ctx.instance);
            }

        }

        return assignState;
    }

    assignStore(storesKey, returnContext) {
        Object.assign(this.stores[storesKey].state, returnContext.stores[storesKey].state);

        if (returnContext.stores[storesKey].callbacks) {
            for (const callback of returnContext.stores[storesKey].callbacks) {
                this.invokeCallback(callback, this.stores[storesKey].state);
            }
        }
    }

}

function newNumericStringGenerator() {
    let counter = 0;
    return (name) => name + (++counter);
}

export class Bridge {
    url = '/data/store';

    stores = {};
    metadata = {};

    batch = null;
    idGenerator = newNumericStringGenerator();

    batchTime = 10;

    jobs = {};

    definitions = {
        states: {},
        stores: {},
        components: {},
    };
    components = {};

    $app = null;
    throttle = throttle;
    debounce = debounce;

    constructor(app) {
        this.$app = app;
    }


    beginBatch(options) {
        if (!this.batch) {
            let newBatch = new Batch(this.url, options);
            this.batch = newBatch;
            setTimeout(async () => {
                if (newBatch.counter) await (this.batch === newBatch ? this.executeBatch() : newBatch.execute(this))
            }, this.batchTime);
            // todo: implement nextTick for better integration with vue
            // nextTick(() => {
            //     if (newBatch.counter) (this.batch === newBatch ? this.executeBatch() : newBatch.execute(this));
            // });
        }
        return this.batch;
    }

    async executeBatch() {
        let apply = this.batch.execute(this);
        this.batch = null;
        return await apply;
    }

    async refresh(ctx, state, awaitForChanges = true, isNew = false) {
        this.beginBatch();
        return this.batch.appendRefresh(ctx, state, awaitForChanges, isNew);
    }

    assignState(stateName, target, source = {}) {
        if (typeof this.definitions.states[stateName] !== 'undefined') {
            for (const propertyKey in this.definitions.states[stateName].properties) {
                let property = this.definitions.states[stateName].properties[propertyKey];
                if (property.store) {
                    target[propertyKey] = property.property ? toRef(this.useStore(property.store), property.property) : this.useStore(property.store);
                } else if (typeof source[propertyKey] !== 'undefined') {
                    target[propertyKey] = source[propertyKey];
                }
            }
        } else {
            Object.assign(target, source);
        }
        return target;
    }

    makeStore(name, currentState, isNew = false) {
        const instanceId = name;
        const self = this;
        let storageManager = null;

        let definition = this.definitions.stores[name];
        let initial = typeof definition.state !== 'undefined' ? definition.state : {};

        currentState = {...initial, ...currentState};

        let _this = null;

        for (const methodName in definition.methods) {
            let method = definition.methods[methodName];
            method.func = typeof method.func === 'function' ? method.func : new Function(...method.params, method.func);
            currentState[methodName] = method.func;
        }

        let ctx = reactive({
            instanceId,
            name,
            isStore: true,
        })

        _this = reactive({
            ...currentState,

            flowCtx() {
                return ctx;
            },

            andRefresh(storage) {
                let __this = isReactive(this) ? this : _this;
                __this.andRefreshAsync(storage);
                return __this;
            },
            async andRefreshAsync(storage) {
                let __this = this.$el ? this : _this;
                if (storage) {
                    storageManager = storage;
                    const currentState = storageManager.getItem('flow.storage.' + instanceId);
                    if (currentState) {
                        if (isStore) {
                            for (const currentStateKey in currentState) {
                                __this[currentStateKey] = currentState[currentStateKey];
                            }
                        } else {
                            self.assignState(name, __this, currentState);
                        }
                        return __this;
                    }
                }
                return await self.refresh(ctx, __this);
            },
            async invoke(action, args, immediate = false) {
                let __this = this.$el ? this : _this;
                let result = await self.invoke(ctx, __this, action, args, immediate);
                if (storageManager) {
                    storageManager.setItem('flow.storage.' + instanceId, JSON.stringify(this));
                }
                return result;
            }
        });


        if (isNew && definition && definition.init) {
            self.refresh(ctx, _this, true, true);
        } else if (definition && definition.awake) {
            _this.andRefresh();
        }

        return _this;
    }

    makeState(name, currentState, isNew = false) {
        const instanceId = this.idGenerator(name);
        const self = this;
        let storageManager = null;

        let definition = typeof this.definitions.states[name] !== 'undefined' ? this.definitions.states[name] : {};

        let initial = typeof definition.state ? definition.state : {};


        let ctx = ({
            instanceId,
            name,
            isStore: false,
            loading: false,
            callbacks: [],
        });

        currentState = {...initial, ...currentState};
        currentState = this.assignState(name, {}, currentState);
        let _this = null;

        let methods = {
            flowCtx() {
                return ctx
            },
            andRefresh(storage) {
                let __this = isReactive(this) ? this : _this;
                __this.andRefreshAsync(storage)
                return __this;
            },
            async andRefreshAsync(storage) {
                let __this = this.$el ? this : _this;
                if (storage) {
                    storageManager = storage;
                    const currentState = storageManager.getItem('flow.storage.' + instanceId);
                    if (currentState) {
                        if (isStore) {
                            for (const currentStateKey in currentState) {
                                __this[currentStateKey] = currentState[currentStateKey];
                            }
                        } else {
                            self.assignState(name, __this, currentState);
                        }
                        return __this;
                    }
                }
                return await self.refresh(ctx, __this);
            },
            async invoke(action, args, immediate = false) {
                let __this = this.$el ? this : _this;
                let result = await self.invoke(ctx, __this, action, args, immediate);
                if (storageManager) {
                    storageManager.setItem('flow.storage.' + instanceId, JSON.stringify(this));
                }
                return result;
            }
        };


        for (const methodName in definition.methods) {
            let method = definition.methods[methodName];
            method.func = typeof method.func === 'function' ? method.func : new Function(...method.params, method.func);
            methods[methodName] = method.func;
        }

        _this = reactive({
                ...currentState,
                ...methods,
            }
        );

        ctx.instance = _this;


        if (isNew && definition && definition.init) {
            self.refresh(ctx, _this, true, true);
        } else if (definition && definition.awake) {
            _this.andRefresh();
        }

        return _this;
    }

    async invoke(ctx, state, action, args, immediate) {
        let batch = this.beginBatch();
        ctx = ctx || state.flowCtx();

        const promise = batch.appendInvokeAction(ctx, state, action, args)
        if (immediate) {
            await (this.batch === batch ? this.executeBatch() : batch.execute(this));
        }
        return promise;
    }

    async useComponents(componentsLoader) {
        let batch = this.beginBatch();
        this.batch = null;
        let components = batch.appendComponentDefinition(componentsLoader);
        await batch.execute(this);
        components = await components;
        for (const componentKey in components) {
            if (!this.definitions.components[componentKey]) {
                this.defineComponent(components[componentKey], componentKey);
            }
        }
        return components;
    }

    defineComponent(component, componentKey) {
        const render = typeof component.render === 'function' ? component.render : new Function('h', 'c', 'wm', 'wd', 'rc', component.render);
        const storage = null;
        const self = this;


        const lifecycle = {};
        const methods = {};
        const watch = {};

        for (const methodName in component.methods) {
            const eventMethod = component.methods[methodName];
            const fn = typeof eventMethod.func === 'function'
                ? eventMethod.func
                : new Function(...eventMethod.params, eventMethod.func);

            switch (eventMethod.methodType) {
                case 'lifecycleEvent':
                    lifecycle[methodName] = fn;
                    break;
                case 'watch':
                    watch[methodName] = fn;
                    break;
                default:
                    // Handle unexpected methodType if needed
                    break;
            }
        }


        const originalCreatedMethod = lifecycle.created;
        lifecycle.created = function () {
            this.flowCtx().instance = this;
            if (originalCreatedMethod) originalCreatedMethod.call(this); // Call the original created method
        };

        this.definitions.components[componentKey] = defineComponent({
                name: componentKey,
                props: component.props || {},
            setup(props, context) {
                return self.useState(component.stateId, {
                    ...component.state,
                    ...toRefs(props),
                });
            },
                render() {
                    let _wd = (component, directives) => wd(component, directives, this.$.appContext.directives);
                    return render.call(this, h, c, withModifiers, _wd, _Vue, this.$.appContext.components);
                },
                ...lifecycle,
                watch,
            }
        );
        this.$app.component(component.name, this.definitions.components[componentKey]);
    }

    useState(stateName, currentState = null, storage = null) {
        let runtimeState = this.makeState(stateName, currentState, !storage);
        if (storage) runtimeState.state.andRefresh(storage);
        return runtimeState;
    }

    useStore(storeName, currentState = null, storage = null) {
        if (typeof this.stores[storeName] === 'undefined') {
            let newState = this.makeStore(storeName, currentState, true);
            this.stores[storeName] = storage ? newState.andRefresh(storage) : newState;
        }
        return this.stores[storeName];
    }

    loadDefinitions(definitions) {
        if (definitions.components) {
            let components = definitions.components;
            for (const componentKey in components) {
                if (!this.definitions.components[componentKey]) {
                    this.defineComponent(components[componentKey], componentKey);
                }
            }
        }
        if (definitions.stores) {
            this.definitions.stores = {...this.definitions.stores, ...definitions.stores};
        }
        if (definitions.states) {
            this.definitions.states = {...this.definitions.states, ...definitions.states};
        }
    }
}