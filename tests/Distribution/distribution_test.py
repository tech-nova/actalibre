import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

OUTIL = Path(__file__).resolve().parents[2] / 'tools/construire_distribution.py'


class DistributionTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name) / 'repo'
        self.repo.mkdir()
        self.git('init', '-q')
        self.ecrire('composer.json', json.dumps({'version': '1.0.0', 'extra': {'actalibre': {'schema': '1.0.0'}}}))
        for p in ['index.php', 'src/autoload.php', '.htaccess_init', 'INSTALL.md', 'vendor/prive.php', 'tests/test.php', 'docs/prive.md', 'composer.lock', 'tools/construire_distribution.py']:
            self.ecrire(p, p)
        self.sha = self.commit()

    def git(self, *args):
        return subprocess.check_output(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', *args], cwd=self.repo).decode().strip()

    def ecrire(self, path, text):
        dest = self.repo / path
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(text)

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'fixture')
        return self.git('rev-parse', 'HEAD')

    def construire(self, sortie, revision=None):
        return subprocess.run(['python3', str(OUTIL), '--revision', revision or self.sha, '--sortie', str(sortie)], cwd=self.repo, capture_output=True, text=True)

    def test_archive_fidele_et_reproductible_depuis_revision(self):
        a, b = Path(self.tmp.name) / 'a', Path(self.tmp.name) / 'b'
        self.assertEqual(0, self.construire(a).returncode)
        self.ecrire('index.php', 'travail non commite')
        self.ecrire('nouveau.php', 'revision suivante')
        self.commit()
        self.assertEqual(0, self.construire(b).returncode)
        za, zb = a / 'ActaLibre-1.0.0.zip', b / 'ActaLibre-1.0.0.zip'
        self.assertEqual(za.read_bytes(), zb.read_bytes())
        with zipfile.ZipFile(za) as z:
            self.assertEqual(b'index.php', z.read('index.php'))
            m = json.loads(z.read('inventaire-actalibre.json'))
            self.assertEqual(sorted(z.namelist()), m['fichiers'])
            self.assertEqual(self.sha, m['revision'])
            for prive in ['vendor/prive.php', 'tests/test.php', 'docs/prive.md', 'composer.lock', 'tools/construire_distribution.py', 'nouveau.php']:
                self.assertNotIn(prive, z.namelist())
        self.assertEqual(hashlib.sha256(za.read_bytes()).hexdigest(), (a / (za.name + '.sha256')).read_text().split()[0])

    def test_chemin_interdit_refuse_avant_extraction(self):
        # Sentinelle inerte : jamais de contenu issu de l'ancien assistant.
        self.ecrire('install/configuration.php', 'SENTINELLE INERTE')
        revision = self.commit()
        out = Path(self.tmp.name) / 'refus'
        result = self.construire(out, revision)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('interdit', result.stderr)
        self.assertFalse(out.exists())

    def test_lien_refuse(self):
        (self.repo / 'lien.php').symlink_to('index.php')
        result = self.construire(Path(self.tmp.name) / 'liens', self.commit())
        self.assertNotEqual(0, result.returncode)
