<?php

declare(strict_types=1);

namespace GrandGala\Utils;

use GrandGala\Middleware\Authentication\Auth;

// Routes are registered in Routes/api.php.
class Router
{
    // method, path, handler, and middleware names
    private array $routes = [];

    // Middleware names: user, staff, role:admin, role:superadmin. Enforced in dispatch().
    public function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    // Method, path, and middleware for every route, including ones added later.
    public function routes(): array
    {
        $list = [];
        foreach ($this->routes as $route) {
            $list[] = ['method' => $route['method'], 'path' => $route['path'], 'middleware' => $route['middleware']];
        }

        return $list;
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $params = self::matchPath($route['path'], $request->path());
            if ($params === null) {
                continue;
            }

            // 401 if signed out, 403 if the role is wrong. optional-user stays open.
            // Request::user() was set by Auth::attach() before dispatch.
            Auth::require($route['middleware'], Request::user());

            $request->setParams($params);
            call_user_func($route['handler'], $request);
            return;
        }

        throw new HttpError(404, 'Not found');
    }

    // ':id' against '/api/spaces/7' yields ['id' => '7']. No match returns null.
    private static function matchPath(string $routePath, string $requestPath): ?array
    {
        $routeParts = explode('/', trim($routePath, '/'));
        $requestParts = explode('/', trim($requestPath, '/'));
        if (count($routeParts) !== count($requestParts)) {
            return null;
        }

        $params = [];
        for ($i = 0; $i < count($routeParts); $i++) {
            $routePart = $routeParts[$i];
            $requestPart = $requestParts[$i];

            if (str_starts_with($routePart, ':')) {
                // ':id' matches any non-empty segment.
                if ($requestPart === '') {
                    return null;
                }
                $params[substr($routePart, 1)] = $requestPart;
            } elseif ($routePart !== $requestPart) {
                return null;
            }
        }

        return $params;
    }

}
