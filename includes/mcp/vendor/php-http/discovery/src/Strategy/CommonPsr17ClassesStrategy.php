<?php

namespace ADBC\Vendor\Http\Discovery\Strategy;

use ADBC\Vendor\Psr\Http\Message\RequestFactoryInterface;
use ADBC\Vendor\Psr\Http\Message\ResponseFactoryInterface;
use ADBC\Vendor\Psr\Http\Message\ServerRequestFactoryInterface;
use ADBC\Vendor\Psr\Http\Message\StreamFactoryInterface;
use ADBC\Vendor\Psr\Http\Message\UploadedFileFactoryInterface;
use ADBC\Vendor\Psr\Http\Message\UriFactoryInterface;
/**
 * @internal
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 *
 * Don't miss updating src/Composer/Plugin.php when adding a new supported class.
 */
final class CommonPsr17ClassesStrategy implements DiscoveryStrategy
{
    /**
     * @var array
     */
    private static $classes = [RequestFactoryInterface::class => ['ADBC\Vendor\Phalcon\Http\Message\RequestFactory', 'ADBC\Vendor\Nyholm\Psr7\Factory\Psr17Factory', 'ADBC\Vendor\GuzzleHttp\Psr7\HttpFactory', 'ADBC\Vendor\Http\Factory\Diactoros\RequestFactory', 'ADBC\Vendor\Http\Factory\Guzzle\RequestFactory', 'ADBC\Vendor\Http\Factory\Slim\RequestFactory', 'ADBC\Vendor\Laminas\Diactoros\RequestFactory', 'ADBC\Vendor\Slim\Psr7\Factory\RequestFactory', 'ADBC\Vendor\HttpSoft\Message\RequestFactory'], ResponseFactoryInterface::class => ['ADBC\Vendor\Phalcon\Http\Message\ResponseFactory', 'ADBC\Vendor\Nyholm\Psr7\Factory\Psr17Factory', 'ADBC\Vendor\GuzzleHttp\Psr7\HttpFactory', 'ADBC\Vendor\Http\Factory\Diactoros\ResponseFactory', 'ADBC\Vendor\Http\Factory\Guzzle\ResponseFactory', 'ADBC\Vendor\Http\Factory\Slim\ResponseFactory', 'ADBC\Vendor\Laminas\Diactoros\ResponseFactory', 'ADBC\Vendor\Slim\Psr7\Factory\ResponseFactory', 'ADBC\Vendor\HttpSoft\Message\ResponseFactory'], ServerRequestFactoryInterface::class => ['ADBC\Vendor\Phalcon\Http\Message\ServerRequestFactory', 'ADBC\Vendor\Nyholm\Psr7\Factory\Psr17Factory', 'ADBC\Vendor\GuzzleHttp\Psr7\HttpFactory', 'ADBC\Vendor\Http\Factory\Diactoros\ServerRequestFactory', 'ADBC\Vendor\Http\Factory\Guzzle\ServerRequestFactory', 'ADBC\Vendor\Http\Factory\Slim\ServerRequestFactory', 'ADBC\Vendor\Laminas\Diactoros\ServerRequestFactory', 'ADBC\Vendor\Slim\Psr7\Factory\ServerRequestFactory', 'ADBC\Vendor\HttpSoft\Message\ServerRequestFactory'], StreamFactoryInterface::class => ['ADBC\Vendor\Phalcon\Http\Message\StreamFactory', 'ADBC\Vendor\Nyholm\Psr7\Factory\Psr17Factory', 'ADBC\Vendor\GuzzleHttp\Psr7\HttpFactory', 'ADBC\Vendor\Http\Factory\Diactoros\StreamFactory', 'ADBC\Vendor\Http\Factory\Guzzle\StreamFactory', 'ADBC\Vendor\Http\Factory\Slim\StreamFactory', 'ADBC\Vendor\Laminas\Diactoros\StreamFactory', 'ADBC\Vendor\Slim\Psr7\Factory\StreamFactory', 'ADBC\Vendor\HttpSoft\Message\StreamFactory'], UploadedFileFactoryInterface::class => ['ADBC\Vendor\Phalcon\Http\Message\UploadedFileFactory', 'ADBC\Vendor\Nyholm\Psr7\Factory\Psr17Factory', 'ADBC\Vendor\GuzzleHttp\Psr7\HttpFactory', 'ADBC\Vendor\Http\Factory\Diactoros\UploadedFileFactory', 'ADBC\Vendor\Http\Factory\Guzzle\UploadedFileFactory', 'ADBC\Vendor\Http\Factory\Slim\UploadedFileFactory', 'ADBC\Vendor\Laminas\Diactoros\UploadedFileFactory', 'ADBC\Vendor\Slim\Psr7\Factory\UploadedFileFactory', 'ADBC\Vendor\HttpSoft\Message\UploadedFileFactory'], UriFactoryInterface::class => ['ADBC\Vendor\Phalcon\Http\Message\UriFactory', 'ADBC\Vendor\Nyholm\Psr7\Factory\Psr17Factory', 'ADBC\Vendor\GuzzleHttp\Psr7\HttpFactory', 'ADBC\Vendor\Http\Factory\Diactoros\UriFactory', 'ADBC\Vendor\Http\Factory\Guzzle\UriFactory', 'ADBC\Vendor\Http\Factory\Slim\UriFactory', 'ADBC\Vendor\Laminas\Diactoros\UriFactory', 'ADBC\Vendor\Slim\Psr7\Factory\UriFactory', 'ADBC\Vendor\HttpSoft\Message\UriFactory']];
    public static function getCandidates($type)
    {
        $candidates = [];
        if (isset(self::$classes[$type])) {
            foreach (self::$classes[$type] as $class) {
                $candidates[] = ['class' => $class, 'condition' => [$class]];
            }
        }
        return $candidates;
    }
}
