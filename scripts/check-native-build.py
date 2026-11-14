#!/usr/bin/env python3
"""Check native UI reproducibility without downloading or changing app files."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]

def main():
    admin = ROOT / 'public/assets/admin'
    record = json.loads((admin / 'native-build.json').read_text())
    with tempfile.TemporaryDirectory(prefix='xboard-native-build-') as temp:
        temp = Path(temp)
        original = temp / 'original'
        original.mkdir()
        shutil.copytree(admin / 'assets', original / 'assets')
        shutil.copytree(admin / 'locales', original / 'locales')
        manifest = json.loads((admin / 'manifest.json').read_text())
        manifest['index.html']['file'] = record['upstream_entry']
        (original / 'manifest.json').write_text(json.dumps(manifest))
        subprocess.run([sys.executable, str(ROOT / 'scripts/build-admin-native.py'), '--source', str(original),
                        '--output', str(temp / 'output'), '--user-source', str(ROOT / 'theme/Xboard/assets/umi.js'),
                        '--user-output', str(temp / 'umi-native.js')], check=True, stdout=subprocess.PIPE)
        for actual, expected in [(temp / 'output/assets/index-v2bx-native.js', admin / 'assets/index-v2bx-native.js'),
                                 (temp / 'output/manifest.json', admin / 'manifest.json'),
                                 (temp / 'output/native-build.json', admin / 'native-build.json'),
                                 (temp / 'umi-native.js', ROOT / 'theme/Xboard/assets/umi-native.js')]:
            if actual.read_bytes() != expected.read_bytes():
                raise SystemExit('Native asset is not reproducible: ' + expected.name)
        assert '/v2bx" style=' not in (ROOT / 'resources/views/admin.blade.php').read_text()
        assert 'href="/connections"' not in (ROOT / 'theme/Xboard/dashboard.blade.php').read_text()
        print('Native admin and user assets reproduce exactly; no separate application entry points.')

if __name__ == '__main__':
    main()
