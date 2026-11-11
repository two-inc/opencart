<?php

declare(strict_types=1);

namespace Opencart\System\Engine;

/**
 * Offline stand-in for OpenCart 4.1's Proxy, the object the loader hands a
 * controller for `$this->model_*`: each model method is set on it as a
 * closure and reached through __call, so the proxy is never an instance of
 * the model class. Loaded by tests/bootstrap.php only.
 *
 * @template TWraps of object
 *
 * @mixin TWraps
 */
class Proxy
{
    /** @var array<string, object> */
    protected array $data = [];

    public function __set(string $key, object $value): void
    {
        $this->data[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /** @param array<int|string, mixed> $args */
    public function __call(string $method, array $args): mixed
    {
        if (!isset($this->data[$method]) || !is_callable($this->data[$method])) {
            throw new \Exception('Undefined property: Proxy::' . $method);
        }

        return call_user_func_array($this->data[$method], $args);
    }
}
