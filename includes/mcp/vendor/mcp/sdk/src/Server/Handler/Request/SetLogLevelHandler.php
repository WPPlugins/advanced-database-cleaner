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

use ADBC\Vendor\Mcp\Schema\JsonRpc\Request;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Response;
use ADBC\Vendor\Mcp\Schema\Request\SetLogLevelRequest;
use ADBC\Vendor\Mcp\Schema\Result\EmptyResult;
use ADBC\Vendor\Mcp\Server\Protocol;
use ADBC\Vendor\Mcp\Server\Session\SessionInterface;
/**
 * Handler for the logging/setLevel request.
 *
 * Handles client requests to set the logging level for the server.
 * The server should send all logs at this level and higher (more severe) to the client.
 *
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Log to stderr (stdio) or use OpenTelemetry instead.
 */
final class SetLogLevelHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof SetLogLevelRequest;
    }
    /**
     * @return Response<EmptyResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof SetLogLevelRequest);
        $session->set(Protocol::SESSION_LOGGING_LEVEL, $request->level->value);
        return new Response($request->getId(), new EmptyResult());
    }
}
