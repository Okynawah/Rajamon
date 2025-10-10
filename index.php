<?php
require_once __DIR__ . '/framework/Router.php';
require_once __DIR__ . '/middlewares/AuthMiddleware.php';
require_once __DIR__ . '/middlewares/LogMiddleware.php';


$router = new RajamonRouter(
    routesDir: __DIR__ . '/routes',
    onNotFound: function ($uri, $method) {
        http_response_code(404);
        echo "La route $method $uri est introuvable 😢";
    }
);


$router->addMiddleware(LogMiddleware::class);