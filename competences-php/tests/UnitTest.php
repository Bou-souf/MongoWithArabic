<?php
declare(strict_types=1);

namespace Tests;

use App\Service\CompetencesRules;
use App\Service\ExcelImport;
use App\Service\PdfAnalyzer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    private const EX = __DIR__ . '/../exemples/';

    public function testDetectionDesCompetences(): void
    {
        $this->assertSame(['Résoudre une équation / un problème'], CompetencesRules::pour("Résoudre l'équation 2x + 3 = 7"));
        $this->assertContains('Raisonner et démontrer', CompetencesRules::pour('Montrer que ABC est rectangle'));
        $this->assertContains('Développer et factoriser une expression', CompetencesRules::pour('Factoriser x² - 9'));
        $this->assertContains('Production écrite / rédaction', CompetencesRules::pour('Rédigez un paragraphe argumentatif'));
        $this->assertContains('Calculer et appliquer une formule', CompetencesRules::pour('احسب العدد A'));
        $this->assertContains('Restituer des connaissances', CompetencesRules::pour('أذكر خاصيتين'));
        $this->assertSame([CompetencesRules::PAR_DEFAUT], CompetencesRules::pour('Bonne chance'));
    }

    public function testDecoupageEnQuestions(): void
    {
        $qs = PdfAnalyzer::decouperQuestions("Exercice 1 (5 pts)\n1) Calculer A. (2 pts)\n2) Développer B\n(3 pts)\nExercice 2\na) Tracer la courbe. (4 points)");
        $this->assertSame(['Ex1-1', 'Ex1-2', 'Ex2-a'], array_column($qs, 'numero'));
        $this->assertSame([2.0, 3.0, 4.0], array_column($qs, 'bareme'));
    }

    public function testAnalysePdfExemple(): void
    {
        $qs = PdfAnalyzer::analyserParRegles(PdfAnalyzer::extraireTexte(file_get_contents(self::EX . 'controle-maths-1.pdf')));
        $this->assertCount(8, $qs);
        foreach ($qs as $q) {
            $this->assertNotEmpty($q['competences']);
        }
    }

    public function testLectureExcelMassar(): void
    {
        $r = ExcelImport::lireFichier(self::EX . 'notes-3APIC-2.xlsx');
        $this->assertCount(8, $r['eleves']);
        $this->assertSame(['nom' => 'Alaoui', 'prenom' => 'Yasmine', 'code' => 'J130000001', 'note' => 15.5], $r['eleves'][0]);
    }

    public function testLectureExcelNomCompletEtVirgule(): void
    {
        $s = new Spreadsheet();
        $s->getActiveSheet()->fromArray([['Élève', 'Note /20'], ['Dupont Marie Claire', '12,5'], ['Martin Léo', 8]]);
        $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        (new Xlsx($s))->save($f);
        $r = ExcelImport::lireFichier($f);
        unlink($f);
        $this->assertSame([
            ['nom' => 'Dupont', 'prenom' => 'Marie Claire', 'code' => '', 'note' => 12.5],
            ['nom' => 'Martin', 'prenom' => 'Léo', 'code' => '', 'note' => 8.0],
        ], $r['eleves']);
    }
}
