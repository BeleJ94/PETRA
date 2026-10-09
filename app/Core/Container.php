<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Conteneur d'injection minimal : instances partagées, fabriques explicites et autowiring par constructeur.
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, Closure(Container): object> */
    private array $factories = [];

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /** @param Closure(Container): object $factory */
    public function factory(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $instance = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->build($id);
        $this->instances[$id] = $instance;

        return $instance;
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Service introuvable : $class");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Service non instanciable : $class");
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new RuntimeException("Impossible de résoudre \${$param->getName()} pour $class");
            }
        }

        return $reflection->newInstanceArgs($args);
    }
}
