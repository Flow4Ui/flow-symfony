# Flow Component Library Documentation

Flow provides a server-driven component model that lets Symfony applications author UI in PHP while rendering interactive Vue 3 components in the browser. The Flow manager compiles component metadata, coordinates state updates, and communicates with the client through pluggable transports so that back-end code can drive a reactive front end.

## Table of contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Core concepts](#core-concepts)
    - [Components](#components)
    - [State, stores, and properties](#state-stores-and-properties)
    - [Actions and lifecycle hooks](#actions-and-lifecycle-hooks)
    - [Client-side methods](#client-side-methods)
    - [Template compilation](#template-compilation)
    - [Routing](#routing)
    - [Security](#security)
    - [Transports](#transports)
    - [Event flow](#event-flow)
    - [Caching and warmup](#caching-and-warmup)
    - [Twig helpers](#twig-helpers)
    - [Assets](#assets)
4. [Rendering Flow components](#rendering-flow-components)
5. [Next steps](#next-steps)

## Installation

1. Install the bundle with Composer:

   ```bash
   composer require flow4ui/flow-symfony
   ```

2. Register the bundle in `config/bundles.php` if your project does not use Symfony's auto-registration yet:

   ```php
   return [
       Flow\FlowBundle::class => ['all' => true],
   ];
   ```

3. Run database/cache warmup as usual so that autoconfiguration can register tagged services and cache compiled templates.

## Configuration

The Flow configuration tree exposes router, cache, and security options. A typical `config/packages/flow.yaml` file looks like this:

```yaml
flow:
  router:
    enabled: true
    mode: history   # or "hash" / "memory"
    base: null
  cache:
    enabled: true
    dir: '%kernel.cache_dir%/flow'
  security:
    component_security: false
    login_route: null         # string path or { name: 'route_name', params: {...} }
    unauthorized_route: null  # string path or { name: 'route_name', params: {...} }
    action_role_map:
      'deleteUser': 'ROLE_ADMIN'
      'UserManager.createUser': ['ROLE_ADMIN', 'ROLE_EDITOR']
      'UserManager.*': 'ROLE_EDITOR'
```

The tree is defined in `FlowConfiguration` and is processed by the bundle extension to set container parameters and service arguments.【F:src/DependencyInjection/FlowConfiguration.php†L11-L38】【F:src/DependencyInjection/FlowExtension.php†L34-L74】

## Core concepts

### Components

Components are PHP classes annotated with `#[Component]` and usually extend `AbstractComponent`. The attribute lets you configure the public name, incoming props, associated state identifier, and template definition (inline markup or a file path). When no `state:` is provided Flow treats the component class itself as the backing state object, so public properties declared on the component become part of the serialized payload automatically.【F:src/Attributes/Component.php†L6-L24】【F:src/Component/AbstractComponent.php†L11-L33】

Projects commonly embed the template on the component attribute. Use the `template:` argument to provide Vue-compatible markup (typically wrapped in a `<template>` root) directly as a PHP string. When you prefer an external file, provide `templatePath:` with the relative path to an HTML template—Flow accepts files with or without an outer `<template>` tag, but the contents must be Vue-flavoured HTML rather than Twig. Flow reads the inline string or file contents before compiling them for the client runtime.【F:src/Attributes/Component.php†L6-L24】【F:src/Service/Manager.php†L499-L544】

At build time the `Registry` collects every service tagged with `flow.component`, so registering the attribute is enough when autoconfiguration is enabled. The bundle adds a compiler pass to process component attributes during container build.【F:src/DependencyInjection/FlowExtension.php†L34-L63】【F:src/FlowBundle.php†L5-L15】

### Example component walkthrough

The following snippets show how Flow's core features—state, stores, routing, actions, and security—fit together in a realistic component. The example models a counter dashboard that exposes a secured increment action and a public reset action.

Define a shared store that holds the counter and exposes an authenticated action:

```php
use Flow\Attributes\{Action, Property, Store};
use Flow\Contract\HasInitState;

#[Store(name: 'CounterStore')]
class CounterStore implements HasInitState
{
    #[Property]
    public int $count = 0;

    public function initState(): void
    {
        // Pre-populate the store when the component boots.
        $this->count = 5;
    }

    #[Action(roles: ['ROLE_USER'])]
    public function increment(int $step = 1): void
    {
        // Only authenticated users with ROLE_USER can call this action.
        $this->count += $step;
    }
}
```

Attach the store to a per-component state object. The `StoreRef` attribute synchronizes the component with the shared store:

```php
use Flow\Attributes\{Property, State, StoreRef};

#[State(name: 'CounterState')]
class CounterState
{
    #[Property]
    public string $title = 'Team counters';

    #[StoreRef(store: 'CounterStore')]
    public CounterStore $counter;
}
```

Finally, wire everything together in a component that registers routes and additional actions:

```php
use Flow\Attributes\{Action, Component, Router};
use Flow\Component\AbstractComponent;

#[Component(
    name: 'counter-dashboard',
    state: CounterState::class,
    templatePath: '@app/components/counter-dashboard.html'
)]
#[Router(path: '/counters', name: 'counter.dashboard')]
class CounterDashboardComponent extends AbstractComponent
{
    public function __construct(public CounterState $state) {}

    #[Action]
    public function reset(): void
    {
        // Public action that clears the shared counter store.
        $this->state->counter->count = 0;
    }
}
```

The template file (or an inline string provided through `template:`) can now reference both actions. For example, `@app/components/counter-dashboard.html` might contain the following markup. Flow also injects an `actions` proxy into the template scope so you can call methods instead of using the `invoke:action` modifier when that reads clearer, and it supports inline `<script>` blocks for Vue-compatible helpers:

```html

<template>
    <section>
        <h1>{{ state.title }}</h1>
        <p class="count">Current count: {{ state.counter.count }}</p>

        <button v-on:click="state.counter.increment(3)">
            Increment by 3
        </button>

        <button @click="reset()">
            Reset counter
        </button>
    </section>
</template>

<script>
    export default {
        methods: {
            boostTwice() {
                this.state.counter.increment(2);
            }
        },
        computed: {
            isHigh() {
                return this.state.counter.count >= 10;
            }
        },
        watch: {
            'state.counter.count'(value) {
                if (value > 20) {
                    this.reset();
                }
            }
        }
    };
</script>
```

When a user with `ROLE_USER` clicks **Increment by 3**, Flow routes the request to `CounterStore::increment()` and applies the role guard before mutating the store. The **Reset counter** button calls `CounterDashboardComponent::reset()` without additional authorization. Because the component is also annotated with `#[Router]`, Flow publishes route metadata so the Vue router can mount this dashboard at `/counters`.

### State, stores, and properties

State classes describe the data exposed to the client. Mark any service with `#[State]` or `#[Store]` to have it tagged automatically. `Store` extends `State` and is typically used for shared application state. Both attributes support binding all public properties and configuring refresh cadence or serializer options.【F:src/Attributes/State.php†L6-L27】【F:src/Attributes/Store.php†L6-L19】

Expose specific fields with `#[Property]` or `#[Attribute]` to control the direction of synchronization between server and client. Use `#[StoreRef]` to reference another store from a property. The registry inspects these attributes, generating canonical names, wiring props back to components, and ensuring references resolve to existing stores.【F:src/Attributes/Property.php†L6-L17】【F:src/Attributes/Attribute.php†L6-L13】【F:src/Attributes/StoreRef.php†L6-L16】【F:src/Service/Registry.php†L45-L137】

### Actions and lifecycle hooks

Declare component or store methods that mutate state with `#[Action]`. The manager resolves incoming action requests, denormalizes typed arguments, checks authorization, invokes the method, and serializes updated state back to the client.【F:src/Service/Manager.php†L99-L218】

Flow provides optional lifecycle interfaces to hook into state handling: `HasInitState`, `HasUpdateState`, `HasPreUpdateState`, `HasPreAction`, and `HasPostAction`. Implement them on state or store classes to initialize data, validate requests, or react after actions complete. The manager calls these hooks as part of the request pipeline.【F:src/Contract/HasInitState.php†L5-L9】【F:src/Contract/HasUpdateState.php†L5-L9】【F:src/Service/Manager.php†L221-L341】【F:src/Service/Manager.php†L612-L670】

### Client-side methods

When components or stores implement `HasClientSideMethods`, they can register JavaScript helpers returned with the component definition. The base `AbstractComponent` exposes a `Methods` collection that Flow serializes into callable functions for the frontend runtime.【F:src/Component/AbstractComponent.php†L13-L33】【F:src/Service/Manager.php†L476-L512】

### Template compilation

Flow compiles Vue-flavoured templates into render functions. The `Compiler` parses HTML-like markup, supports Vue directives such as `v-if`, `v-for`, dynamic bindings, slot syntax, and extracts inline `<script>` blocks so they can be injected on the client. Within those scripts you can register `methods`, `computed` values, `$watch` handlers, or call the injected `actions` proxy to invoke server actions from JavaScript helpers. Event modifiers like `v-on:click.invoke:action` are translated into Flow invocations, while throttling and debouncing helpers wrap handlers automatically.【F:src/Template/Compiler.php†L8-L170】【F:src/Template/Compiler.php†L171-L266】

### Routing

Annotate classes with `#[Router]` to describe client-side routes. Registry stores each route definition, and router metadata is bundled into the serialized Flow options that hydrate the Vue router in the browser. Enable the router globally with `flow.router.enabled` and configure the mode or base path as needed.【F:src/Attributes/Router.php†L6-L16】【F:src/Service/Registry.php†L20-L33】【F:src/Service/Manager.php†L362-L404】

### Security

Flow ships with a role-based security adapter. Configure an action-to-role map in `flow.security.action_role_map`, or attach roles directly on an action attribute. The `RoleBasedSecurity` service checks the active user's roles before allowing a method to execute.【F:src/Security/RoleBasedSecurity.php†L9-L70】【F:src/DependencyInjection/FlowExtension.php†L65-L74】

You can also secure components when enabled in configuration. Set `flow.security.component_security: true`, optionally map roles via `component_role_map`, and declare redirects for unauthorized users:

```yaml
flow:
  security:
    component_security: true
    route_security: true
    login_route: '/login'
    unauthorized_route: '/forbidden'
    component_role_map:
      'admin-dashboard': 'ROLE_ADMIN'
```

Components can declare roles and denied behaviour inline:

```php
#[Component(name: 'admin-dashboard', roles: ['ROLE_ADMIN'], onDenied: 'render')]
#[Router(path: '/admin', name: 'admin.dashboard')]
class AdminDashboard extends AbstractComponent { /* ... */ }
```

When component security denies access, Flow skips lifecycle hooks such as `HasInitState`, blocks deserialization and actions for that component, and injects `authorized: false` into its state payload. By default (`onDenied: 'redirect'`) Flow will redirect authenticated users to `flow.security.unauthorized_route` and guests to `flow.security.login_route`. Set `onDenied: 'render'` to allow the component to render while exposing `$security.accessDeniedComponents` on the client for custom handling. Router metadata is filtered based on component access.【F:src/Attributes/Component.php†L6-L25】【F:src/Service/Manager.php†L145-L200】【F:src/Security/RoleBasedSecurity.php†L9-L90】

### Transports

Transports abstract how requests and responses move between browser and server. The default `AjaxJsonTransport` expects POSTed JSON payloads and returns JSON responses, but you can provide another service implementing the `Transport` contract to support alternative channels (e.g., WebSockets or SSE).【F:src/Transport/AjaxJsonTransport.php†L9-L58】【F:src/Contract/Transport.php†L1-L20】【F:src/Resources/config/services.php†L18-L33】

### Event flow

The manager dispatches Symfony events before and after every request as well as around each action invocation, allowing listeners to extend Flow's behaviour (logging, analytics, etc.). Listen to `flow.pre_action`, `flow.post_action`, `flow.before_action_invoke`, and `flow.after_action_invoke` for granular hooks.【F:src/Service/Manager.php†L545-L613】【F:src/Event/PreActionEvent.php†L5-L18】【F:src/Event/PostActionEvent.php†L5-L18】【F:src/Event/BeforeActionInvokeEvent.php†L5-L20】【F:src/Event/AfterActionInvokeEvent.php†L5-L20】

### Caching and warmup

When caching is enabled, compiled templates are saved under the Flow cache directory. Subsequent renders reuse the cached PHP payload unless the template hash changes. The `FlowComponentCacheWarmer` precompiles every registered component during Symfony's `cache:warmup` command so production deployments start with warm caches.【F:src/Service/Manager.php†L420-L535】【F:src/Service/FlowComponentCacheWarmer.php†L9-L59】

### Twig helpers

Two Twig helpers simplify bootstrapping Flow in templates. `{{ flow_options({...}) }}` returns a JSON blob of component, state, and router definitions, while `{{ flow_loader() }}` emits a `<script>` tag that assigns the same structure to `window.FlowOptions`. Both call `Manager::compileJsFlowOptions()` under the hood.【F:src/Service/FlowTwigExtension.php†L9-L40】【F:src/Service/Manager.php†L356-L413】

### Assets

Ship the Flow runtime alongside your application's frontend assets by running the install command:

```bash
php bin/console flow:install-assets assets --force
```

The command checks for Encore, Vue, and Vue Router, optionally installs missing dependencies, and copies the Flow library files into your asset tree while printing follow-up build steps.【F:src/Command/InstallFlowAssetsCommand.php†L6-L134】

When server-side rendering is enabled, make sure your build also ships the Vue server renderer bundle so SSR HTML can stream scoped styles immediately. The SSR entrypoint lives under `assets/flow/index.js` and depends on `@vue/server-renderer`; run `yarn build` (or the equivalent Encore production build) after `flow:install-assets` so the generated `flow-main.*.js` bundle is available to `SsrRenderer`.【F:src/Service/SsrRenderer.php†L8-L88】【F:package.json†L1-L21】

## Rendering Flow components

Once a component and its state are registered, expose an endpoint that delegates to the Flow manager. The README example below renders a Todo list component by injecting the manager and forwarding the HTTP request.【F:src/README.md†L23-L83】

```php
#[Route('/flow/handle', name: 'flow_handle')]
public function handle(Request $request): Response
{
    return $this->flowManager->handle($request);
}
```

Use the generated Flow options to hydrate the Vue application on the client and let Flow synchronize state between server actions and the browser runtime.

## Next steps

- Explore the `TODO` section in the project README for upcoming features and areas where contributions are welcome.【F:README.md†L67-L93】
- Review the PHP unit tests under `tests/` for additional usage scenarios and integration guidance.
