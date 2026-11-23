<?php

/**
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */
declare (strict_types=1);
namespace ADBC\Vendor\League\OAuth2\Server;

use ADBC\Vendor\League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use ADBC\Vendor\Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
class RequestAccessTokenEvent extends RequestEvent
{
    public function __construct(string $name, ServerRequestInterface $request, #[SensitiveParameter] private AccessTokenEntityInterface $accessToken)
    {
        parent::__construct($name, $request);
    }
    /**
     * @codeCoverageIgnore
     */
    public function getAccessToken(): AccessTokenEntityInterface
    {
        return $this->accessToken;
    }
}
