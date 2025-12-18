<?php

namespace Flow\Controller;

use Flow\Service\Manager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FlowController extends AbstractController
{
    public function __construct(private readonly Manager $manager)
    {
    }

    #[Route('/_flow/endpoint', name: 'flow.endpoint', methods: ['POST'])]
    public function endpoint(Request $request): Response
    {
        return $this->manager->handle($request);
    }

    #[Route('/_flow/ssr/{component}', name: 'flow.ssr', defaults: ['component' => null], methods: ['GET'])]
    public function ssr(Request $request, ?string $component = null): Response
    {
        $options = $this->buildHydrationOptions($request, $component);
        $ssrEnabled = filter_var($request->query->get('ssr', true), FILTER_VALIDATE_BOOLEAN);

        $view = $this->manager->renderHydratableView($options, $ssrEnabled);

        $html = ($ssrEnabled && !empty($view['ssr'])) ? $view['ssr'] : '';
        $html .= sprintf('<script>window.FlowOptions=%s;</script>', $view['flowOptions']);

        return new Response($html);
    }

    private function buildHydrationOptions(Request $request, ?string $component): array
    {
        $options = [
            'components' => $this->parseRequestedNames($request->query->get('components')) ?? ['*' => []],
            'stores' => $this->parseRequestedNames($request->query->get('stores')) ?? ['*' => []],
            'states' => $this->parseRequestedNames($request->query->get('states')) ?? ['*' => []],
            'endpoint' => $this->generateUrl('flow.endpoint'),
        ];

        $mainComponent = $component ?? $request->query->get('component') ?? $request->query->get('mainComponent');
        if ($mainComponent) {
            $options['mainComponent'] = $mainComponent;
        }

        if ($mountTarget = $request->query->get('mount')) {
            $options['mount'] = $mountTarget;
        }

        return $options;
    }

    private function parseRequestedNames(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $names = array_filter(array_map('trim', explode(',', (string)$raw)));
        if (empty($names)) {
            return null;
        }

        $requested = [];
        foreach ($names as $name) {
            $requested[$name] = [];
        }

        return $requested;
    }
}
