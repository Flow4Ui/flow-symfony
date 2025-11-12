<?php

namespace Flow\Transport;

use Flow\Exception\FlowException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;

class AjaxJsonTransport extends AbstractTransport
{
    private SerializerInterface $serializer;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * {@inheritdoc}
     */
    public function processRequest(Request $request): array
    {
        if (!$request->isMethod('POST')) {
            throw new FlowException('Flow AJAX JSON transport supports only POST method');
        }

        $content = $request->getContent();
        if (empty($content)) {
            throw new FlowException('Empty request content');
        }

        $requestData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new FlowException('Invalid JSON in request: ' . json_last_error_msg());
        }

        return $requestData;
    }

    /**
     * {@inheritdoc}
     */
    public function processResponse(array $data, int $statusCode = Response::HTTP_OK): Response
    {
        return new JsonResponse($data, $statusCode);
    }
}
