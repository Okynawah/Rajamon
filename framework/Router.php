<?php

require_once __DIR__ . '/RouteAttribute.php';
require_once __DIR__ . '/MiddlewareInterface.php';
require_once __DIR__ . '/MiddlewareAttribute.php';

class RajamonRouter
{
    private array $routes = [];
    private array $middlewares = [];
    private string $routesDir;
    private $onNotFound;

    public function __construct(?string $routesDir = null, callable $onNotFound = null, bool $autoRun = true)
    {
        $this->routesDir = $routesDir ?? dirname(__DIR__) . '/routes';
        $this->onNotFound = $onNotFound;

        $cachedRoute = apcu_fetch('routes');
        if ($cachedRoute && !empty($cachedRoute)) {
            foreach ($cachedRoute as $r) {
                if (!file_exists($r['file']) || filemtime($r['file']) > $r['file_mtime']) {
                    $cachedRoute = null;
                    break;
                }
            }
        }

        if ($cachedRoute && !empty($cachedRoute)) {
            error_log("Cache used");
            $this->routes = $cachedRoute;
        } else {
            error_log("Cache refresh");
            $this->registerRoutesFromDir($this->routesDir);
            apcu_store('routes', $this->routes);
        }


        if ($autoRun) {
            register_shutdown_function([$this, 'autoDispatch']);
        }
    }

    public function addMiddleware(string $middlewareClass): void
    {
        if (!class_exists($middlewareClass)) {
            throw new InvalidArgumentException("Middleware introuvable : $middlewareClass");
        }
        $this->middlewares[] = $middlewareClass;
    }

    public function autoDispatch(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];
        $this->dispatch($uri, $method);
    }

    public function registerRoutesFromDir(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                    $className = basename($file->getRealPath(), '.php');
                    require_once $file->getRealPath();
                    $this->registerRoutesFromClass($className);
            }
        }
    }

    private function registerRoutesFromClass(string $className): void
    {
        $refClass = new ReflectionClass($className);
        $classAttributes = $refClass->getAttributes(Route::class);
        $classMiddlewares = array_map(fn($a) => $a->newInstance()->class, $refClass->getAttributes(Middleware::class));

        $classPrefix = '';
        if (!empty($classAttributes)) {
            $classPrefix = $classAttributes[0]->newInstance()->path;
        }

        foreach ($refClass->getMethods() as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                $route = $attr->newInstance();
                $path = $classPrefix . $route->path;

                $methodMiddlewares = array_map(fn($a) => $a->newInstance()->class, $method->getAttributes(Middleware::class));

                $this->routes[] = [
                    'path' => $path,
                    'method' => strtoupper($route->method),
                    'class' => $className,
                    'function' => $method->getName(),
                    'middlewares' => array_merge($classMiddlewares, $methodMiddlewares),
                    'file' => $refClass->getFileName(),
                    'file_mtime' => filemtime($refClass->getFileName()),
                ];
            }
        }
    }

    public function dispatch(string $requestUri, string $requestMethod): void
    {
        $request = [
            'uri' => $requestUri,
            'method' => $requestMethod,
        ];

        $next = function () use ($requestUri, $requestMethod) {
            $this->dispatchToRoute($requestUri, $requestMethod);
        };

        foreach (array_reverse($this->middlewares) as $middlewareClass) {
            $next = $this->wrapMiddleware($middlewareClass, $request, $next);
        }

        $next();
    }

    private function dispatchToRoute(string $requestUri, string $requestMethod): void
    {
        foreach ($this->routes as $route) {
            $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']);
            $pattern = "#^{$pattern}$#";

            if (
                $route['method'] === strtoupper($requestMethod)
                && preg_match($pattern, $requestUri, $matches)
            ) {
                if (!class_exists($route['class'])) {
                    if (!empty($route['file']) && file_exists($route['file'])) {
                        require_once $route['file'];
                    } else {
                        throw new RuntimeException("Impossible de charger la classe {$route['class']} (fichier manquant)");
                    }
                }

                $controller = new $route['class']();
                $params = array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);

                $request = ['uri' => $requestUri, 'method' => $requestMethod];

                // Middleware spécifiques à cette route :
                $next = function () use ($controller, $route, $params) {
                    call_user_func_array([$controller, $route['function']], $params);
                };

                foreach (array_reverse($route['middlewares']) as $middlewareClass) {
                    $next = $this->wrapMiddleware($middlewareClass, $request, $next);
                }

                $next();
                return;
            }
        }

        if ($this->onNotFound) {
            call_user_func($this->onNotFound, $requestUri, $requestMethod);
            return;
        }

        http_response_code(404);
        echo "404 Not Found";
    }

    private function wrapMiddleware(string $middlewareClass, array $request, callable $next): callable
    {
        return function () use ($middlewareClass, $request, $next) {
            if (!class_exists($middlewareClass)) {
                throw new RuntimeException("Middleware introuvable : $middlewareClass");
            }
            $middleware = new $middlewareClass();
            if (!($middleware instanceof MiddlewareInterface)) {
                throw new RuntimeException("Le middleware $middlewareClass doit implémenter MiddlewareInterface");
            }
            $middleware->handle($request, $next);
        };
    }
}
