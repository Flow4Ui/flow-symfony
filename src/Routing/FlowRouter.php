<?php

namespace Flow\Routing;

use Flow\Attributes\Router as FlowRoute;
use Flow\Service\Registry;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class FlowRouter implements RouterInterface, WarmableInterface
{
    public function __construct(
        private RouterInterface $inner,
        private Registry $registry,
    ) {
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->inner->getRouteCollection();
    }

    public function setContext(RequestContext $context): void
    {
        $this->inner->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->inner->getContext();
    }

    public function match(string $pathinfo): array
    {
        return $this->inner->match($pathinfo);
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->inner instanceof WarmableInterface) {
            return $this->inner->warmUp($cacheDir);
        }

        return [];
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        try {
            return $this->inner->generate($name, $parameters, $referenceType);
        } catch (RouteNotFoundException $exception) {
            $flowRoute = $this->findFlowRoute($name);
            if ($flowRoute === null) {
                throw $exception;
            }

            return $this->generateFlowRoute($flowRoute, $parameters, $referenceType);
        }
    }

    private function findFlowRoute(string $name): ?FlowRoute
    {
        foreach ($this->registry->getRoutes() as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        return null;
    }

    private function generateFlowRoute(FlowRoute $route, array $parameters, int $referenceType): string
    {
        $usedParameters = [];
        $missingParameters = [];
        $path = $this->interpolateRoutePath($route->path, $parameters, $usedParameters, $missingParameters);

        if ($missingParameters) {
            throw new MissingMandatoryParametersException($route->name ?? '', $missingParameters);
        }

        $path = $this->applyFlowBase($path);
        $context = $this->inner->getContext();

        if (self::RELATIVE_PATH === $referenceType) {
            $url = UrlGenerator::getRelativePath($context->getPathInfo(), $path);
        } else {
            $url = $this->getSchemeAuthority($context, $referenceType).$context->getBaseUrl().$path;
        }

        $extra = array_diff_key($parameters, $usedParameters);
        if (array_key_exists('_fragment', $extra)) {
            $fragment = $extra['_fragment'];
            unset($extra['_fragment']);
        } else {
            $fragment = '';
        }

        $this->normalizeQueryParameters($extra);

        if ($extra && $query = http_build_query($extra, '', '&', \PHP_QUERY_RFC3986)) {
            $url .= '?'.$query;
        }

        if ('' !== $fragment) {
            $url .= '#'.rawurlencode((string) $fragment);
        }

        return $url;
    }

    private function interpolateRoutePath(
        string $path,
        array $parameters,
        array &$usedParameters,
        array &$missingParameters,
    ): string {
        $path = $this->replaceCurlyParameters($path, $parameters, $usedParameters, $missingParameters);
        $path = $this->replaceColonParameters($path, $parameters, $usedParameters, $missingParameters);
        $path = preg_replace('#//+#', '/', $path);

        if ($path === '' || $path[0] !== '/') {
            $path = '/'.ltrim($path, '/');
        }

        return $path;
    }

    private function replaceCurlyParameters(
        string $path,
        array $parameters,
        array &$usedParameters,
        array &$missingParameters,
    ): string {
        return preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function (array $matches) use (
            $parameters,
            &$usedParameters,
            &$missingParameters,
        ): string {
            $name = $matches[1];

            if (array_key_exists($name, $parameters) && $parameters[$name] !== null) {
                $usedParameters[$name] = true;
                return $this->formatPathValue($parameters[$name]);
            }

            $missingParameters[] = $name;

            return $matches[0];
        }, $path);
    }

    private function replaceColonParameters(
        string $path,
        array $parameters,
        array &$usedParameters,
        array &$missingParameters,
    ): string {
        return preg_replace_callback(
            '/:([A-Za-z0-9_]+)(?:\([^)]*\))?([?*+]?)?/',
            function (array $matches) use ($parameters, &$usedParameters, &$missingParameters): string {
                $name = $matches[1];
                $modifier = $matches[2] ?? '';

                if (array_key_exists($name, $parameters) && $parameters[$name] !== null) {
                    $usedParameters[$name] = true;

                    return $this->formatPathValue($parameters[$name]);
                }

                if ($modifier === '?' || $modifier === '*') {
                    return '';
                }

                $missingParameters[] = $name;

                return $matches[0];
            },
            $path,
        );
    }

    private function formatPathValue(mixed $value): string
    {
        if (is_array($value)) {
            $encoded = array_map(fn ($segment) => rawurlencode((string) $segment), $value);
            return implode('/', $encoded);
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        return rawurlencode((string) $value);
    }

    private function applyFlowBase(string $path): string
    {
        $mode = $this->registry->getRouterMode();
        $base = $this->normalizeBasePath($this->registry->getRouterBase());
        $path = '/'.ltrim($path, '/');

        if ($mode === 'hash') {
            if ($base !== '') {
                return $base.'/#'.$path;
            }

            return '/#'.$path;
        }

        if ($base === '' || $path === '/') {
            return $base === '' ? $path : $base;
        }

        return $base.$path;
    }

    private function normalizeBasePath(?string $base): string
    {
        if ($base === null || $base === '') {
            return '';
        }

        return '/'.trim($base, '/');
    }

    private function getSchemeAuthority(RequestContext $context, int $referenceType): string
    {
        if ($referenceType !== UrlGeneratorInterface::ABSOLUTE_URL && $referenceType !== UrlGeneratorInterface::NETWORK_PATH) {
            return '';
        }

        $host = $context->getHost();
        $scheme = $context->getScheme();

        if ($host === '' && ($scheme === '' || $scheme === 'http' || $scheme === 'https')) {
            return '';
        }

        $port = '';
        if ($scheme === 'http' && 80 !== $context->getHttpPort()) {
            $port = ':'.$context->getHttpPort();
        } elseif ($scheme === 'https' && 443 !== $context->getHttpsPort()) {
            $port = ':'.$context->getHttpsPort();
        }

        if ($referenceType === UrlGeneratorInterface::NETWORK_PATH || $scheme === '') {
            return '//'.$host.$port;
        }

        return $scheme.'://'.$host.$port;
    }

    private function normalizeQueryParameters(array &$parameters): void
    {
        array_walk_recursive($parameters, function (&$value) {
            if (is_object($value)) {
                if ($vars = get_object_vars($value)) {
                    $value = $vars;
                } elseif ($value instanceof \Stringable) {
                    $value = (string) $value;
                }
            }
        });
    }
}
