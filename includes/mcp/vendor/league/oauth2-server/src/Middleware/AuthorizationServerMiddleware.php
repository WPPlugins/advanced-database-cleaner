<?php

/**
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */
declare (strict_types=1);
namespace ADBC\Vendor\League\OAuth2\Server\Middleware;

use ADBC\Vendor\League\OAuth2\Server\AuthorizationServer;
use ADBC\Vendor\League\OAuth2\Server\Exception\OAuthServerException;
use ADBC\Vendor\Psr\Http\Message\ResponseInterface;
use ADBC\Vendor\Psr\Http\Message\ServerRequestInterface;
class AuthorizationServerMiddleware
{
    public function __construct(private AuthorizationServer $server)
    {
    }
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
    {
        try {
            $response = $this->server->respondToAccessTokenRequest($request, $response);
        } catch (OAuthServerException $exception) {
            return $exception->generateHttpResponse($response);
        }
        // Pass the request and response on to the next responder in the chain
        return $next($request, $response);
    }
}
