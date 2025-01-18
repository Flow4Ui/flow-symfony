<?php

namespace Flow\Service;

use Flow\Attributes\{Component, Property, State, Store, StoreRef};
use Flow\Component\Context;
use Flow\Contract\{ComponentBuilderInterface,
    HasCallbacks,
    HasClientSideMethods,
    HasInitState,
    HasPostAction,
    HasPreAction,
    HasPreUpdateState,
    HasUpdateState,
    MethodType
};
use Flow\Enum\Direction;
use Flow\Exception\FlowException;
use Flow\Template\Compiler;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, RequestStack, Response};
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\{Authentication\Token\Storage\TokenStorageInterface,
    Authorization\AuthorizationCheckerInterface
};
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Serializer\Context\Normalizer\ObjectNormalizerContextBuilder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\{AbstractObjectNormalizer, DenormalizerInterface, NormalizerInterface};
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Twig\Environment;

class Manager implements ServiceSubscriberInterface
{


    protected Registry $registry;
    protected NormalizerInterface $normalizer;
    protected DenormalizerInterface $denormalizer;
    protected ContainerInterface|null $container = null;

    protected array $stateInstances = [];
    protected array $storeInstances = [];
    protected array $componentInstances = [];

    protected Environment $environment;

    protected Request|null $request = null;

    protected CacheItemPoolInterface $adapter;

    /**
     * Whether or not to enable template caching
     */
    protected bool $cacheEnabled;
    protected string $cacheDirectory;


    public function __construct(
        #[TaggedIterator('flow.state')] iterable     $stores,
        #[TaggedIterator('flow.component')] iterable $components,
        NormalizerInterface                          $normalizer,
        DenormalizerInterface                        $denormalizer,
        Registry                                     $registry,
        Environment                                  $environment,

        bool   $cacheEnabled,
        string $cacheDir
        //Transport                                $transport,
    )
    {

        $this->registry = $registry;
        $this->normalizer = $normalizer;
        $this->denormalizer = $denormalizer;

        foreach ($stores as $store) {
            $this->stateInstances[get_class($store)] = $store;
        }
        foreach ($components as $component) {
            $this->componentInstances[get_class($component)] = $component;
        }
        $this->environment = $environment;

        // Suppose your flow config is read into 'flow.cache.enabled'
        // Reading from config:
        $this->cacheEnabled = $cacheEnabled;
        $this->cacheDirectory = $cacheDir;
        if ($this->cacheEnabled && !is_dir($this->cacheDirectory)) {
            @mkdir($this->cacheDirectory, 0777, true);
        }
    }

    public static function getSubscribedServices(): array
    {
        return [
            'router' => '?' . RouterInterface::class,
            'request_stack' => '?' . RequestStack::class,
            'http_kernel' => '?' . HttpKernelInterface::class,
            'serializer' => '?' . SerializerInterface::class,
            'security.authorization_checker' => '?' . AuthorizationCheckerInterface::class,
            'twig' => '?' . Environment::class,
            'form.factory' => '?' . FormFactoryInterface::class,
            'security.token_storage' => '?' . TokenStorageInterface::class,
            'security.csrf.token_manager' => '?' . CsrfTokenManagerInterface::class,
            'parameter_bag' => '?' . ContainerBagInterface::class,
        ];
    }

