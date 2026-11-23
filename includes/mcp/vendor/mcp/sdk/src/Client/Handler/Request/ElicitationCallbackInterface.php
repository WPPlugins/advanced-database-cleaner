<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace ADBC\Vendor\Mcp\Client\Handler\Request;

use ADBC\Vendor\Mcp\Schema\Request\ElicitRequest;
use ADBC\Vendor\Mcp\Schema\Result\ElicitResult;
/**
 * Contract for callbacks used by ElicitationRequestHandler.
 *
 * Implementations present the requested schema to the user and collect their
 * response when the server sends an elicitation/create request.
 */
interface ElicitationCallbackInterface
{
    public function __invoke(ElicitRequest $request): ElicitResult;
}
