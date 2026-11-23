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
use ADBC\Vendor\Mcp\Exception\InvalidCursorException;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Request;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Response;
use ADBC\Vendor\Mcp\Schema\Request\ListResourcesRequest;
use ADBC\Vendor\Mcp\Schema\Result\ListResourcesResult;
use ADBC\Vendor\Mcp\Server\Session\SessionInterface;
/**
 * @implements RequestHandlerInterface<ListResourcesResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ListResourcesHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RegistryInterface $registry, private readonly int $pageSize = 20)
    {
    }
    public function supports(Request $request): bool
    {
        return $request instanceof ListResourcesRequest;
    }
    /**
     * @return Response<ListResourcesResult>
     *
     * @throws InvalidCursorException
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListResourcesRequest);
        $page = $this->registry->getResources($this->pageSize, $request->cursor);
        return new Response($request->getId(), new ListResourcesResult($page->references, $page->nextCursor));
    }
}
