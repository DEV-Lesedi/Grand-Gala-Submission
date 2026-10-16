<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

use GrandGala\Tests\Support\CreatesTestAccounts;

// Who may call every protected route in Routes/api.php, read from the router.
// Signed out is 401 (optional-user lets guests through). The wrong role is 403.
// The right role may get 404 or 422: ids and bodies are made up, so nothing is saved.
class RouteAccessTest extends FeatureTestCase
{
    use CreatesTestAccounts;

    public function testSignedOutCallersGet401OnEveryProtectedRoute(): void
    {
        foreach ($this->protectedRoutes() as $route) {
            $response = $this->send($route['method'], $this->samplePath($route['path']));

            if ($route['middleware'] === ['optional-user']) {
                self::assertNotSame(401, $response['status'], $this->label($route) . ' should let guests through');
            } else {
                self::assertSame(401, $response['status'], $this->label($route));
            }
        }
    }

    public function testGuestsGet403OnStaffRoutes(): void
    {
        $token = $this->tokenFor($this->createAccount('customer')['id']);

        foreach ($this->protectedRoutes() as $route) {
            $response = $this->sendAuthed($route['method'], $this->samplePath($route['path']), $token);

            if ($this->isStaffRoute($route)) {
                self::assertSame(403, $response['status'], $this->label($route));
            } else {
                self::assertNotContains($response['status'], [401, 403], $this->label($route) . ' should accept a guest');
            }
        }
    }

    public function testStaffGet403OnlyOnAdminRoutes(): void
    {
        $token = $this->tokenFor($this->createAccount('staff')['id']);

        foreach ($this->protectedRoutes() as $route) {
            $response = $this->sendAuthed($route['method'], $this->samplePath($route['path']), $token);

            if (in_array('role:admin', $route['middleware'], true) || in_array('role:superadmin', $route['middleware'], true)) {
                self::assertSame(403, $response['status'], $this->label($route));
            } else {
                self::assertNotContains($response['status'], [401, 403], $this->label($route) . ' should accept staff');
            }
        }
    }

    public function testAdminsGetPastTheAdminRoutes(): void
    {
        $token = $this->tokenFor($this->createAccount('admin')['id']);

        foreach ($this->protectedRoutes() as $route) {
            if (in_array('role:admin', $route['middleware'], true)) {
                $response = $this->sendAuthed($route['method'], $this->samplePath($route['path']), $token);
                self::assertNotContains($response['status'], [401, 403], $this->label($route) . ' should accept an admin');
            }
        }
    }

    // Every route in Routes/api.php that has a sign-in check
    private function protectedRoutes(): array
    {
        $router = require dirname(__DIR__, 2) . '/Routes/api.php';

        $protected = [];
        foreach ($router->routes() as $route) {
            if (count($route['middleware']) > 0) {
                $protected[] = $route;
            }
        }

        return $protected;
    }

    private function isStaffRoute(array $route): bool
    {
        foreach ($route['middleware'] as $name) {
            if ($name === 'staff' || str_starts_with($name, 'role:')) {
                return true;
            }
        }

        return false;
    }

    // Made-up path values that cannot match a real row, e.g. /api/staff/media/999999999.
    private function samplePath(string $path): string
    {
        $parts = explode('/', $path);
        foreach ($parts as $i => $part) {
            if ($part === ':id' || $part === ':spaceId') {
                $parts[$i] = '999999999';
            } elseif (str_starts_with($part, ':')) {
                $parts[$i] = 'TEST-NOPE';
            }
        }

        return implode('/', $parts);
    }

    private function label(array $route): string
    {
        return $route['method'] . ' ' . $route['path'];
    }
}
