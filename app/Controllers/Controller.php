<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Controllers;

use Petra\Core\HttpException;
use Petra\Core\Request;
use Petra\Core\Response;
use Petra\Security\CurrentUser;

abstract class Controller
{
    public function __construct(protected readonly ControllerContext $ctx)
    {
    }

    protected function render(string $template, array $data = [], int $status = 200, ?string $layout = 'layouts/app'): Response
    {
        return Response::html($this->ctx->view->render($template, $data, $layout), $status);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * Fin d'une action réussie : message JSON quand la page l'a envoyée sans se recharger (fenêtre de saisie),
     * redirection avec message sinon. Les deux chemins passent par le même service : mêmes règles, même journal.
     */
    protected function done(Request $request, string $path, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'message' => $message, 'redirect' => url($path)]);
        }

        return $this->redirect($path, $message);
    }

    protected function redirect(string $path, ?string $success = null): Response
    {
        if ($success !== null) {
            $this->ctx->session->flash('success', $success);
        }

        return Response::redirect(url($path));
    }

    protected function user(): CurrentUser
    {
        return $this->ctx->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
    }

    protected function authorize(string $permission): void
    {
        if (!$this->user()->can($permission)) {
            throw HttpException::forbidden();
        }
    }

    protected function id(array $params): string
    {
        return (string) ($params['id'] ?? '');
    }

    /** Chemin de retour fourni par un formulaire : interne à l'application uniquement. */
    protected function back(Request $request): string
    {
        $back = $request->string('back');

        return $back !== null && str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\') ? $back : '/';
    }
}
