<?php
namespace Tqdev\PhpCrudApi\Middleware\Router;

use Tqdev\PhpCrudApi\Cache\Cache;
use Tqdev\PhpCrudApi\Controller\Responder;
use Tqdev\PhpCrudApi\Middleware\Base\Middleware;
use Tqdev\PhpCrudApi\Record\ErrorCode;
use Tqdev\PhpCrudApi\Record\PathTree;
use Tqdev\PhpCrudApi\Request;
use Tqdev\PhpCrudApi\Response;

class SimpleRouter implements Router
{
    private $responder;
    private $cache;
    private $ttl;
    private $registration;
    private $routes;
    private $midlewares;

    public function __construct(Responder $responder, Cache $cache, int $ttl)
    {
        $this->responder = $responder;
        $this->cache = $cache;
        $this->ttl = $ttl;
        $this->registration = true;
        $this->routes = $this->loadPathTree();
        $this->routeHandlers = [];
        $this->middlewares = array();
    }

    private function loadPathTree(): PathTree
    {
        $data = $this->cache->get('PathTree');
        if ($data != '') {
            $tree = PathTree::fromJson(json_decode(gzuncompress($data)));
            $this->registration = false;
        } else {
            $tree = new PathTree();
        }
        return $tree;
    }

    public function register(String $method, String $path, array $handler)
    {
        $routeNumber = count($this->routeHandlers);
        $this->routeHandlers[$routeNumber] = $handler;
        if ($this->registration) {
            $parts = explode('/', trim($path, '/'));
            array_unshift($parts, $method);
            $this->routes->put($parts, $routeNumber);
        }
    }

    public function load(Middleware $middleware) /*: void*/
    {
        if (count($this->middlewares) > 0) {
            $next = $this->middlewares[0];
        } else {
            $next = $this;
        }
        $middleware->setNext($next);
        array_unshift($this->middlewares, $middleware);
    }

    public function route(Request $request): Response
    {
        if ($this->registration) {
            $data = gzcompress(json_encode($this->routes, JSON_UNESCAPED_UNICODE));
            $this->cache->set('PathTree', $data, $this->ttl);
        }
        $obj = $this;
        if (count($this->middlewares) > 0) {
            $obj = $this->middlewares[0];
        }
        return $obj->handle($request);
    }

    private function getRouteNumbers(Request $request): array
    {
        $method = strtoupper($request->getMethod());
        $path = explode('/', trim($request->getPath(0), '/'));
        array_unshift($path, $method);
        return $this->routes->match($path);
    }

    public function handle(Request $request): Response
    {
        $routeNumbers = $this->getRouteNumbers($request);
        if (count($routeNumbers) == 0) {
            return $this->responder->error(ErrorCode::ROUTE_NOT_FOUND, $request->getPath());
        }
        return call_user_func($this->routeHandlers[$routeNumbers[0]], $request);
    }

}
