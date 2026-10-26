<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Tests;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface> $script */
    public function __construct(private array $script) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->script) ?? throw new \LogicException('Unexpected request ' . $request->getUri());
    }
}
