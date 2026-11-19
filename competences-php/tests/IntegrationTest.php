<?php
declare(strict_types=1);

namespace Tests;

use App\Config;
use App\Db;
use PHPUnit\Framework\TestCase;

/**
 * Test de bout en bout : base MySQL de test + serveur PHP intégré + requêtes HTTP réelles.
 * Base utilisée : tests/config.test.php (variables TEST_DB_*).
 */
final class IntegrationTest extends TestCase
{
    private const EX = __DIR__ . '/../exemples/';
    private static $serveur;
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        $config = __DIR__ . '/config.test.php';
        putenv("COMPETENCES_CONFIG=$config");
        Config::load(require $config);
        Db::reset();
        try {
            Db::pdo();
        } catch (\PDOException $e) {
            self::markTestSkipped('Base MySQL de test indisponible : ' . $e->getMessage());
        }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Db::all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
            Db::pdo()->exec('DROP TABLE `' . $r['t'] . '`');
        }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

        $port = 18000 + random_int(0, 999);
        self::$base = "http://127.0.0.1:$port";
        $racine = dirname(__DIR__);
        self::$serveur = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$racine/public", "$racine/public/index.php"],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, $racine, ['COMPETENCES_CONFIG' => $config] + getenv()
        );
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100000);
        }
        self::fail('Le serveur PHP intégré ne démarre pas');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serveur) {
            proc_terminate(self::$serveur);
        }
    }

    /** Client HTTP minimal avec cookies et jeton CSRF */
    private function client(): object
    {
        $base = self::$base;
        return new class ($base) {
            private string $jar;
            public string $csrf = '';

            public function __construct(private string $base)
            {
                $this->jar = tempnam(sys_get_temp_dir(), 'cj');
            }

            public function req(string $methode, string $url, ?array $corps = null, bool $csrf = true): array
            {
                $ch = curl_init($this->base . $url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_PROXY => '',
                ]);
                if ($methode === 'POST') {
                    $corps ??= [];
                    if ($csrf) {
                        $corps['_csrf'] = $this->csrf;
                    }
                    $multipart = (bool) array_filter($corps, fn ($v) => $v instanceof \CURLFile);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $corps : http_build_query($corps));
                }
                $r = curl_exec($ch);
                $taille = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $statut = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $entetes = substr($r, 0, $taille);
                $texte = substr($r, $taille);
                if (preg_match('/name="_csrf" value="([^"]+)"/', $texte, $m)) {
                    $this->csrf = $m[1];
                }
                $location = preg_match('/^Location:\s*(\S+)/mi', $entetes, $m) ? $m[1] : null;
                return ['statut' => $statut, 'location' => $location, 'texte' => $texte];
            }

            public function get(string $u): array
            {
                return $this->req('GET', $u);
            }

            public function post(string $u, array $c = []): array
            {
                return $this->req('POST', $u, $c);
            }

            public function login(string $email, string $mdp): void
            {
                $this->get('/login');
                $r = $this->post('/login', ['email' => $email, 'password' => $mdp]);
                if ($r['statut'] !== 302) {
                    throw new \RuntimeException("Connexion impossible pour $email");
                }
                $this->get('/');
            }
        };
    }

    private function formControle(int $classeId, string $titre, string $pdf, string $date): array
    {
        return [
            'classe' => $classeId, 'titre' => $titre, 'matiere' => 'Mathématiques', 'date' => $date, 'note_sur' => '20',
            'pdf' => new \CURLFile(self::EX . $pdf, 'application/pdf', $pdf),
            'excel' => new \CURLFile(self::EX . 'notes-3APIC-2.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'notes.xlsx'),
        ];
    }

    public function testParcoursComplet(): void
    {
        // 0. Installation par le navigateur
        $inst = $this->client();
        $this->assertSame(200, $inst->get('/install')['statut']);
        $r = $inst->post('/install', ['nom' => 'Admin', 'email' => 'admin@test.ma', 'password' => 'admin12345']);
        $this->assertSame('/login', $r['location']);
        $this->assertSame('/login', $inst->get('/install')['location'], "L'installeur doit être désactivé après installation");
        foreach ([['Prof Un', 'prof1@test.ma'], ['Prof Deux', 'prof2@test.ma']] as [$nom, $email]) {
            Db::insert('users', ['nom' => $nom, 'email' => $email, 'password_hash' => password_hash('prof123', PASSWORD_DEFAULT), 'role' => 'prof']);
        }

        // Accès anonyme, mauvais mot de passe, CSRF
        $anonyme = $this->client();
        $this->assertSame('/login', $anonyme->get('/controles')['location']);
        $mauvais = $this->client();
        $mauvais->get('/login');
        $this->assertSame(401, $mauvais->post('/login', ['email' => 'prof1@test.ma', 'password' => 'faux'])['statut']);

        $prof = $this->client();
        $prof->login('prof1@test.ma', 'prof123');
        $this->assertSame(403, $prof->req('POST', '/classes', ['nom' => 'X'], false)['statut']);

        // 1. Classe
        $r = $prof->post('/classes', ['nom' => '3APIC-2', 'niveau' => '3e', 'annee_scolaire' => '2026-2027']);
        $this->assertMatchesRegularExpression('#^/classes/\d+$#', (string) $r['location']);
        $classeId = (int) basename($r['location']);

        // 2. Contrôle 1 : PDF + Excel
        $r = $prof->post('/controles', $this->formControle($classeId, 'Contrôle 1', 'controle-maths-1.pdf', '2026-10-15'));
        $this->assertMatchesRegularExpression('#^/controles/\d+/competences$#', (string) $r['location'], $r['texte']);
        $c1 = (int) explode('/', $r['location'])[2];
        $this->assertStringContainsString('Résoudre une équation', $prof->get("/controles/$c1/competences")['texte']);
        $this->assertSame(8, (int) Db::val('SELECT COUNT(*) FROM eleves WHERE classe_id = ?', [$classeId]));
        $this->assertSame(200, $prof->get("/controles/$c1/pdf")['statut']);

        $comps = \App\Service\Repo::controle($c1)['competences'];
        $iResoudre = array_search('Résoudre une équation / un problème', $comps, true);
        $this->assertNotFalse($iResoudre);

        // 3. Grille : tout maîtrisé sauf Bennani (équations) ; Ghazi a une lacune libre ; Kettani non évalué
        $this->assertSame(200, $prof->get("/controles/$c1/evaluation")['statut']);
        $eleves = Db::all('SELECT id, nom FROM eleves WHERE classe_id = ?', [$classeId]);
        $id = fn (string $nom) => (int) array_values(array_filter($eleves, fn ($e) => $e['nom'] === $nom))[0]['id'];
        $corps = ['suite' => 'dashboard'];
        foreach ($eleves as $e) {
            $k = "eval[{$e['id']}]";
            $corps["{$k}[touche]"] = $e['nom'] === 'Kettani' ? '0' : '1';
            foreach ($comps as $i => $c) {
                if (!($e['nom'] === 'Bennani' && $i === $iResoudre)) {
                    $corps["{$k}[m][$i]"] = (string) $i;
                }
            }
            $corps["{$k}[libres]"] = $e['nom'] === 'Ghazi' ? 'Tables de multiplication' : '';
            $corps["{$k}[note]"] = '';
        }
        $r = $prof->post("/controles/$c1/evaluation", $corps);
        $this->assertSame("/controles/$c1/dashboard", $r['location']);
        $this->assertSame(7, (int) Db::val('SELECT COUNT(*) FROM evaluations WHERE controle_id = ? AND evaluee = 1', [$c1]));
        $this->assertSame(1, (int) Db::val("SELECT COUNT(*) FROM lacunes WHERE eleve_id = ? AND statut = 'ouverte'", [$id('Bennani')]));
        $this->assertSame(1, (int) Db::val("SELECT COUNT(*) FROM lacunes WHERE eleve_id = ? AND origine = 'professeur'", [$id('Ghazi')]));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM lacunes WHERE eleve_id = ?', [$id('Kettani')]));
        $this->assertEquals(9.0, Db::val('SELECT note FROM evaluations WHERE controle_id = ? AND eleve_id = ?', [$c1, $id('Bennani')]), 'Une note vide ne doit pas effacer la note importée');

        // 4. Tableau de bord
        $t = $prof->get("/controles/$c1/dashboard")['texte'];
        $this->assertStringContainsString('Groupes de besoin', $t);
        $this->assertStringContainsString('Bennani', $t);
        $this->assertStringContainsString('Tables de multiplication', $t);

        // 5. Contrôle 2 : suivi des lacunes antérieures
        $r = $prof->post('/controles', $this->formControle($classeId, 'Contrôle 2', 'controle-maths-2.pdf', '2026-11-15'));
        $c2 = (int) explode('/', $r['location'])[2];
        $this->assertStringContainsString('Lacunes antérieures', $prof->get("/controles/$c2/evaluation")['texte']);
        $lacB = (int) Db::val('SELECT id FROM lacunes WHERE eleve_id = ?', [$id('Bennani')]);
        $lacG = (int) Db::val('SELECT id FROM lacunes WHERE eleve_id = ?', [$id('Ghazi')]);
        $comps2 = \App\Service\Repo::controle($c2)['competences'];
        $corps = [];
        foreach ($eleves as $e) {
            $corps["eval[{$e['id']}][touche]"] = '1';
            foreach ($comps2 as $i => $c) {
                $corps["eval[{$e['id']}][m][$i]"] = (string) $i;
            }
        }
        $corps["suivi[$lacB]"] = 'non';
        $corps["suivi[$lacG]"] = 'oui';
        $this->assertSame(302, $prof->post("/controles/$c2/evaluation", $corps)['statut']);
        $this->assertSame(1, \App\Service\Repo::completerLacunes([Db::one('SELECT * FROM lacunes WHERE id = ?', [$lacB])])[0]['niveau_alerte']);
        $g = Db::one('SELECT * FROM lacunes WHERE id = ?', [$lacG]);
        $this->assertSame('remediee', $g['statut']);
        $this->assertSame($c2, (int) $g['controle_remediation_id']);

        // 6. Alertes, statistiques, fiche élève, accueil
        $t = $prof->get('/alertes')['texte'];
        $this->assertStringContainsString('Bennani', $t);
        $this->assertStringNotContainsString('Ghazi', $t);
        $t = $prof->get("/statistiques?classe=$classeId&matiere=" . rawurlencode('Mathématiques'))['texte'];
        $this->assertStringContainsString('Contrôle 2', $t);
        $this->assertStringContainsString('Remédiée le', $prof->get('/eleves/' . $id('Ghazi'))['texte']);
        $this->assertSame(200, $prof->get('/')['statut']);
        $this->assertSame(200, $prof->get('/controles')['statut']);
        $this->assertSame(200, $prof->get("/classes/$classeId")['statut']);

        // 7. Cloisonnement entre professeurs
        $prof2 = $this->client();
        $prof2->login('prof2@test.ma', 'prof123');
        $this->assertSame(404, $prof2->get("/controles/$c1/dashboard")['statut']);
        $this->assertSame(404, $prof2->get('/eleves/' . $id('Bennani'))['statut']);
        $this->assertSame(404, $prof2->get("/classes/$classeId")['statut']);
        $this->assertStringNotContainsString('Bennani', $prof2->get('/alertes')['texte']);
        $this->assertSame(403, $prof2->get('/admin/utilisateurs')['statut']);

        // 8. Administrateur
        $admin = $this->client();
        $admin->login('admin@test.ma', 'admin12345');
        $this->assertSame(200, $admin->get("/controles/$c1/dashboard")['statut']);
        $admin->get('/admin/utilisateurs');
        $this->assertSame(302, $admin->post('/admin/utilisateurs', ['nom' => 'Prof Trois', 'email' => 'prof3@test.ma', 'password' => 'secret1', 'role' => 'prof'])['statut']);
        $this->assertNotNull(Db::val("SELECT id FROM users WHERE email = 'prof3@test.ma' AND role = 'prof'"));

        // 9. Suppression du contrôle 2 : la remédiation de Ghazi est annulée, l'alerte de Bennani disparaît
        $this->assertSame(302, $prof->post("/controles/$c2/supprimer")['statut']);
        $this->assertSame('ouverte', Db::val('SELECT statut FROM lacunes WHERE id = ?', [$lacG]));
        $this->assertSame(0, \App\Service\Repo::completerLacunes([Db::one('SELECT * FROM lacunes WHERE id = ?', [$lacB])])[0]['niveau_alerte']);

        // 10. 404
        $this->assertSame(404, $prof->get('/nimporte-quoi')['statut']);
    }
}
