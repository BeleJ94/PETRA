<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

use RuntimeException;

/**
 * Rendu de gabarits PHP. Les vues reçoivent leurs variables par extract() et
 * doivent échapper toute sortie avec e().
 */
final class View
{
    /** @var array<string, mixed> Variables partagées par toutes les vues (utilisateur, flash…) */
    private array $shared = [];

    public function __construct(private readonly string $viewsDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#', $template)) {
            throw new RuntimeException("Nom de vue invalide : $template");
        }
        $file = $this->viewsDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vue introuvable : $template");
        }
        $view = $this;
        extract($this->shared, EXTR_SKIP);
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
