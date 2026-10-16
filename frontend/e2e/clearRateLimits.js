import { execFileSync } from 'node:child_process'

// The register/login endpoints are rate-limited per IP (backend Services/RateLimiter.php): real
// anti-abuse behaviour, not a bug. A local E2E run makes many sign-ups from one IP, so the counters
// are cleared first rather than weakening the real limiter.
// Point it at your local database with these settings (the defaults suit a standard local MySQL):
//   E2E_MYSQL_CLI (mysql) · E2E_DB_HOST (127.0.0.1) · E2E_DB_PORT (3306) · E2E_DB_USER (root)
//   E2E_DB_PASSWORD (none) · E2E_DB_NAME (grand_gala_gardens)
export function clearRateLimits() {
  const env = process.env
  const args = [
    '-h' + (env.E2E_DB_HOST || '127.0.0.1'),
    '-P' + (env.E2E_DB_PORT || '3306'),
    '-u' + (env.E2E_DB_USER || 'root'),
    env.E2E_DB_NAME || 'grand_gala_gardens',
    '-e',
    'DELETE FROM api_rate_limit;',
  ]
  // The password goes in MYSQL_PWD, so it never appears on the command line
  const childEnv = env.E2E_DB_PASSWORD ? { ...env, MYSQL_PWD: env.E2E_DB_PASSWORD } : env
  execFileSync(env.E2E_MYSQL_CLI || 'mysql', args, { env: childEnv, stdio: 'pipe' })
}
