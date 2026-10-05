#!/usr/bin/env python3
"""
Genera el recorte de Bootstrap Icons que el tema manda inline (inc/performance.php):
  - bootstrap-icons-subset.woff2  → solo los glifos de los íconos que se usan
  - bootstrap-icons-subset.css    → sus clases (va inline en el <head>)

Busca "bi-nombre" en el tema (PHP, JS, seed/) y, si se pasa, en el plugin OEC.
Un ícono que no esté en el recorte igual se ve: la hoja completa
(bootstrap-icons.min.css) carga sin bloquear y la fuente completa queda
declarada como respaldo. Conviene volver a correrlo al sumar íconos nuevos.

Uso (necesita fonttools y brotli: pip install fonttools brotli):
  python3 assets/fonts/bootstrap-icons/build-subset.py [ruta/al/oec-wordpress-plugin]
"""
import re
import sys
from pathlib import Path

from fontTools import subset

HERE = Path(__file__).resolve().parent
THEME = HERE.parents[2]
roots = [THEME] + [Path(p) for p in sys.argv[1:]]

names = set()
for root in roots:
    for f in root.rglob('*'):
        if f.suffix not in ('.php', '.js', '.html', '.twig', '.txt', '.css') or 'bootstrap-icons' in f.parts:
            continue
        try:
            names.update(re.findall(r'\bbi-[a-z0-9]+(?:-[a-z0-9]+)*', f.read_text(errors='ignore')))
        except OSError:
            pass

full = (HERE / 'bootstrap-icons.min.css').read_text()
glyphs = dict(re.findall(r'\.(bi-[a-z0-9-]+)::before\{content:"\\([0-9a-f]+)"\}', full))
used = sorted(n for n in names if n in glyphs)
codes = sorted({int(glyphs[n], 16) for n in used})

opts = subset.Options()
opts.flavor = 'woff2'
opts.layout_features = []
opts.name_IDs = []
opts.notdef_outline = True
font = subset.load_font(str(HERE / 'bootstrap-icons.woff2'), opts)
sub = subset.Subsetter(opts)
sub.populate(unicodes=codes)
sub.subset(font)
subset.save_font(font, str(HERE / 'bootstrap-icons-subset.woff2'), opts)


def ranges(cps):
    out, start, prev = [], cps[0], cps[0]
    for c in cps[1:] + [None]:
        if c is not None and c == prev + 1:
            prev = c
            continue
        out.append(f'U+{start:X}' if start == prev else f'U+{start:X}-{prev:X}')
        if c is not None:
            start = prev = c
    return ','.join(out)


base = re.search(r'\.bi::before,\[class\*=" bi-"\]::before,\[class\^=bi-\]::before\{[^}]+\}', full).group(0)
# {URL} lo reemplaza PHP por la carpeta de la fuente. La completa va PRIMERO y
# sin unicode-range: para un glifo que esté en las dos, gana la última
# declarada (el recorte); la completa solo se baja si aparece otro ícono.
css = (
    '@font-face{font-display:block;font-family:bootstrap-icons;'
    'src:url("{URL}/bootstrap-icons.woff2?dd67030699838ea613ee6dbda90effa6") format("woff2")}'
    '@font-face{font-display:block;font-family:bootstrap-icons;'
    'src:url("{URL}/bootstrap-icons-subset.woff2?v=' + str(len(codes)) + '-' + format(sum(codes) % 0xFFFFF, 'x') + '") format("woff2");'
    'unicode-range:' + ranges(codes) + '}'
    + base
    + ''.join(f'.{n}::before{{content:"\\{glyphs[n]}"}}' for n in used)
)
(HERE / 'bootstrap-icons-subset.css').write_text(css + '\n')
print(f'{len(used)} íconos, {len(css)} bytes de CSS, '
      f'{(HERE / "bootstrap-icons-subset.woff2").stat().st_size} bytes de fuente')
