#!/usr/bin/env python3
"""Genera el SQL de carga de labores y categorías de labor desde Labores.xlsx.

Uso:
    python3 database/tools/crear_labores_excel.py ARCHIVO.xlsx SALIDA.sql [CULTIVO]

CULTIVO es la descripción del cultivo al que se asignan las labores
(por defecto "Gulupa"); debe existir en la BD.

Columnas esperadas en la primera hoja: Nro | Labor | Tipo de Labor.
- Cada "Tipo de Labor" distinto es una categoría (categorias_labor).
- Cada fila es una labor (labores) con codigo = Nro.
- Es repetible: no duplica categorías que ya existan con la misma descripción
  ni labores que ya existan con el mismo nombre en ese cultivo.
Lee el .xlsx con la librería estándar (no requiere openpyxl).
"""
import hashlib
import re
import sys
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

NS = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
ACTOR = 'carga_excel'


def uid(*parts):
    return hashlib.md5('|'.join(str(p) for p in parts).encode()).hexdigest()


def clean(v):
    if v is None:
        return None
    v = re.sub(r'\s+', ' ', str(v)).strip()
    return v or None


def sql(v):
    if v is None:
        return 'NULL'
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


def col_index(ref):
    letras = re.match(r'[A-Z]+', ref).group()
    n = 0
    for ch in letras:
        n = n * 26 + ord(ch) - 64
    return n - 1


def leer_xlsx(path):
    z = zipfile.ZipFile(path)
    shared = []
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in ET.fromstring(z.read('xl/sharedStrings.xml')).iter(NS + 'si'):
            shared.append(''.join(t.text or '' for t in si.iter(NS + 't')))
    hoja = sorted(n for n in z.namelist() if re.fullmatch(r'xl/worksheets/sheet\d+\.xml', n))[0]
    filas = []
    for row in ET.fromstring(z.read(hoja)).iter(NS + 'row'):
        valores = {}
        for c in row.iter(NS + 'c'):
            v, t = c.find(NS + 'v'), c.get('t')
            if t == 's' and v is not None:
                val = shared[int(v.text)]
            elif t == 'inlineStr':
                val = ''.join(x.text or '' for x in c.iter(NS + 't'))
            else:
                val = v.text if v is not None else None
            valores[col_index(c.get('r'))] = clean(val)
        if any(valores.values()):
            filas.append([valores.get(i) for i in range(max(valores) + 1)])
    return filas


def main(xlsx, salida, cultivo):
    filas = leer_xlsx(xlsx)
    enc = [(h or '').lower() for h in filas[0]]
    i_nro, i_labor, i_tipo = enc.index('nro'), enc.index('labor'), enc.index('tipo de labor')

    categorias, labores, vistos = [], [], set()
    for f in filas[1:]:
        f = f + [None] * (len(enc) - len(f))
        nombre, tipo = f[i_labor], f[i_tipo]
        if not nombre or not tipo:
            continue
        nro = f[i_nro]
        if nro and re.fullmatch(r'\d+(\.0+)?', nro):
            nro = str(int(float(nro)))
        if tipo not in categorias:
            categorias.append(tipo)
        clave = nombre.lower()
        if clave in vistos:
            print(f'Aviso: labor repetida en el Excel, se omite: {nombre}', file=sys.stderr)
            continue
        vistos.add(clave)
        labores.append((nro, nombre, tipo))

    s = []
    w = s.append
    w('-- Labores y categorías generadas por database/tools/crear_labores_excel.py')
    w(f'-- Origen: {Path(xlsx).name}. {len(categorias)} categorías, {len(labores)} labores, cultivo: {cultivo}.')
    w('-- Repetible: no duplica categorías (por descripción) ni labores (por nombre + cultivo).')
    w('SET NAMES utf8mb4;')
    w('START TRANSACTION;')
    w('')
    w(f'SET @cultivo_id = (SELECT id FROM cultivos WHERE descripcion = {sql(cultivo)} AND voided = \'1\' LIMIT 1);')
    w(f"SELECT IF(@cultivo_id IS NULL, 'ERROR: no existe el cultivo activo {cultivo}; no se cargaron labores', "
      f"CONCAT('Cultivo destino: ', @cultivo_id)) AS verificacion;")
    w('')
    w('-- Categorías de labor')
    for cat in categorias:
        w('INSERT INTO categorias_labor (id, descripcion, codigo, sync, voided, created_at, updated_at, created_by)')
        w(f"SELECT {sql(uid('categoria_labor', cat.lower()))}, {sql(cat)}, NULL, '1', '1', NOW(), NOW(), {sql(ACTOR)}")
        w(f'FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM categorias_labor WHERE LOWER(TRIM(descripcion)) = LOWER({sql(cat)}));')
    w('')
    w('-- Labores')
    for nro, nombre, tipo in labores:
        w(f'-- {nro or "-"} · {tipo}')
        w('INSERT INTO labores (id, nombre, codigo, cultivo_id, categoria_labor_id, sync, voided, created_at, updated_at, created_by)')
        w(f"SELECT {sql(uid('labor', cultivo.lower(), nombre.lower()))}, {sql(nombre)}, {sql(nro)}, @cultivo_id, cl.id, '1', '1', NOW(), NOW(), {sql(ACTOR)}")
        w(f'FROM categorias_labor cl WHERE LOWER(TRIM(cl.descripcion)) = LOWER({sql(tipo)}) AND @cultivo_id IS NOT NULL')
        w(f'  AND NOT EXISTS (SELECT 1 FROM labores l WHERE l.cultivo_id = @cultivo_id AND LOWER(TRIM(l.nombre)) = LOWER({sql(nombre)}))')
        w('ORDER BY cl.voided DESC, cl.created_at LIMIT 1;')
    w('')
    w('COMMIT;')
    w('')
    w('SELECT cl.descripcion AS categoria, COUNT(l.id) AS labores')
    w('FROM categorias_labor cl JOIN labores l ON l.categoria_labor_id = cl.id AND l.cultivo_id = @cultivo_id')
    w('GROUP BY cl.descripcion ORDER BY cl.descripcion;')
    Path(salida).write_text('\n'.join(s) + '\n', encoding='utf-8')
    print(f'{salida}: {len(categorias)} categorías, {len(labores)} labores')


if __name__ == '__main__':
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else 'Gulupa')
