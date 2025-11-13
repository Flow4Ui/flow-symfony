# Flow-Symfony

Flow-Symfony is a powerful integration of Vue framework with Symfony, enabling seamless development of reactive
components in your Symfony applications.

## Features

- State and Store management
- Component-based architecture with attributes
- Automatic state initialization and updates
- Client-side method handling
- Integrated routing support

## Installation

Install the package via Composer:

```
composer require flow4ui/flow-symfony
```

## Usage

Here's a simple example of how to create a Todo List component using Flow-Symfony:

```php
<?php

namespace App\UI\Component\Todo;

use Flow\Attributes\{Action,Attribute,Component,Property};
use Flow\Component\AbstractComponent;
use Flow\Contract\HasInitState;
use Symfony\Component\HttpFoundation\Request;

#[Component(
    props: [
        'initialTodos'
    ],
    template: <<<'VUE'
<template>
    <div>
        <ul>
            <li v-for="todo in todos" :key="todo.id">
                {{ todo.text }}
                <button @click="removeTodo(todo.id)">Remove</button>
            </li>
        </ul>
        <input v-model="newTodo" @keyup.enter="addTodo">
        <button @click="addTodo">Add Todo</button>
    </div>
</template>
VUE
)]
class TodoList extends AbstractComponent implements HasInitState
{
    #[Property]
    public array $todos = [];

    #[Property]
    public string $newTodo = '';
    
    #[Attribute]
    public array $initialTodos = null;

    public function initState(Request $request): void
    {
        $this->todos = $this->initialTodos ?? [];
    }

    #[Action]
    public function addTodo(): void
    {
        if (!empty($this->newTodo)) {
            $this->todos[] = [
                'id' => uniqid(),
                'text' => $this->newTodo
            ];
            $this->newTodo = '';
        }
    }
    
    #[Action]
    public function removeTodo(string $id): void
    {
        $this->todos = array_filter($this->todos, fn($todo) => $todo['id'] !== $id);
    }
}
```

This example demonstrates how to create a reactive Todo List component with Flow-Symfony, showcasing state management,
property binding, and event handling.

## Documentation

For more detailed information on how to use Flow-Symfony, please refer to the
[Flow Component Library documentation](docs/flow-component-library.md).

## TODO

- [ ] Enhance the JavaScript transport library
- [ ] Refine the manager
    - [ ] Extract the server-side transport logic into a class
- [ ] Implement Expression Language for client-side code compilation and validation
- [ ] Add support for styles
- [ ] Implement server-side rendering
- [ ] Add more options to the flow_options template function
  - [ ] Load components asynchronously from URL
  - [ ] Load components from a CDN or route
- [ ] Document computed properties and `$watch` helpers in templates
- [ ] Extend the runtime to expose computed properties and `$watch` registrations

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the GPLv3 License.