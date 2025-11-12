# Flow UI Component Library

Flow is a UI component library that compiles components into JavaScript Vue 3 applications and transparently manages states and actions.

## Key Features

- Transparent state management
- Vue 3 integration
- Role-based security
- Multiple transport options
- Event-driven architecture

## Configuration

```yaml
# config/packages/flow.yaml
flow:
  cache:
    enabled: true
    directory: '%kernel.cache_dir%/flow'
  security:
    action_role_map:
      # Global action restrictions
      'deleteUser': 'ROLE_ADMIN'
      # Component-specific restrictions
      'UserManager.createUser': 'ROLE_ADMIN'
      # Wildcard restrictions for component
      'UserManager.*': 'ROLE_EDITOR'
```

## Events

The Flow Manager dispatches events during its lifecycle:

- `flow.pre_action`: Before any actions are processed
- `flow.post_action`: After all actions are processed
- `flow.before_action_invoke`: Before invoking a specific action method
- `flow.after_action_invoke`: After invoking a specific action method

## Transport System

Transports handle the communication between client and server. The default transport is `AjaxJsonTransport`.

You can implement custom transports by extending `AbstractTransport`.

## Security

Flow provides role-based security for actions. You can define role requirements per action or per component.

## Example Usage

```php
// src/Controller/FlowController.php
class FlowController extends AbstractController
{
    private Manager $flowManager;

    public function __construct(Manager $flowManager)
    {
        $this->flowManager = $flowManager;
    }

    #[Route('/flow/handle', name: 'flow_handle')]
    public function handle(Request $request): Response
    {
        return $this->flowManager->handle($request);
    }
}
```