    #[Required]
    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }

    /**
     * @throws ExceptionInterface
     * @throws \ReflectionException
     * @throws FlowException
     */
    public function handle(Request $request): Response
    {

        $statusCode = Response::HTTP_OK;
        $this->request = $request;

        if (!$request->isMethod('POST')) {
            throw new FlowException('Flow http transport is server only POST method');
        }

        $requestContext = json_decode($request->getContent(), true);
        $instances = [];
        $metadata = [];
        $this->storeInstances = [];
        $returnContext = [];

        foreach ($requestContext['stores'] as $instanceId => $store) {
            $storeDefinition = $this->registry->getStoreDefinition($store['name']);
            if ($storeDefinition === null) {
                throw new FlowException(sprintf('Loading store: %s not defined', $store['name']));
            }
            $metadata[$instanceId] = $storeDefinition;
            $this->storeInstances[$store['name']] = $instances[$instanceId] = $this->makeInputState($storeDefinition, $store['state'] ?? [], $store['isNew'] ?? false, $request);
        }

        foreach ($requestContext['states'] as $instanceId => $state) {
            $stateDefinition = $this->registry->getStateDefinition($state['name']);

            if ($stateDefinition === null) {
                throw new FlowException(sprintf('Loading store: %s not defined', $state['name']));
            }
            $metadata[$instanceId] = $stateDefinition;
            $instances[$instanceId] = $this->makeInputState($stateDefinition, $state['state'] ?? [], $state['isNew'] ?? false, $request);
        }

        $this->invokePreactionInstances($request, $instances);

        foreach ($requestContext['actions'] as $invokeId => $action) {
            $instanceId = $action['instanceId'];
            $instanceDefinition = $metadata[$instanceId];
            $name = $action['action'];
            $actionDefinition = $instanceDefinition->actions[$name] ?? null;
            if ($actionDefinition === null) {
                throw new FlowException(sprintf('Invoking action: %s not defined in state manager: %s', $name, $instanceDefinition->name));
            }
            $args = !empty($action['args']) && is_array($action['args']) ? $action['args'] : [];
            foreach ($actionDefinition->reflectionAction->getParameters() as $argIndex => $parameter) {
                if ($parameter->hasType() && !$parameter->getType()->isBuiltin()) {
                    $typeName = $parameter->getType()->getName();
                    $args[$argIndex] = $this->denormalizer->denormalize($args[$argIndex], $typeName, context: [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true]);
                }
            }
            $returnContext['actions'][$invokeId]['return'] = $actionDefinition->reflectionAction->invokeArgs($instances[$instanceId], $args);
        }

        $this->invokePostActionInstances($request, $instances);

        foreach ($requestContext['stores'] as $instanceId => $store) {
            $returnContext['stores'][$instanceId] = $this->makeOutputState($metadata[$instanceId], $instances[$instanceId]);
        }

        foreach ($requestContext['states'] as $instanceId => $state) {
            $returnContext['states'][$instanceId] = $this->makeOutputState($metadata[$instanceId], $instances[$instanceId]);
        }

//        todo: implement lazy component loading
//        if (!empty($requestContext['components'])) {
//            $returnContext['components'] = $this->appendComponents($requestContext['components']);
//        }
//
//        if (!empty($requestContext['stores'])) {
//            $returnContext['components'] = $this->appendComponents($requestContext['components']);
//        }

        return new JsonResponse($returnContext, $statusCode);
    }

    /**
     * @param State $stateDefinition
     * @param array<string,mixed> $inputState
     * @param bool|null $isNew
     * @param Request $request
     * @return mixed
     * @throws \ReflectionException
     * @throws ExceptionInterface
     */
    protected function makeInputState(State $stateDefinition, array $inputState, bool|null $isNew, Request $request): mixed
    {
        $storeInstance = clone $this->stateInstances[$stateDefinition->className];
        $normalizedData = [];
        foreach ($stateDefinition->properties as $property) {
            if ($property instanceof StoreRef) {
                $store = $this->storeInstances[$property->store];
                $storeProperty = !empty($property->property) ?
                    $this->registry->getStoreDefinition($property->store)->properties[$property->property] ?? null :
                    null;
                $propertyValue = empty($storeProperty) ? $store : $storeProperty->reflectionProperty->getValue($store);
                $property->reflectionProperty->setValue($storeInstance, $propertyValue);
            } else if (($property->direction === Direction::Booth || $property->direction === Direction::Server) && isset($inputState[$property->name])) {
                $propertyValue = $inputState[$property->name];
                $normalizedData[$property->reflectionProperty->name] = $propertyValue;
            }
        }

        if (!$isNew && $storeInstance instanceof HasPreUpdateState) {
            $normalizedData = $storeInstance->preUpdateState($request, $normalizedData);
        }

        $this->denormalizer->denormalize($normalizedData, get_class($storeInstance), null, (new ObjectNormalizerContextBuilder())
            ->withObjectToPopulate($storeInstance)
            ->withDeepObjectToPopulate(true)
            ->withAllowExtraAttributes(true)
            ->withRequireAllProperties(false)
            ->withDisableTypeEnforcement(true)
            ->withContext($stateDefinition->denormalizeAttributes)
            ->toArray());

        if ($isNew && $storeInstance instanceof HasInitState) {
            $storeInstance->initState($request);
        }

        if ($storeInstance instanceof HasUpdateState) {
            $storeInstance->updateState($request);
        }

        return $storeInstance;
    }

    /**
     * @param State $stateDefination
     * @param object $object
     * @param mixed $stateUpdate
     * @return mixed
     * @throws ExceptionInterface
     */
    public function makeOutputState(State $stateDefination, object $object, $isDefinition = false): mixed
    {
        $stateState = [];

        foreach ($stateDefination->properties as $property) {
            if ($property instanceof Property && ($property->direction === Direction::Booth || $property->direction === Direction::Client || $isDefinition)) {
                $value = $property->reflectionProperty->getValue($object);
                if (!isset($stateUpdate['state'][$property->name]) || $stateUpdate['state'][$property->name] !== $value) {
                    $stateState[$property->name] = $value;
                }
            }
        }
        if (empty($stateState)) {
            $stateUpdate['state'] = new \stdClass();
        } else {
            $stateUpdate['state'] = $this->normalizer->normalize($stateState, null, (new ObjectNormalizerContextBuilder())
                ->withCircularReferenceLimit(1)
                ->withCircularReferenceHandler(fn($object, $format, $context) => sprintf('%s:%s', get_class($object), $object->getId()))
                ->toArray());
        }

        if ($object instanceof HasCallbacks) {
            //$stateUpdate['callbacks'] = $object->getCallbacks();
            $stateUpdate['callbacks'] = $this->normalizer->normalize($object->getCallbacks(), null, (new ObjectNormalizerContextBuilder())
                ->withCircularReferenceLimit(1)
                ->withCircularReferenceHandler(fn($object, $format, $context) => sprintf('%s:%s', get_class($object), $object->getId()))
                ->toArray());
        }

        return $stateUpdate;
    }

    public function compileJsFlowOptions($options = []): string
    {
        $definitions = [];

        $placeholders = [];

        $requestedComponents = $options['components'] ?? ['*' => []];
        if ($requestedComponents) {
            $definitions['components'] = $this->appendComponents($requestedComponents);
            foreach ($definitions['components'] as $id => &$component) {
                $placeholder = $component['name'] . 'RenderFunc' . $id;
                $placeholders[json_encode($placeholder)] = sprintf('function(h,c,wm,wd,v,rc){%s}', $component['render']);
                $component['render'] = $placeholder;

                if (!empty($component['lifecycleEventMethods'])) {
                    foreach ($component['lifecycleEventMethods'] as $methodName => &$method) {
                        $placeholder = $component['name'] . $methodName . $id;
                        $placeholders[json_encode($placeholder)] = sprintf('function(%s){%s}', implode(',', $method['params']), $method['func']);
                        $method['func'] = $placeholder;
                    }
                }
            }
        }

        $requestedStores = $options['stores'] ?? ['*' => []];
        if ($requestedStores) {
            $definitions['stores'] = $this->appendStoreDefinitions($requestedStores);
            foreach ($definitions['stores'] as $id => &$definition) {
                if (!empty($definition['methods'])) {
                    foreach ($definition['methods'] as $methodName => &$method) {
                        $placeholder = $definition['name'] . $methodName . $id;
                        $placeholders[json_encode($placeholder)] = sprintf('function(%s){%s}', implode(',', $method['params']), $method['func']);
                        $method['func'] = $placeholder;
                    }
                }
            }
        }

        $requestedStates = $options['states'] ?? ['*' => []];
        if ($requestedStates) {
            $definitions['states'] = $this->appendStateDefinitions($requestedStates);
            foreach ($definitions['states'] as $id => &$definition) {
                if (!empty($definition['methods'])) {
                    foreach ($definition['methods'] as $methodName => &$method) {
                        $placeholder = $definition['name'] . $methodName . $id;
                        $placeholders[json_encode($placeholder)] = sprintf('function(%s){%s}', implode(',', $method['params']), $method['func']);
                        $method['func'] = $placeholder;
                    }
                }
            }
        }


        $jsObject = json_encode(
            [
                'definitions' => $definitions,
                'router' => [
                    'enabled' => $this->registry->isRouterEnabled(),
                    'routes' => $this->registry->getRoutes(),
                    'mode' => $this->registry->getRouterMode(),
                    'base' => $this->registry->getRouterBase(),
                ]
            ]
        );

        if (!empty($placeholders)) {
            $jsObject = str_replace(array_keys($placeholders), array_values($placeholders), $jsObject);
        }
        return $jsObject;
    }

    /**
     * @param $requestedComponents
     * @return array
     * @throws \ReflectionException
     */
    public function appendComponents($requestedComponents): array
    {
        $componentDefinitions = [];

        $components = $requestedComponents;
        $fetchAllOptions = $components['*'] ?? null;
        $fetchAll = $fetchAllOptions !== null;
        foreach ($this->componentInstances as $name => $component) {
            $componentDefinition = $this->registry->getComponentDefinition($name);
            if ($fetchAll || !empty($components[$componentDefinition->name])) {
                $componentDefinitions[$componentDefinition->name] = $this->appendComponentDefinition($componentDefinition, $component);
            }
        }
        return $componentDefinitions;
    }

    /**
     * @throws ExceptionInterface
     * @throws \ReflectionException
     */
    protected function appendComponentDefinition(?Component $componentDefinition, object $component): array
    {
        $stateDefinition = $this->registry->getStateDefinition($componentDefinition->stateId);
        $flowContext = new Context($this->registry, $componentDefinition);
        // If the component implements a custom interface, skip
        if ($component instanceof ComponentBuilderInterface) {
            $element = $component->build($flowContext);
            $rendered = $element->render($flowContext);
        } else {
            $rendered = $this->compileAndRenderFlowTemplate($componentDefinition, $flowContext, get_class($component));
        }

        $componentClientDefinition = [
            'name' => $componentDefinition->name,
            'stateId' => $stateDefinition->name,
            'props' => $componentDefinition->props,
            'state' => $this->makeOutputState($stateDefinition, $this->stateInstances[$stateDefinition->className])['state'],
            'render' => $rendered,
        ];

        if ($component instanceof HasClientSideMethods) {
            $componentClientDefinition['methods'] = $component
                ->getClientSideMethods()
                ->getMethods($flowContext);
        }
//
//        foreach ($stateDefinition->actions as $action) {
//            $componentClientDefinition['methods'] ??= [];
//            $componentClientDefinition['methods'][$action->name] = [
//                'params' => [],
//                'func' => sprintf('this.invoke.call(\'%s\',arguments);', $action->name)
//            ];
//        }

        return $componentClientDefinition;
    }

    private function compileAndRenderFlowTemplate(Component $definition, Context $flowContext, string $className): string
    {
        // 1) Retrieve template content
        $template = $definition->template
            ?? ($definition->templatePath ? @file_get_contents($definition->templatePath) : null);

        if (!$template) {
            // No template -> no output
            return '';
        }

        // 2) If caching disabled, compile fresh each time:
        if (!$this->cacheEnabled) {
            $element = (new Compiler())->compile($template, $flowContext);
            return $element->render($flowContext);
        }

        // 3) Build a stable filename in the Flow cache directory
        $hash = md5($template);
        $nameHash = md5($className);
        $fileName = sprintf('%s_%s.php', $definition->name, $nameHash);
        // e.g. MyComponent_2f18cd4ee.php

        $filePath = rtrim($this->cacheDirectory, '/\\') . DIRECTORY_SEPARATOR . $fileName;

        // 4) Check if the file is already present
        if (is_file($filePath)) {
            // The file returns an Element or something similar
            [$rendered, $oldHash] = require $filePath;

            if ($oldHash === $hash) {
                return $rendered;
            }
            // If it's not valid or something changed, fallback to recompile
        }

        // 5) We do not have a valid cached version -> compile from scratch
        $element = (new Compiler())->compile($template, $flowContext);
        $rendered = $element->render($flowContext);

        // 6) Cache it to a .php file
        // We'll store the serialized Element. Alternatively, build real PHP code.
        $serialized = serialize([$rendered, $hash]);
        $serializedExport = var_export($serialized, true);

        $phpCode = <<<PHP
<?php

/**
 * Auto-generated by Flow Manager
 * Name: {$definition->name}
 * Hash: $hash
 */
return unserialize($serializedExport);

PHP;

        @file_put_contents($filePath, $phpCode);

        return $rendered;
    }

    /**
     * @param array $requestedStores
     * @return array
     * @throws ExceptionInterface
     * @throws \ReflectionException
     */
    protected function appendStoreDefinitions(array $requestedStores = ['*' => true]): array
    {
        $storesDefinitions = [];
        $context = new Context($this->registry);
        $stores = $requestedStores;
        $fetchAllOptions = $stores['*'] ?? null;
        $fetchAll = $fetchAllOptions !== null;
        foreach ($this->stateInstances as $name => $stateInstance) {
            $storeDefinition = $this->registry->getStoreDefinition($name);
            if ($storeDefinition instanceof Store && ($fetchAll || !empty($stores[$storeDefinition->name]))) {
                $storeMetadata = [
                    'name' => $storeDefinition->name,
                    'properties' => array_map(fn($item) => ['name' => $item->name, 'direction' => $item->direction ?? ''], $storeDefinition->properties),
                    'actions' => array_map(fn($item) => ['name' => $item->name], $storeDefinition->actions),
                    'refresh' => $storeDefinition->refresh,
                    'init' => is_a($storeDefinition->className, HasInitState::class, true),
                    'awake' => is_a($storeDefinition->className, HasUpdateState::class, true) || is_a($storeDefinition->className, HasPreUpdateState::class, true),
                    'state' => $this->makeOutputState($storeDefinition, $stateInstance, true)['state'],
                ];
                $storeMetadata['methods'] = $this->getActionsMethod($storeDefinition, $stateInstance, $context);
                if (empty($storeMetadata['methods'])) {
                    unset($storeMetadata['methods']);
                }

                $storesDefinitions[$storeDefinition->name] = $storeMetadata;
            }
        }
        return $storesDefinitions;
    }

    /**
     * @param array $requestedStates
     * @return array
     * @throws ExceptionInterface
     * @throws \ReflectionException
     */
    protected function appendStateDefinitions(array $requestedStates = ['*' => true]): array
    {
        $statesDefinitions = [];

        $states = $requestedStates;
        $fetchAllOptions = $states['*'] ?? null;
        $fetchAll = $fetchAllOptions !== null;
        foreach ($this->stateInstances as $name => $stateInstance) {
            $stateDefinition = $this->registry->getStateDefinition($name);
            $context = new Context($this->registry, $this->registry->getComponentByState($stateDefinition));
            if ($stateDefinition instanceof State && ($fetchAll || !empty($states[$stateDefinition->name]))) {
                $stateMetadata = [
                    'name' => $stateDefinition->name,
                    'properties' => array_map(
                        fn($item) => [
                            'name' => $item->name,
                            'direction' => $item->direction ?? null,
                            'store' => $item->store ?? null,
                            'property' => $item->property ?? null
                        ],
                        $stateDefinition->properties),
                    'actions' => array_map(fn($item) => ['name' => $item->name], $stateDefinition->actions),
                    'refresh' => $stateDefinition->refresh,
                    'init' => is_a($stateDefinition->className, HasInitState::class, true),
                    'awake' => is_a($stateDefinition->className, HasUpdateState::class, true) || is_a($stateDefinition->className, HasPreUpdateState::class, true),
                    'state' => $this->makeOutputState($stateDefinition, $stateInstance, true)['state'],
                ];
                $stateMetadata['methods'] = $this->getActionsMethod($stateDefinition, $stateInstance, $context);
                if (empty($stateMetadata['methods'])) {
                    unset($stateMetadata['methods']);
                }
                $statesDefinitions[$stateDefinition->name] = $stateMetadata;
            }
        }
        return $statesDefinitions;
    }

    /**
     * @param Request $request
     * @param array $instances
     * @return void
     */
    public function invokePreactionInstances(Request $request, array $instances): void
    {
        foreach ($this->storeInstances as $instance) {
            if ($instance instanceof HasPreAction) {
                $instance->preAction($request);
            }
        }
        foreach ($instances as $instance) {
            if ($instance instanceof HasPreAction) {
                $instance->preAction($request);
            }
        }
    }

    /**
     * @param Request $request
     * @param array $instances
     * @return void
     */
    public function invokePostActionInstances(Request $request, array $instances): void
    {
        foreach ($this->storeInstances as $instance) {
            if ($instance instanceof HasPostAction) {
                $instance->postAction($request);
            }
        }
        foreach ($instances as $instance) {
            if ($instance instanceof HasPostAction) {
                $instance->postAction($request);
            }
        }
    }

    public function warmUpComponent(Component $definition, string $className): void
    {
        if ($this->cacheEnabled) {
            $context = new Context($this->registry, $definition);
            $this->compileAndRenderFlowTemplate($definition, $context, $className);
        }
    }


    protected function getRequest()
    {
        $this->request ??= $this->container->get('request_stack')->getCurrentRequest();
        return $this->request;
    }

    protected function metadata(string|array $request, $store = false)
    {
        $sendAll = $request === '*';
        if (!$sendAll && is_string($request)) {
            $request = [$request];
        }
        $metadata = [];

        foreach (array_keys($this->stateInstances) as $className) {
            $definition = $this->registry->getStoreDefinition($className);
            if ($sendAll || in_array($className, $request) || in_array($definition->name, $request)) {
                $metadata[$definition->name] = $definition;
            }
        }
        return $metadata;
    }

    /**
     * Returns a JsonResponse that uses the serializer component if enabled, or json_encode.
     */
    protected function json($data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        if ($this->container->has('serializer')) {
            $json = $this->container->get('serializer')->serialize($data, 'json', array_merge([
                'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS,
            ], $context));

            return new JsonResponse($json, $status, $headers, true);
        }

        return new JsonResponse($data, $status, $headers);
    }

    protected function getActionsMethod(State|Store $storeDefinition, mixed $stateInstance, Context $context)
    {
        $methods = [];

        if ($stateInstance instanceof HasClientSideMethods) {
            $methods = $stateInstance
                ->getClientSideMethods()
                ->getMethods($context, fn($method) => $method['methodType'] === MethodType::Method);
        }

        foreach ($storeDefinition->actions as $action) {
            $method = $action->name;
            $methods[$method] = [
                'params' => [],
                'func' => sprintf('this.invoke.call(this,"%s",Array.prototype.slice.call(arguments));', $method),
            ];
        }
        return $methods;
    }
}