<?php

interface MiddlewareInterface
{
    /**
     * @param array $request Informations de la requête (uri, method, etc.)
     * @param callable $next Fonction à appeler pour exécuter le middleware suivant
     */
    public function handle(array $request, callable $next): void;
}
