<?php

namespace Flow\Transport;

use Flow\Contract\Transport;
use Flow\Exception\FlowException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

abstract class AbstractTransport implements Transport
{
    /**
     * Process the incoming request and convert it to a format that the manager can handle
     * 
     * @param Request $request The incoming HTTP request
     * @return array The processed request data
     * @throws FlowException When the request data is invalid
     */
    abstract public function processRequest(Request $request): array;

    /**
     * Process the outgoing response data and convert it to an HTTP response
     * 
     * @param array $data The response data to be processed
     * @param int $statusCode The HTTP status code for the response
     * @return Response The HTTP response
     * @throws ExceptionInterface When serialization fails
     */
    abstract public function processResponse(array $data, int $statusCode = Response::HTTP_OK): Response;
}