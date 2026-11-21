<?php
declare(strict_types=1);

namespace ConnectTalk\Http;

/**
 * Roteador mínimo: método + padrão → função. Os "{nome}" do padrão viram parâmetros
 * (só números, que é o que o Talk usa em URL). Sem framework, de propósito.
 *
 *   $r->get('/api/conversations/{id}/panel', fn (array $p) => ...);
 *   $r->dispatch('GET', '/api/conversations/7/panel'); // true se achou a rota
 */
final class Router
{
    /** @var list<array{string,string,callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('/\\\\\{(\w+)\\\\\}/', '(?<$1>\d+)', preg_quote($pattern, '#')) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    /** Executa a primeira rota que bate. Retorna false quando nenhuma bate (o chamador decide o 404). */
    public function dispatch(string $method, string $path): bool
    {
        foreach ($this->routes as [$m, $regex, $handler]) {
            if ($m === $method && preg_match($regex, $path, $match)) {
                $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
                $handler($params);
                return true;
            }
        }
        return false;
    }
}
