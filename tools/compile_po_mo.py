import json
import struct
from pathlib import Path

root = Path(__file__).resolve().parent.parent
langs_dir = root / 'languages'

def make_mo(entries):
    # entries is a dict of {orig: trans}
    keys = sorted(entries.keys())
    # Header format
    N = len(keys)
    orig_table = []
    trans_table = []
    orig_data = bytearray()
    trans_data = bytearray()

    for k in keys:
        kb = k.encode('utf-8')
        vb = entries[k].encode('utf-8')
        orig_table.append((len(kb), len(orig_data)))
        orig_data.extend(kb + b'\x00')
        trans_table.append((len(vb), len(trans_data)))
        trans_data.extend(vb + b'\x00')

    key_start = 28 + N * 16
    val_start = key_start + len(orig_data)

    header = struct.pack(
        '<Iiiiiii',
        0x950412DE,  # Magic
        0,           # Version
        N,           # Number of strings
        28,          # Offset of original strings table
        28 + N * 8,  # Offset of translation strings table
        0,           # Hash table size
        0            # Hash table offset
    )

    orig_indices = bytearray()
    for length, offset in orig_table:
        orig_indices.extend(struct.pack('<ii', length, key_start + offset))

    trans_indices = bytearray()
    for length, offset in trans_table:
        trans_indices.extend(struct.pack('<ii', length, val_start + offset))

    return bytes(header + orig_indices + trans_indices + orig_data + trans_data)

def generate_po(entries, locale_name):
    header = f'''# Translation of Product Workflow for WooCommerce into {locale_name}
# Copyright (C) 2026 Ali Abdollahzadeh
# This file is distributed under the GPLv2 or later.
msgid ""
msgstr ""
"Project-Id-Version: Product Workflow for WooCommerce 1.2.0\\n"
"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/product-workflow\\n"
"POT-Creation-Date: 2026-09-05T21:00:00+00:00\\n"
"PO-Revision-Date: 2026-09-05T21:00:00+00:00\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: product-workflow\\n"

'''
    po_lines = [header]
    for orig in sorted(entries.keys()):
        if not orig:
            continue
        trans = entries[orig]
        clean_orig = orig.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')
        clean_trans = trans.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')
        po_lines.append(f'msgid "{clean_orig}"\nmsgstr "{clean_trans}"\n\n')
    return ''.join(po_lines)

for code, name in [('fa_IR', 'Persian'), ('it_IT', 'Italian')]:
    json_path = langs_dir / f'{code}.json'
    if not json_path.is_file():
        continue
    data = json.loads(json_path.read_text(encoding='utf-8'))
    # Include PO header in gettext table as empty msgid
    entries_for_mo = {'': ''}
    entries_for_mo.update(data)

    po_content = generate_po(data, name)
    (langs_dir / f'product-workflow-{code}.po').write_text(po_content, encoding='utf-8')

    mo_bytes = make_mo(entries_for_mo)
    (langs_dir / f'product-workflow-{code}.mo').write_bytes(mo_bytes)

    print(f'Generated product-workflow-{code}.po and product-workflow-{code}.mo ({len(data)} strings)')
