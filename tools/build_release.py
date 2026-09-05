import re
import shutil
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parent.parent
main_php = (root / 'product-workflow.php').read_text(encoding='utf-8')
m = re.search(r"define\('PWF_VERSION',\s*'([^']+)'\);", main_php)
version = m.group(1) if m else '1.0.0'

output = root / 'dist' / f'product-workflow-{version}.zip'
output.parent.mkdir(exist_ok=True)
# Production files for WordPress.org directory submission
root_files = ('product-workflow.php', 'readme.txt', 'uninstall.php', 'LICENSE', 'CHANGELOG.md', 'SECURITY.md')
files = [root / name for name in root_files if (root / name).is_file()]
for directory in ('admin', 'api', 'assets', 'includes', 'integrations', 'languages'):
    files.extend(path for path in (root / directory).rglob('*') if path.is_file())
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for path in sorted(files):
        archive.write(path, 'product-workflow/' + path.relative_to(root).as_posix())
with ZipFile(output) as archive:
    assert archive.testzip() is None
    assert 'product-workflow/product-workflow.php' in archive.namelist()
    assert all('.test-runtime' not in name for name in archive.namelist())

root_zip = root / 'product-workflow.zip'
shutil.copyfile(output, root_zip)
print(f'Built {output} and {root_zip.name} (version {version}; {output.stat().st_size:,} bytes; {len(files)} files)')
