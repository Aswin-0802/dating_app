<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\EmbedOpenApi;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * docs/openapi.yaml cannot drift from routes/api.php: every route must be in
 * the spec, every spec path must be a route, the auth and ability on each
 * must match the middleware, and the offline embed must be current.
 */
class OpenApiSpecTest extends TestCase
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(base_path(EmbedOpenApi::YAML));
    }

    /** @return array<string, array{route: Route, ability: ?string, auth: bool, throttle: ?string}> "METHOD /path" => facts from the router */
    private function routes(): array
    {
        $out = [];

        foreach (Router::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $ability = null;
            $throttle = null;

            foreach ($middleware as $m) {
                // gatherMiddleware() may hand back the alias or the resolved class.
                if (preg_match('/^(?:ability:|.*CheckForAnyAbility:)(.+)$/', $m, $x)) {
                    $ability = $x[1];
                }

                if (preg_match('/^(?:throttle:|.*ThrottleRequests:)(.+)$/', $m, $x)) {
                    $throttle = $x[1] === 'api' ? $throttle : $x[1];
                }
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $out[strtoupper($method).' '.substr($route->uri(), strlen('api/v1'))] = [
                    'route' => $route,
                    'ability' => $ability,
                    'auth' => (bool) preg_grep('/^(?:auth:|.*Authenticate:)sanctum$/', $middleware),
                    'throttle' => $throttle,
                ];
            }
        }

        ksort($out);

        return $out;
    }

    /** @return array<string, array<string, mixed>> "METHOD /path" => operation */
    private function operations(): array
    {
        $out = [];

        foreach ($this->spec()['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $out[strtoupper($method).' '.$path] = $operation;
                }
            }
        }

        ksort($out);

        return $out;
    }

    public function test_every_api_route_is_in_the_spec_and_nothing_else_is(): void
    {
        $routes = array_keys($this->routes());
        $operations = array_keys($this->operations());

        $this->assertSame([], array_values(array_diff($routes, $operations)), 'Routes missing from docs/openapi.yaml.');
        $this->assertSame([], array_values(array_diff($operations, $routes)), 'Spec paths that are not routes.');
        $this->assertCount(41, $routes, 'The endpoint count changed; update the documents that state it.');
    }

    public function test_auth_ability_and_throttle_match_the_middleware(): void
    {
        $operations = $this->operations();

        foreach ($this->routes() as $key => $facts) {
            $operation = $operations[$key];
            $secured = ! empty($operation['security']);

            $this->assertSame($facts['auth'], $secured, "{$key}: spec security does not match auth:sanctum.");
            $this->assertSame($facts['ability'], $operation['x-ability'] ?? null, "{$key}: x-ability does not match the ability middleware.");

            $expectedThrottle = $facts['throttle'] ?? ($facts['auth'] ? 'api' : null);
            $this->assertSame($expectedThrottle, $operation['x-throttle'] ?? null, "{$key}: x-throttle does not match the throttle middleware.");
        }
    }

    public function test_every_operation_documents_the_error_envelope(): void
    {
        foreach ($this->operations() as $key => $operation) {
            $this->assertArrayHasKey('responses', $operation, "{$key} has no responses.");

            if (! empty($operation['security'])) {
                $this->assertArrayHasKey('401', $operation['responses'], "{$key}: an authenticated route must document 401 unauthenticated.");
                $this->assertArrayHasKey('403', $operation['responses'], "{$key}: an authenticated route must document 403 (account_restricted / account_deactivated).");
                $this->assertArrayHasKey('429', $operation['responses'], "{$key}: a throttled route must document 429 rate_limited.");
            }
        }
    }

    public function test_the_offline_embed_matches_the_yaml(): void
    {
        $yaml = (string) file_get_contents(base_path(EmbedOpenApi::YAML));
        $embed = (string) file_get_contents(base_path(EmbedOpenApi::EMBED));

        $this->assertSame(EmbedOpenApi::wrap($yaml), $embed, 'docs/openapi.yaml.js is stale: run `php artisan platform:embed-openapi`.');
    }
}
