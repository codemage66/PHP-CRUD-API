<?php
namespace Tqdev\PhpCrudApi\Middleware\Router;

use Tqdev\PhpCrudApi\Middleware\Base\Handler;
use Tqdev\PhpCrudApi\Middleware\Base\Middleware;
use Tqdev\PhpCrudApi\Request;
use Tqdev\PhpCrudApi\Response;

interface Router extends Handler
{
    public function register(String $method, String $path, array $handler);

    public function load(Middleware $middleware);

    public function route(Request $request): Response;
}
