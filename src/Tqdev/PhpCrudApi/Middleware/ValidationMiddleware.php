<?php
namespace Tqdev\PhpCrudApi\Middleware;

use Tqdev\PhpCrudApi\Column\ReflectionService;
use Tqdev\PhpCrudApi\Column\Reflection\ReflectedTable;
use Tqdev\PhpCrudApi\Controller\Responder;
use Tqdev\PhpCrudApi\Middleware\Base\Middleware;
use Tqdev\PhpCrudApi\Middleware\Router\Router;
use Tqdev\PhpCrudApi\Record\ErrorCode;
use Tqdev\PhpCrudApi\Request;
use Tqdev\PhpCrudApi\Response;

class ValidationMiddleware extends Middleware
{
    private $reflection;

    public function __construct(Router $router, Responder $responder, array $properties, ReflectionService $reflection)
    {
        parent::__construct($router, $responder, $properties);
        $this->reflection = $reflection;
    }

    private function callHandler($handler, $record, String $method, ReflectedTable $table) /*: Response?*/
    {
        $context = (array) $record;
        $details = array();
        $tableName = $table->getName();
        foreach ($context as $columnName => $value) {
            if ($table->exists($columnName)) {
                $column = $table->get($columnName);
                $valid = call_user_func($handler, $method, $tableName, $column->serialize(), $value, $context);
                if ($valid !== true && $valid !== '') {
                    $details[$columnName] = $valid;
                }
            }
        }
        if (count($details) > 0) {
            return $this->responder->error(ErrorCode::INPUT_VALIDATION_FAILED, $tableName, $details);
        }
        return null;
    }

    public function handle(Request $request): Response
    {
        $path = $request->getPathSegment(1);
        $tableName = $request->getPathSegment(2);
        $record = $request->getBody();
        if ($path == 'records' && $this->reflection->hasTable($tableName) && $record !== null) {
            $table = $this->reflection->getTable($tableName);
            $method = $request->getMethod();
            $handler = $this->getProperty('handler', '');
            if ($handler !== '') {
                if (is_array($record)) {
                    foreach ($record as $r) {
                        $response = $this->callHandler($handler, $r, $method, $table);
                        if ($response !== null) {
                            return $response;
                        }
                    }
                } else {
                    $response = $this->callHandler($handler, $record, $method, $table);
                    if ($response !== null) {
                        return $response;
                    }
                }
            }
        }
        return $this->next->handle($request);
    }
}
