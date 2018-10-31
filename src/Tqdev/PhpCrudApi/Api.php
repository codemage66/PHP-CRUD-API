<?php
namespace Tqdev\PhpCrudApi;

use Tqdev\PhpCrudApi\Cache\CacheFactory;
use Tqdev\PhpCrudApi\Column\DefinitionService;
use Tqdev\PhpCrudApi\Column\ReflectionService;
use Tqdev\PhpCrudApi\Controller\CacheController;
use Tqdev\PhpCrudApi\Controller\ColumnController;
use Tqdev\PhpCrudApi\Controller\OpenApiController;
use Tqdev\PhpCrudApi\Controller\RecordController;
use Tqdev\PhpCrudApi\Controller\Responder;
use Tqdev\PhpCrudApi\Database\GenericDB;
use Tqdev\PhpCrudApi\Middleware\AuthorizationMiddleware;
use Tqdev\PhpCrudApi\Middleware\BasicAuthMiddleware;
use Tqdev\PhpCrudApi\Middleware\CorsMiddleware;
use Tqdev\PhpCrudApi\Middleware\CustomizationMiddleware;
use Tqdev\PhpCrudApi\Middleware\FirewallMiddleware;
use Tqdev\PhpCrudApi\Middleware\JwtAuthMiddleware;
use Tqdev\PhpCrudApi\Middleware\MultiTenancyMiddleware;
use Tqdev\PhpCrudApi\Middleware\Router\SimpleRouter;
use Tqdev\PhpCrudApi\Middleware\SanitationMiddleware;
use Tqdev\PhpCrudApi\Middleware\ValidationMiddleware;
use Tqdev\PhpCrudApi\Middleware\XsrfMiddleware;
use Tqdev\PhpCrudApi\OpenApi\OpenApiService;
use Tqdev\PhpCrudApi\Record\ErrorCode;
use Tqdev\PhpCrudApi\Record\RecordService;

class Api
{
    private $router;
    private $responder;
    private $debug;

    public function __construct(Config $config)
    {
        $db = new GenericDB(
            $config->getDriver(),
            $config->getAddress(),
            $config->getPort(),
            $config->getDatabase(),
            $config->getUsername(),
            $config->getPassword()
        );
        $cache = CacheFactory::create($config);
        $reflection = new ReflectionService($db, $cache, $config->getCacheTime());
        $responder = new Responder();
        $router = new SimpleRouter($responder, $cache, $config->getCacheTime());
        foreach ($config->getMiddlewares() as $middleware => $properties) {
            switch ($middleware) {
                case 'cors':
                    new CorsMiddleware($router, $responder, $properties);
                    break;
                case 'firewall':
                    new FirewallMiddleware($router, $responder, $properties);
                    break;
                case 'basicAuth':
                    new BasicAuthMiddleware($router, $responder, $properties);
                    break;
                case 'jwtAuth':
                    new JwtAuthMiddleware($router, $responder, $properties);
                    break;
                case 'validation':
                    new ValidationMiddleware($router, $responder, $properties, $reflection);
                    break;
                case 'sanitation':
                    new SanitationMiddleware($router, $responder, $properties, $reflection);
                    break;
                case 'multiTenancy':
                    new MultiTenancyMiddleware($router, $responder, $properties, $reflection);
                    break;
                case 'authorization':
                    new AuthorizationMiddleware($router, $responder, $properties, $reflection);
                    break;
                case 'xsrf':
                    new XsrfMiddleware($router, $responder, $properties);
                    break;
                case 'customization':
                    new CustomizationMiddleware($router, $responder, $properties, $reflection);
                    break;
            }
        }
        foreach ($config->getControllers() as $controller) {
            switch ($controller) {
                case 'records':
                    $records = new RecordService($db, $reflection);
                    new RecordController($router, $responder, $records);
                    break;
                case 'columns':
                    $definition = new DefinitionService($db, $reflection);
                    new ColumnController($router, $responder, $reflection, $definition);
                    break;
                case 'cache':
                    new CacheController($router, $responder, $cache);
                    break;
                case 'openapi':
                    $openApi = new OpenApiService($reflection, $config->getOpenApiBase());
                    new OpenApiController($router, $responder, $openApi);
                    break;
            }
        }
        $this->router = $router;
        $this->responder = $responder;
        $this->debug = $config->getDebug();
    }

    public function handle(Request $request): Response
    {
        $response = null;
        try {
            $response = $this->router->route($request);
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException) {
                if (strpos(strtolower($e->getMessage()), 'duplicate') !== false) {
                    return $this->responder->error(ErrorCode::DUPLICATE_KEY_EXCEPTION, '');
                }
                if (strpos(strtolower($e->getMessage()), 'default value') !== false) {
                    return $this->responder->error(ErrorCode::DATA_INTEGRITY_VIOLATION, '');
                }
                if (strpos(strtolower($e->getMessage()), 'allow nulls') !== false) {
                    return $this->responder->error(ErrorCode::DATA_INTEGRITY_VIOLATION, '');
                }
                if (strpos(strtolower($e->getMessage()), 'constraint') !== false) {
                    return $this->responder->error(ErrorCode::DATA_INTEGRITY_VIOLATION, '');
                }
            }
            $response = $this->responder->error(ErrorCode::ERROR_NOT_FOUND, $e->getMessage());
            if ($this->debug) {
                $response->addHeader('X-Debug-Info', 'Exception in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
        }
        return $response;
    }
}
