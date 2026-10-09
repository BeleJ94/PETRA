<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Tests\Functional;

use Petra\Core\Response;
use Petra\Core\Session;
use Petra\Security\Auth;
use Tests\DatabaseTestCase;

final class AuthTest extends DatabaseTestCase
{
    private string $org;
    private string $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->createOrganization();
        $this->station = $this->createStation($this->org, 'A');
    }

    private function login(Session $session, string $username, string $password): Response
    {
        $this->http($session, 'GET', '/login');

        return $this->http($session, 'POST', '/login', ['username' => $username, 'password' => $password]);
    }

    public function testLoginScreenCarriesTheCreditAndAnonymousUsersAreSentToIt(): void
    {
        $session = Session::memory();
        $page = $this->http($session, 'GET', '/login');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('name="_csrf"', $page->body);
        self::assertStringContainsString('name="username"', $page->body);
        self::assertGreaterThanOrEqual(2, substr_count($page->body, 'Petra — développé par BELEJ-CONSULTING'));

        foreach (['/', '/about', '/alerts', '/users', '/roles'] as $path) {
            $response = $this->http($session, 'GET', $path);
            self::assertSame(302, $response->status, $path);
            self::assertSame('/login', $response->headers['Location'], $path);
        }
        self::assertSame(401, $this->http($session, 'GET', '/alerts', [], ['x-requested-with' => 'XMLHttpRequest'])->status);
    }

    public function testLoginRequiresCsrfAndValidCredentials(): void
    {
        $this->createUser($this->org, 'attendant', [$this->station], 'pompiste1');
        $session = Session::memory();

        self::assertSame(419, $this->http($session, 'POST', '/login', ['username' => 'pompiste1', 'password' => self::PASSWORD], [], false)->status);

        $bad = $this->login($session, 'pompiste1', 'wrong');
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Identifiants incorrects', $bad->body);
        self::assertSame(422, $this->login($session, 'inconnu', self::PASSWORD)->status);
        self::assertNull($session->get(Auth::SESSION_USER));

        $ok = $this->login($session, ' Pompiste1 ', self::PASSWORD);
        self::assertSame(302, $ok->status);
        self::assertSame('/', $ok->headers['Location']);
        $home = $this->http($session, 'GET', '/');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Attendant Test', $home->body);
        self::assertStringContainsString('Pompiste', $home->body);
        self::assertSame(['login_failed', 'login'], array_column($this->db->fetchAll("SELECT action FROM audit_log WHERE entity_type = 'user' ORDER BY created_at, id"), 'action'));
    }

    public function testLoginAlsoAcceptsTheEmailAddress(): void
    {
        $id = $this->createUser($this->org, 'manager', [$this->station], 'gerant');
        $this->db->execute("UPDATE users SET email = 'gerant@petra.test' WHERE id = :id", ['id' => $id]);

        $session = Session::memory();
        self::assertSame(302, $this->login($session, 'Gerant@Petra.test', self::PASSWORD)->status);
        self::assertSame($id, $session->get(Auth::SESSION_USER));
        self::assertSame(422, $this->login(Session::memory(), 'gerant@petra.test', 'wrong')->status);
        self::assertSame(1, (int) $this->value('SELECT failed_logins FROM users WHERE id = :id', ['id' => $id]), 'Un échec par e-mail compte pour le verrouillage');
    }

    public function testCsrfTokenAndSessionChangeAtLogin(): void
    {
        $this->createUser($this->org, 'manager', [$this->station], 'gerant');
        $session = Session::memory(['station_context' => 'fixé avant connexion']);
        $this->http($session, 'GET', '/login');
        $before = $session->get('_csrf_token');

        $this->http($session, 'POST', '/login', ['username' => 'gerant', 'password' => self::PASSWORD]);

        self::assertNotSame($before, $session->get('_csrf_token'));
        self::assertNull($session->get('station_context'), 'Rien de la session anonyme ne survit à la connexion');
    }

    public function testPasswordsAreStoredInArgon2idAndOlderHashesAreUpgraded(): void
    {
        $id = $this->createUser($this->org, 'manager', [$this->station], 'gerant');
        self::assertStringStartsWith('$argon2id$', self::passwordHash());

        $this->db->execute('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => password_hash(self::PASSWORD, PASSWORD_BCRYPT), 'id' => $id]);
        $session = Session::memory();
        self::assertSame(302, $this->login($session, 'gerant', self::PASSWORD)->status);

        self::assertStringStartsWith('$argon2id$', (string) $this->value('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]));
        self::assertSame(200, $this->http($session, 'GET', '/')->status, 'La session reste valable après la mise à niveau du hachage');
    }

    public function testAccountIsLockedAfterFiveFailures(): void
    {
        $id = $this->createUser($this->org, 'attendant', [$this->station], 'pompiste1');
        $session = Session::memory();

        for ($i = 1; $i <= 4; $i++) {
            self::assertStringContainsString('Identifiants incorrects', $this->login($session, 'pompiste1', 'wrong')->body, "Échec $i");
        }
        self::assertNull($this->value('SELECT locked_until FROM users WHERE id = :id', ['id' => $id]));

        $fifth = $this->login($session, 'pompiste1', 'wrong');
        self::assertStringContainsString('Compte verrouillé', $fifth->body);
        self::assertNotNull($this->value('SELECT locked_until FROM users WHERE id = :id', ['id' => $id]));

        $locked = $this->login($session, 'pompiste1', self::PASSWORD);
        self::assertSame(422, $locked->status, 'Le bon mot de passe ne déverrouille pas');
        self::assertNull($session->get(Auth::SESSION_USER));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'login_locked'"));
    }

    public function testInactiveUserCannotLogInAndLosesHisSession(): void
    {
        $id = $this->createUser($this->org, 'manager', [$this->station], 'gerant');
        $session = $this->sessionFor($id);
        self::assertSame(200, $this->http($session, 'GET', '/')->status);

        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = :id', ['id' => $id]);

        self::assertSame(302, $this->http($session, 'GET', '/')->status);
        self::assertSame(422, $this->login(Session::memory(), 'gerant', self::PASSWORD)->status);
    }

    public function testLogoutClearsTheSession(): void
    {
        $id = $this->createUser($this->org, 'manager', [$this->station]);
        $session = $this->sessionFor($id);

        self::assertSame(419, $this->http($session, 'POST', '/logout', [], [], false)->status);
        $response = $this->http($session, 'POST', '/logout');

        self::assertSame('/login', $response->headers['Location']);
        self::assertSame([], $session->all());
        self::assertSame(302, $this->http($session, 'GET', '/')->status);
    }

    // --- Réinitialisation par le propriétaire -------------------------------------

    public function testOwnerResetsAPasswordWhichUnlocksTheAccountAndClosesItsSessions(): void
    {
        $owner = $this->createUser($this->org, 'owner');
        $attendant = $this->createUser($this->org, 'attendant', [$this->station], 'pompiste1');
        $this->db->execute('UPDATE users SET failed_logins = 5, locked_until = NOW() + INTERVAL 15 MINUTE WHERE id = :id', ['id' => $attendant]);
        $attendantSession = $this->sessionFor($attendant);

        $list = $this->http($this->sessionFor($owner), 'GET', '/users');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('pompiste1', $list->body);
        self::assertStringContainsString('Verrouillé', $list->body);

        $reset = $this->http($this->sessionFor($owner), 'POST', "/users/$attendant/password", ['password' => 'Nouveau-Secret-2026', 'password_confirmation' => 'Nouveau-Secret-2026']);
        self::assertSame(302, $reset->status);

        $row = $this->row('SELECT password_hash, failed_logins, locked_until FROM users WHERE id = :id', ['id' => $attendant]);
        self::assertStringStartsWith('$argon2id$', $row['password_hash']);
        self::assertSame(0, (int) $row['failed_logins']);
        self::assertNull($row['locked_until']);
        self::assertSame(302, $this->http($attendantSession, 'GET', '/')->status, 'Les sessions ouvertes avec l\'ancien mot de passe sont fermées');
        self::assertSame(422, $this->login(Session::memory(), 'pompiste1', self::PASSWORD)->status);
        self::assertSame(302, $this->login(Session::memory(), 'pompiste1', 'Nouveau-Secret-2026')->status);

        $log = $this->row("SELECT * FROM audit_log WHERE action = 'password_reset'");
        self::assertSame($owner, $log['created_by']);
        self::assertSame($attendant, $log['entity_id']);
        self::assertStringNotContainsString('Nouveau-Secret', json_encode($log));
    }

    public function testResetRejectsWeakOrMismatchedPasswords(): void
    {
        $owner = $this->createUser($this->org, 'owner');
        $attendant = $this->createUser($this->org, 'attendant', [$this->station]);
        $session = $this->sessionFor($owner);

        foreach ([['court', 'court'], ['Assez-Long-2026', 'Autre-Chose-2026'], ['', '']] as [$password, $confirmation]) {
            $response = $this->http($session, 'POST', "/users/$attendant/password", ['password' => $password, 'password_confirmation' => $confirmation], ['referer' => 'http://localhost/users']);
            self::assertSame(302, $response->status);
            self::assertSame('/users', $response->headers['Location']);
            self::assertNotEmpty($session->get('_flash')['errors'] ?? []);
            $this->http($session, 'GET', '/users');
        }
        self::assertSame(self::passwordHash(), $this->value('SELECT password_hash FROM users WHERE id = :id', ['id' => $attendant]));
    }

    public function testOnlyTheOwnerResetsPasswordsAndOnlyInsideHisOrganization(): void
    {
        $owner = $this->createUser($this->org, 'owner');
        $manager = $this->createUser($this->org, 'manager', [$this->station]);
        $attendant = $this->createUser($this->org, 'attendant', [$this->station]);
        $foreigner = $this->createUser($this->createOrganization('Concurrent'), 'attendant');
        $new = ['password' => 'Nouveau-Secret-2026', 'password_confirmation' => 'Nouveau-Secret-2026'];

        self::assertSame(403, $this->http($this->sessionFor($manager), 'GET', '/users')->status);
        self::assertSame(403, $this->http($this->sessionFor($manager), 'POST', "/users/$attendant/password", $new)->status);
        self::assertSame(404, $this->http($this->sessionFor($owner), 'POST', "/users/$foreigner/password", $new)->status);

        foreach ([$attendant, $foreigner] as $id) {
            self::assertSame(self::passwordHash(), $this->value('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]));
        }
        self::assertStringNotContainsString('Concurrent', $this->http($this->sessionFor($owner), 'GET', '/users')->body);
    }
}
