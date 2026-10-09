<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Core;

use Petra\Repositories\AlertRepository;
use Petra\Repositories\IdentityRepository;
use Petra\Security\Auth;
use Petra\Security\StationContext;
use Petra\Services\SecurityMonitor;
use Petra\Sync\SyncConfig;
use Petra\Sync\SyncStatus;
use Throwable;

/**
 * Noyau HTTP : Request → Response. Chaîne : routage → CSRF (toute méthode non GET) →
 * authentification → permission → contrôleur. Aucune sortie directe (testable).
 */
final class App
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    /** Permission manquante à l'origine du 403 en cours (null : refus sur une station ou une ligne). */
    private ?string $deniedPermission = null;

    private function __construct(private readonly array $config, private readonly Container $container)
    {
    }

    public static function create(array $config, Session $session, Database $db, Request $request): self
    {
        $root = dirname(__DIR__, 2);
        Url::configure((string) $config['base_path'], $root . '/public');

        $c = new Container();
        $c->set(Database::class, $db);
        $c->set(Session::class, $session);
        $c->set(Request::class, $request);
        $csrf = new Csrf($session);
        Csrf::setCurrent($csrf);
        $c->set(Csrf::class, $csrf);
        $i18n = new I18n($root . '/lang', (string) $config['default_locale']);
        I18n::setCurrent($i18n);
        $c->set(I18n::class, $i18n);
        $c->set(View::class, new View($root . '/app/Views'));
        $sync = SyncConfig::fromConfig($config);
        $c->set(SyncConfig::class, $sync);
        $c->set(\Petra\Maintenance\BackupTransfer::class, new \Petra\Maintenance\BackupTransfer((string) $config['backup']['dir'], (int) $config['backup']['retention_days']));
        $c->factory(Auth::class, static fn (Container $c) => new Auth(
            $c->get(Session::class),
            $c->get(IdentityRepository::class),
            $c->get(Csrf::class),
            $config['security'],
            $config['mode'] === 'station' ? (string) $config['station_id'] : '',
            $sync->isCentral(),
        ));
        // Stations autorisées et station active : découlent de l'utilisateur authentifié, injectées dans tous les repositories.
        $c->factory(StationContext::class, static fn (Container $c) => $c->get(Auth::class)->stations());

        $router = new Router();
        (require $root . '/app/routes.php')($router);
        $c->set(Router::class, $router);

        return new self($config, $c);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (ValidationException $e) {
            $response = $this->validationResponse($request, $e);
        } catch (HttpException $e) {
            if ($e->status === 403) {
                $this->reportDenied($request);
            }
            $response = $this->errorResponse($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            $this->report($e);
            $response = $this->errorResponse($request, 500, 'errors.server', $e);
        }

        return $this->secure($response);
    }

    private function dispatch(Request $request): Response
    {
        $route = $this->container->get(Router::class)->match($request->method, $request->path);
        $session = $this->container->get(Session::class);

        // L'API de synchronisation ('csrf' => false) n'a pas de session : elle est authentifiée par le jeton de station.
        if ($request->method !== 'GET' && $request->method !== 'HEAD' && ($route['options']['csrf'] ?? true)) {
            $this->container->get(Csrf::class)->validateRequest($request);
        }
        $auth = $this->container->get(Auth::class);
        $user = $auth->user();
        if ($route['options']['auth'] && $user === null) {
            return $request->wantsJson()
                ? Response::json(['error' => t('errors.unauthenticated')], 401)
                : Response::redirect(url('/login'));
        }
        $this->deniedPermission = $route['options']['permission'] ?? null;
        if ($this->deniedPermission !== null && !$user?->can($this->deniedPermission)) {
            throw HttpException::forbidden();
        }
        $this->deniedPermission = null;

        FormState::load((array) $session->pullFlash('errors', []), (array) $session->pullFlash('old', []));
        $view = $this->container->get(View::class);
        $view->share('appName', $this->config['name']);
        $view->share('appMode', $this->config['mode']);
        $view->share('currentPath', $request->path);
        $view->share('currentUser', $user);
        $view->share('stationContext', $this->container->get(StationContext::class));
        $view->share('syncSummary', $user !== null && !$request->wantsJson() ? $this->container->get(SyncStatus::class)->summary() : null);
        // Badge de l'en-tête : alertes ouvertes des stations affichées (pages HTML uniquement).
        $view->share('openAlertCount', $user !== null && $user->can('alerts.view') && !$request->wantsJson()
            ? $this->container->get(AlertRepository::class)->countOpen()
            : null);
        // Pastille du menu : postes clôturés que l'utilisateur peut valider.
        $view->share('shiftsToValidate', $user !== null && $user->can('shifts.validate') && !$request->wantsJson()
            ? $this->container->get(\Petra\Repositories\ShiftRepository::class)->countToValidate()
            : null);
        // Action rapide du menu : seulement si un poste peut être ouvert (une station choisie, avec un distributeur encore libre).
        $stations = $this->container->get(StationContext::class);
        $view->share('canOpenShift', $user !== null && $user->can('shifts.open') && !$request->wantsJson() && !$stations->isReadOnly() && $stations->activeId() !== null
            && $this->container->get(\Petra\Repositories\ShiftRepository::class)->hasFreePump($stations->activeId()));
        $view->share('flashSuccess', $session->pullFlash('success'));
        $view->share('flashError', $session->pullFlash('error'));

        [$class, $method] = $route['handler'];
        $controller = $this->container->get($class);

        return $controller->$method($request, $route['params']);
    }

    private function validationResponse(Request $request, ValidationException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => t('validation.failed'), 'errors' => $e->errors], 422);
        }
        $session = $this->container->get(Session::class);
        $session->flash('errors', $e->errors);
        $old = $request->body;
        unset($old['_csrf'], $old['password'], $old['password_confirmation']);
        $session->flash('old', $old);
        $session->flash('error', t('validation.failed'));

        return Response::redirect($this->backUrl($request));
    }

    /** Retour à la page d'origine (même application uniquement). */
    private function backUrl(Request $request): string
    {
        $referer = $request->header('referer');
        $path = $referer !== null ? (string) parse_url($referer, PHP_URL_PATH) : '';
        $query = $referer !== null ? (string) parse_url($referer, PHP_URL_QUERY) : '';
        $base = Url::basePath();
        if ($path === '' || ($base !== '' && !str_starts_with($path, $base)) || str_starts_with($path, '//')) {
            return url('/');
        }

        return $path . ($query !== '' ? '?' . $query : '');
    }

    private function errorResponse(Request $request, int $status, string $messageKey, ?Throwable $e = null): Response
    {
        $message = str_starts_with($messageKey, 'errors.') ? t($messageKey) : $messageKey;
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }
        try {
            $html = $this->container->get(View::class)->render('errors/error', [
                'status' => $status,
                'message' => $message,
                'debug' => $this->config['debug'] && $e !== null ? (string) $e : null,
            ], 'layouts/minimal');
        } catch (Throwable) {
            $html = '<h1>' . $status . '</h1><p>' . e($message) . '</p>';
        }

        return Response::html($html, $status);
    }

    /** Accès refusé : journal d'audit + alerte. Un échec de journalisation ne change pas la réponse (403). */
    private function reportDenied(Request $request): void
    {
        try {
            $this->container->get(SecurityMonitor::class)->accessDenied($request, $this->deniedPermission);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    private function report(Throwable $e): void
    {
        $file = dirname(__DIR__, 2) . '/storage/logs/app.log';
        @file_put_contents($file, '[' . date('c') . '] ' . $e . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=()')
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('Cache-Control', $response->headers['Cache-Control'] ?? 'no-store');
    }
}
