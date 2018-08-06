<?php
namespace Tqdev\PhpCrudApi\Middleware\Base;

use Tqdev\PhpCrudApi\Controller\Responder;
use Tqdev\PhpCrudApi\Middleware\Router\Router;

abstract class Middleware implements Handler
{
    protected $next;
    protected $responder;
    private $properties;

    public function __construct(Router $router, Responder $responder, array $properties)
    {
        $router->load($this);
        $this->responder = $responder;
        $this->properties = $properties;
    }

    public function setNext(Handler $handler) /*: void*/
    {
        $this->next = $handler;
    }

    protected function getProperty(String $key, $default)
    {
        return isset($this->properties[$key]) ? $this->properties[$key] : $default;
    }
}
