"""Distribution acceptance without PHP extensions."""
import hashlib
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
TOOL = ROOT / 'tools/release.py'
VERSION = (ROOT / 'VERSION').read_text().strip()
NAME = f'CajeerWorkspace-{VERSION}.zip'


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name)

    def run_tool(self, *args, ok=True):
        result = subprocess.run([sys.executable, str(TOOL), *map(str, args)], capture_output=True, text=True)
        self.assertEqual(result.returncode == 0, ok, result.stdout + result.stderr)
        return result

    def build(self, folder='dist'):
        dest = self.path / folder
        self.run_tool('build', '--output', dest)
        return dest / NAME

    def rewrite_checksum(self, path):
        path.with_name(path.name + '.sha256').write_text(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.name + '\n', encoding='ascii')

    def test_reproducible_release(self):
        a, b = self.build('a'), self.build('b')
        self.assertEqual(a.read_bytes(), b.read_bytes())
        self.run_tool('verify', a)
        self.run_tool('build', '--output', a.parent, ok=False)
        with zipfile.ZipFile(a) as z:
            self.assertIn('database/003.sql', z.namelist())
            self.assertIn('SHA256SUMS', z.namelist())
            self.assertNotIn('config/connections.local.json', z.namelist())
            self.assertNotIn('.env', z.namelist())
            self.assertFalse(any(n.startswith('var/') for n in z.namelist()))
            z.extractall(self.path / 'unpacked')
        # Rebuilding the distributed source works, without a git checkout.
        unpacked = self.path / 'unpacked'
        subprocess.run([sys.executable, str(unpacked / 'tools/release.py'), 'build', '--output', str(self.path / 'rebuilt')], check=True, capture_output=True)
        self.assertEqual(a.read_bytes(), (self.path / 'rebuilt' / NAME).read_bytes())

    def test_corrupt_archive(self):
        a = self.build()
        a.write_bytes(a.read_bytes() + b'altered')
        self.run_tool('verify', a, ok=False)

    def test_internal_hash(self):
        a = self.build()
        temp = self.path / 'altered.zip'
        with zipfile.ZipFile(a) as old, zipfile.ZipFile(temp, 'w') as new:
            for member in old.infolist():
                data = old.read(member)
                new.writestr(member, data + b'\n' if member.filename == 'README.md' else data)
        shutil.move(temp, a)
        self.rewrite_checksum(a)
        self.run_tool('verify', a, ok=False)

    def test_unsafe_member(self):
        a = self.build()
        with zipfile.ZipFile(a, 'a') as z:
            z.writestr('../outside', 'bad')
        self.rewrite_checksum(a)
        self.run_tool('verify', a, ok=False)

    def test_excludes_local_state(self):
        src = self.path / 'source'
        shutil.copytree(ROOT, src, ignore=shutil.ignore_patterns('dist', 'var', '.git', '__pycache__'))
        (src / 'var').mkdir()
        (src / 'var/workspace.sqlite').write_text('secret database')
        (src / '.env').write_text('secret environment')
        (src / 'config/connections.local.json').write_text('secret config')
        subprocess.run([sys.executable, str(src / 'tools/release.py'), 'build', '--output', str(self.path / 'safe')], check=True, capture_output=True)
        with zipfile.ZipFile(self.path / 'safe' / NAME) as z:
            self.assertFalse(set(z.namelist()) & {'var/workspace.sqlite', '.env', 'config/connections.local.json'})


if __name__ == '__main__':
    unittest.main(verbosity=2)
