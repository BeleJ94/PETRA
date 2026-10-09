<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use Petra\Controllers\HomeController;
use Petra\Core\App;
use Petra\Core\Request;
use Petra\Core\Router;
use Petra\Core\Session;
use Tests\DatabaseTestCase;

/** Socle : layout, mention obligatoire, pages d'erreur, en-têtes de sécurité, routes protégées par défaut. */
final class SkeletonTest extends DatabaseTestCase
{
    private const CREDIT = 'Petra — développé par BELEJ-CONSULTING';

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $org = $this->createOrganization();
        $this->session = $this->sessionFor($this->createUser($org, 'manager', [$this->createStation($org, 'A')]));
    }

    public function testHomePageIsServedInFrenchWithCredit(): void
    {
        $page = $this->http($this->session, 'GET', '/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<html lang="fr">', $page->body);
        self::assertStringContainsString('<footer class="footer">' . self::CREDIT . '</footer>', $page->body);
        self::assertStringContainsString('assets/js/pt-core.js', $page->body);
        self::assertStringNotContainsString('PerfectMaint', $page->body);
    }

    public function testAboutPageNamesTheVendorAndTheMode(): void
    {
        $page = $this->http($this->session, 'GET', '/about');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<strong>' . self::CREDIT . '</strong>', $page->body);
        self::assertStringContainsString('<dd>Station</dd>', $page->body);
        self::assertStringContainsString('Africa/Lubumbashi', $page->body);
    }

    public function testErrorPagesAlsoCarryTheCredit(): void
    {
        $page = $this->http($this->session, 'GET', '/nope');
        self::assertSame(404, $page->status);
        self::assertStringContainsString(self::CREDIT, $page->body);

        $json = $this->http($this->session, 'GET', '/nope', [], ['accept' => 'application/json']);
        self::assertSame(404, $json->status);
        self::assertArrayHasKey('error', json_decode($json->body, true));
    }

    public function testSecurityHeadersAreSet(): void
    {
        $page = $this->http($this->session, 'GET', '/');
        self::assertSame(App::CSP, $page->headers['Content-Security-Policy']);
        self::assertSame('DENY', $page->headers['X-Frame-Options']);
        self::assertSame('nosniff', $page->headers['X-Content-Type-Options']);
        self::assertStringNotContainsString('<script>', $page->body, 'Aucun script inline (CSP stricte)');
    }

    /** Une route sans 'auth' => false n'est jamais servie à un visiteur anonyme. */
    public function testRoutesAreProtectedByDefault(): void
    {
        $anonymous = Session::memory(['_csrf_token' => self::CSRF]);
        $request = new Request('GET', '/private');
        $app = App::create(self::config(), $anonymous, $this->db, $request);
        $app->container()->get(Router::class)->get('/private', [HomeController::class, 'index']);
        $app->container()->get(Router::class)->post('/private', [HomeController::class, 'index']);

        $response = $app->handle($request);
        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
        self::assertSame(401, $app->handle(new Request('GET', '/private', [], [], ['accept' => 'application/json']))->status);

        self::assertSame(419, $app->handle(new Request('POST', '/private'))->status, 'CSRF vérifié avant tout');
        self::assertSame(302, $app->handle(new Request('POST', '/private', [], ['_csrf' => self::CSRF]))->status);
    }
}
