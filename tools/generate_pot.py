import json
from pathlib import Path

root = Path(__file__).resolve().parent.parent
catalog = json.loads((root / 'languages' / 'en_US.json').read_text(encoding='utf-8'))

pot_header = """# Copyright (C) 2026 Ali Abdollahzadeh
# This file is distributed under the GPLv2 or later.
msgid ""
msgstr ""
"Project-Id-Version: Product Workflow for WooCommerce 1.2.0\\n"
"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/product-workflow-for-woocommerce\\n"
"Last-Translator: Ali Abdollahzadeh\\n"
"Language-Team: English\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: 2026-09-05T21:00:00+00:00\\n"
"PO-Revision-Date: 2026-09-05T21:00:00+00:00\\n"
"X-Domain: product-workflow-for-woocommerce\\n"

"""

entries = []
for msgid in sorted(catalog.keys()):
    clean_id = msgid.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')
    entries.append(f'msgid "{clean_id}"\nmsgstr ""\n')

pot_file = root / 'languages' / 'product-workflow-for-woocommerce.pot'
pot_file.write_text(pot_header + '\n'.join(entries), encoding='utf-8')
print(f'Generated {pot_file} with {len(entries)} strings.')
