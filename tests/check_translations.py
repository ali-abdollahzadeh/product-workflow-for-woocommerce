"""Validate catalog coverage and formatting placeholders without dependencies."""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parent.parent
english = json.loads((root / 'languages/en_US.json').read_text(encoding='utf-8'))
placeholder = re.compile(r'%(?:\d+\$)?[ds]')
for locale in ('en_US', 'fa_IR', 'it_IT'):
    catalog = json.loads((root / f'languages/{locale}.json').read_text(encoding='utf-8'))
    assert catalog.keys() == english.keys(), f'{locale}: missing or extra messages'
    for key, value in catalog.items():
        assert value.strip(), (locale, key)
        assert placeholder.findall(key) == placeholder.findall(value), (locale, key)
        assert '<' not in value and '>' not in value, (locale, key, 'HTML in translation')
    print(f'{locale}: {len(catalog)} messages verified')
for directory in ('admin', 'api', 'includes'):
    for path in (root / directory).glob('*.php'):
        for key in re.findall(r"pwf_t\('([^']*)'\)", path.read_text(encoding='utf-8')):
            assert key in english, f'{path.name}: missing {key}'
print('Translation coverage and placeholders passed.')
