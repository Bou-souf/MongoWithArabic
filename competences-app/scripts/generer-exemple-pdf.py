"""Génère des contrôles PDF d'exemple (nécessite reportlab : pip install reportlab)."""
import os
import sys
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

SUJETS = {
    'controle-maths-1.pdf': [
        'Lycée Exemple - Contrôle n°1 de Mathématiques - 3e année',
        'Durée : 1h',
        '',
        'Exercice 1 (6 points)',
        '1) Calculer A = 3/4 + 5/6 et donner le résultat sous forme irréductible. (2 pts)',
        '2) Développer et réduire B = (2x + 3)(x - 1). (2 pts)',
        '3) Factoriser C = x² - 9. (2 pts)',
        '',
        'Exercice 2 (8 points)',
        '1) Résoudre l\'équation 3x - 7 = 2x + 5. (3 pts)',
        '2) Montrer que le triangle ABC est rectangle en A sachant que AB = 3, AC = 4 et BC = 5. (3 pts)',
        '3) En déduire l\'aire du triangle ABC. (2 pts)',
        '',
        'Exercice 3 (6 points)',
        '1) Dans un repère orthonormé, placer les points A(1;2), B(3;-1) et C(-2;0). (3 pts)',
        '2) Tracer la droite (AB). (3 pts)',
    ],
    'controle-maths-2.pdf': [
        'Lycée Exemple - Contrôle n°2 de Mathématiques - 3e année',
        '',
        'Exercice 1 (10 points)',
        '1) Calculer D = 2/3 - 1/4 x 8/3. (3 pts)',
        '2) Résoudre l\'équation 5x + 2 = 3(x - 4). (4 pts)',
        '3) Factoriser E = 4x² - 25. (3 pts)',
        '',
        'Exercice 2 (10 points)',
        '1) Lire le tableau statistique ci-dessous et relever la valeur maximale. (4 pts)',
        '2) Calculer la moyenne de la série. (3 pts)',
        '3) Justifier pourquoi la médiane est différente de la moyenne. (3 pts)',
    ],
}

def generer(dossier):
    os.makedirs(dossier, exist_ok=True)
    for nom, lignes in SUJETS.items():
        c = canvas.Canvas(os.path.join(dossier, nom), pagesize=A4)
        y = 800
        for l in lignes:
            c.setFont('Helvetica-Bold' if l.startswith(('Exercice', 'Lycée')) else 'Helvetica', 11)
            c.drawString(50, y, l)
            y -= 20
        c.save()
        print('créé :', os.path.join(dossier, nom))

if __name__ == '__main__':
    generer(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), '..', 'exemples'))
