<?php

namespace Flow\Contract;

use Flow\Component\Context;
use Flow\Component\JsFunc;

class Methods
{
    /**
     * @param array<string,array{lifecycleEvent:bool,func:string|JsFunc}> $methods
     */
    public function __construct(protected array $methods = [])
    {
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onBeforeCreate(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onBeforeCreate(string|JsFunc $func): self
    {
        return $this->on('beforeCreate', $func);
    }

    /**
     * @param $event
     * @param string|JsFunc $func
     * @return self
     */
    public function on(string $event, string|JsFunc $func): self
    {
        return $this->addMethod($event, $func, MethodType::LifecycleEvent);
    }

    public function addMethod(string $name, string|JsFunc $func, MethodType $methodType = MethodType::Method): self
    {
        $this->methods[$name] = [
            'methodType' => $methodType,
            'func' => $func,
        ];
        return $this;
    }

    /**
     * @param $watch
     * @param string|JsFunc $func
     * @return self
     */
    public function watch(string $watch, string|JsFunc $func): self
    {
        return $this->addMethod($watch, $func, MethodType::Watch);
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onCreated(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onCreated(string|JsFunc $func): self
    {
        return $this->on('created', $func);
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onBeforeMount(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onBeforeMount(string|JsFunc $func): self
    {
        return $this->on('beforeMount', $func);
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onMounted(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onMounted(string|JsFunc $func): self
    {
        return $this->on('mounted', $func);
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onBeforeUpdate(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onBeforeUpdate(string|JsFunc $func): self
    {
        return $this->on('beforeUpdate', $func);
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onUpdated(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onUpdated(string|JsFunc $func): self
    {
        return $this->on('updated', $func);
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onBeforeUnmount(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onBeforeUnmount(string|JsFunc $func): self
    {
        return $this->on('beforeUnmount', $func);
    }

    /**
     * Generates a lifecycle callback for the vue side component
     * ex: onUnmounted(func)
     * @param string|JsFunc $func
     * @return self
     */
    public function onUnmounted(string|JsFunc $func): self
    {
        return $this->on('unmounted', $func);
    }


    /**
     * @return array<string,array{lifecycleEvent:bool,params:array<string>,func:string|JsFunc}>
     */
    public function getMethods(Context $context, ?callable $filter = null): array
    {
        $methods = [];

        foreach ($this->methods as $methodName => $method) {
            if ($filter !== null && !$filter($method, $methodName)) {
                continue;
            }
            $func = $method['func'];
            if ($func instanceof JsFunc) {
                $params = $func->getParams();
                $method['params'] = $params;
                $method['func'] = empty($params) ? $context->parseExpression($func->getBody()) : $context->addScope($params)->parseExpression($func->getBody());
            } else {
                $method['params'] = [];
                $method['func'] = $context->parseExpression($func);
            }
            $methods[$methodName] = $method;
        }

        return $methods;
    }
}
