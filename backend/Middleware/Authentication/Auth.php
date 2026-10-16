<?php

declare(strict_types=1);

namespace GrandGala\Middleware\Authentication;

use GrandGala\Services\Sessions;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;

/* attach() runs once per request, before routing, and sets Request::user() or null.
 * require() is the only place a wrong role becomes 401 or 403.
 */
class Auth
{
    // A bad or missing token leaves the user null. require() decides if that is allowed.
    public static function attach(Request $request): void
    {
        // $user is static and survives across requests in one php -S process. Always set it.
        $token = self::currentToken();
        $user = null;
        if ($token !== null) {
            $user = Sessions::verify($token);
        }
        Request::setUser($user);
    }

    // user: any account. staff: not customer. role:admin includes superadmin.
    // Signed-out callers always get 401 (the message hides the role). optional-user is skipped.
    public static function require(array $middlewareNames, ?array $user): void
    {
        $checks = [];
        foreach ($middlewareNames as $name) {
            if ($name !== 'optional-user') {
                $checks[] = $name;
            }
        }

        if (count($checks) === 0) {
            return;
        }
        if ($user === null) {
            throw new HttpError(401, 'Please sign in');
        }

        foreach ($checks as $name) {
            self::checkOne($name, $user);
        }
    }

    private static function checkOne(string $name, array $user): void
    {
        if ($name === 'user') {
            return; // signed in at all is already enough
        }

        if ($name === 'staff') {
            if ($user['role'] === 'customer') {
                throw new HttpError(403, 'Staff only');
            }

            return;
        }

        if (str_starts_with($name, 'role:')) {
            $required = substr($name, strlen('role:'));
            if (!self::roleSatisfies($user['role'], $required)) {
                throw new HttpError(403, 'You don\'t have access to this');
            }

            return;
        }

        // Unknown middleware is a bug in Routes/api.php, not a guest's fault.
        throw new HttpError(500, 'Unknown middleware: ' . $name);
    }

    // role:admin matches admin or superadmin. role:superadmin matches only superadmin.
    private static function roleSatisfies(string $actualRole, string $requiredRole): bool
    {
        if ($requiredRole === 'admin') {
            return $actualRole === 'admin' || $actualRole === 'superadmin';
        }

        return $actualRole === $requiredRole;
    }

    // Bearer token from HTTP_AUTHORIZATION, or getallheaders() when the SAPI strips it.
    // Public so logout can revoke the same token.
    public static function currentToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, strlen('Bearer ')));
        if ($token === '') {
            return null;
        }

        return $token;
    }
}
