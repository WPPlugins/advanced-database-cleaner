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

use ADBC\Vendor\Mcp\Capability\Completion\ProviderInterface;
use ADBC\Vendor\Mcp\Capability\Registry\ResourceTemplateReference;
use ADBC\Vendor\Mcp\Capability\RegistryInterface;
use ADBC\Vendor\Mcp\Exception\PromptNotFoundException;
use ADBC\Vendor\Mcp\Exception\ResourceNotFoundException;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Error;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Request;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Response;
use ADBC\Vendor\Mcp\Schema\PromptReference;
use ADBC\Vendor\Mcp\Schema\Request\CompletionCompleteRequest;
use ADBC\Vendor\Mcp\Schema\ResourceReference;
use ADBC\Vendor\Mcp\Schema\Result\CompletionCompleteResult;
use ADBC\Vendor\Mcp\Server\Session\SessionInterface;
use ADBC\Vendor\Psr\Container\ContainerInterface;
use ADBC\Vendor\Psr\Log\LoggerInterface;
use ADBC\Vendor\Psr\Log\NullLogger;
/**
 * Handles completion/complete requests.
 *
 * @implements RequestHandlerInterface<CompletionCompleteResult>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class CompletionCompleteHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RegistryInterface $registry, private readonly ?ContainerInterface $container = null, private readonly LoggerInterface $logger = new NullLogger())
    {
    }
    public function supports(Request $request): bool
    {
        return $request instanceof CompletionCompleteRequest;
    }
    /**
     * @return Response<CompletionCompleteResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CompletionCompleteRequest);
        $name = $request->argument['name'] ?? '';
        $value = $request->argument['value'] ?? '';
        try {
            $providers = match (\true) {
                $request->ref instanceof PromptReference => $this->registry->getPrompt($request->ref->name)->completionProviders,
                $request->ref instanceof ResourceReference => $this->resourceCompletionProviders($request->ref->uri),
            };
            $provider = $providers[$name] ?? null;
            if (null === $provider) {
                return new Response($request->getId(), new CompletionCompleteResult([]));
            }
            if (\is_string($provider)) {
                if (!class_exists($provider)) {
                    return Error::forInternalError('Invalid completion provider', $request->getId());
                }
                $provider = $this->container?->has($provider) ? $this->container->get($provider) : new $provider();
            }
            if (!$provider instanceof ProviderInterface) {
                return Error::forInternalError('Invalid completion provider type', $request->getId());
            }
            $completions = $provider->getCompletions($value);
            $total = \count($completions);
            $hasMore = $total > 100;
            $paged = \array_slice($completions, 0, 100);
            return new Response($request->getId(), new CompletionCompleteResult($paged, $total, $hasMore));
        } catch (PromptNotFoundException|ResourceNotFoundException $e) {
            // The reference names something the server does not have, which is
            // a bad parameter rather than a missing resource.
            $this->logger->warning(\sprintf('Completion requested for unknown reference: %s', $e->getMessage()), ['exception' => $e]);
            return Error::forInvalidParams($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Error while handling completion request: %s', $e->getMessage()), ['exception' => $e]);
            return Error::forInternalError('Error while handling completion request', $request->getId());
        }
    }
    /**
     * @return array<string, class-string|object>
     */
    private function resourceCompletionProviders(string $uri): array
    {
        $reference = $this->registry->getResource($uri);
        return $reference instanceof ResourceTemplateReference ? $reference->completionProviders : [];
    }
}
