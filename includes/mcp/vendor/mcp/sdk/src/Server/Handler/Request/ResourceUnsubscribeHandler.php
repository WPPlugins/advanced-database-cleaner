<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace ADBC\Vendor\Mcp\Server\Handler\Request;

use ADBC\Vendor\Mcp\Capability\RegistryInterface;
use ADBC\Vendor\Mcp\Exception\ResourceNotFoundException;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Error;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Request;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Response;
use ADBC\Vendor\Mcp\Schema\Request\ResourceUnsubscribeRequest;
use ADBC\Vendor\Mcp\Schema\Result\EmptyResult;
use ADBC\Vendor\Mcp\Server\RequestContext;
use ADBC\Vendor\Mcp\Server\Resource\SubscriptionManagerInterface;
use ADBC\Vendor\Mcp\Server\Session\SessionInterface;
use ADBC\Vendor\Psr\Log\LoggerInterface;
use ADBC\Vendor\Psr\Log\NullLogger;
use ADBC\Vendor\Psr\SimpleCache\InvalidArgumentException;
/**
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Larry Sule-balogun <suleabimbola@gmail.com>
 */
final class ResourceUnsubscribeHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RegistryInterface $registry, private readonly SubscriptionManagerInterface $subscriptionManager, private readonly LoggerInterface $logger = new NullLogger())
    {
    }
    public function supports(Request $request): bool
    {
        return $request instanceof ResourceUnsubscribeRequest;
    }
    /**
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof ResourceUnsubscribeRequest);
        $uri = $request->uri;
        try {
            $this->registry->getResource($uri);
        } catch (ResourceNotFoundException $e) {
            $this->logger->error('Resource not found', ['uri' => $uri, 'exception' => $e]);
            return (new RequestContext($session, $request))->getProtocolVersion()->usesInvalidParamsForResourceNotFound() ? Error::forInvalidParams($e->getMessage(), $request->getId(), ['uri' => $uri]) : Error::forResourceNotFound($e->getMessage(), $request->getId());
        }
        $this->logger->debug('Unsubscribing from resource', ['uri' => $uri]);
        $this->subscriptionManager->unsubscribe($session, $uri);
        return new Response($request->getId(), new EmptyResult());
    }
}
