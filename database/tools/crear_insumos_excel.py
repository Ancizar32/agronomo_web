#!/usr/bin/env python3
"""Genera el SQL de carga de insumos (agroquímicos) desde agroquimicos.xlsx.

Uso:
    python3 database/tools/crear_insumos_excel.py ARCHIVO.xlsx SALIDA.sql

Columnas esperadas en la primera hoja: Agroquímico | Tipo | Unidade de Medida.
- "Tipo" se guarda en insumos.categoria (texto libre en la app). Se unifican
  mayúsculas/espacios: "Insecticida " e "insecticida" quedan como una sola.
- La unidad se lleva a las que acepta la app: g→Gr, mL→Cc, kg→Kg, L→Lt,
  Unidad/Und→Und.
- El código (id) sigue el patrón de la app: 2 letras de la categoría + número
  consecutivo (FE007, FU002...). El consecutivo continúa desde el mayor código
  existente en la BD con ese prefijo.
- Es repetible: no inserta un insumo si ya existe otro con el mismo nombre
  (la comparación de MySQL ignora mayúsculas y tildes). Los repetidos dentro
  del mismo Excel se omiten y se avisan.
"""
import re
import sys
import unicodedata
from pathlib import Path

from crear_labores_excel import leer_xlsx, sql

ACTOR = 'carga_excel'
UNIDADES = {'g': 'Gr', 'gr': 'Gr', 'kg': 'Kg', 'ml': 'Cc', 'cc': 'Cc', 'l': 'Lt', 'lt': 'Lt',
            'unidad': 'Und', 'und': 'Und', 'un': 'Und'}
LARGO_NOMBRE_WEB = 30  # límite del formulario web al editar


def sin_tildes(s):
    return ''.join(c for c in unicodedata.normalize('NFD', s) if unicodedata.category(c) != 'Mn')


def clave(s):
    return re.sub(r'\s+', ' ', sin_tildes(s).lower()).strip()


def prefijo(categoria):
    letras = re.sub(r'[^A-Z]', '', sin_tildes(categoria).upper())
    return (letras + 'XX')[:2]


def main(xlsx, salida):
    filas = leer_xlsx(xlsx)
    enc = [clave(h or '') for h in filas[0]]
    i_nom = next(i for i, h in enumerate(enc) if h.startswith('agroquimico'))
    i_tipo = enc.index('tipo')
    i_und = next(i for i, h in enumerate(enc) if h.startswith('unidad'))

    categorias = {}      # clave -> forma canónica (primera aparición)
    insumos, vistos, avisos = [], {}, []
    for n, f in enumerate(filas[1:], start=2):
        f = f + [None] * (len(enc) - len(f))
        nombre, tipo, und = f[i_nom], f[i_tipo], f[i_und]
        if not nombre:
            continue
        if not tipo or not und:
            avisos.append(f'Fila {n}: "{nombre}" sin tipo o unidad; se omite.')
            continue
        unidad = UNIDADES.get(und.lower())
        if not unidad:
            avisos.append(f'Fila {n}: "{nombre}" con unidad desconocida "{und}"; se omite.')
            continue
        tipo = re.sub(r'\s*-\s*', '- ', tipo)  # "Fungicida-Bactericida" = "Fungicida- Bactericida"
        categoria = categorias.setdefault(clave(tipo), tipo[0].upper() + tipo[1:])
        k = clave(nombre)
        if k in vistos:
            avisos.append(f'Fila {n}: "{nombre}" ({tipo}) repetido de la fila {vistos[k]}; se omite.')
            continue
        vistos[k] = n
        if len(nombre) > LARGO_NOMBRE_WEB:
            avisos.append(f'Fila {n}: "{nombre}" tiene {len(nombre)} caracteres; la web solo deja editar hasta {LARGO_NOMBRE_WEB}.')
        insumos.append((nombre, unidad, categoria))

    contador = {}
    s = []
    w = s.append
    w('-- Insumos (agroquímicos) generados por database/tools/crear_insumos_excel.py')
    w(f'-- Origen: {Path(xlsx).name}. {len(insumos)} insumos en {len(categorias)} categorías.')
    w('-- Repetible: no inserta un insumo si ya existe uno con el mismo nombre.')
    for a in avisos:
        w(f'-- AVISO {a}')
    w('SET NAMES utf8mb4;')
    w('START TRANSACTION;')
    w('')
    w('-- Mayor consecutivo existente por prefijo (el nuevo código continúa desde ahí)')
    for p in sorted({prefijo(c) for _, _, c in insumos}):
        w(f"SET @base_{p} = (SELECT COALESCE(MAX(CAST(SUBSTRING(id, 3) AS UNSIGNED)), 0) FROM insumos WHERE id REGEXP '^{p}[0-9]+$');")
    w('')
    for nombre, unidad, categoria in insumos:
        p = prefijo(categoria)
        contador[p] = contador.get(p, 0) + 1
        n = f'CAST(@base_{p} + {contador[p]} AS UNSIGNED)'  # la variable de sesión llega como DECIMAL
        codigo = f"CONCAT('{p}', LPAD({n}, GREATEST(3, LENGTH({n})), '0'))"
        w('INSERT INTO insumos (id, nombre, unidad_medida, categoria, sync, voided, created_at, updated_at, created_by)')
        w(f"SELECT {codigo}, {sql(nombre)}, {sql(unidad)}, {sql(categoria)}, '1', '1', NOW(), NOW(), {sql(ACTOR)}")
        w(f'FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM insumos WHERE TRIM(nombre) = {sql(nombre)});')
    w('')
    w('COMMIT;')
    w('')
    w('SELECT categoria, COUNT(*) AS insumos FROM insumos')
    w(f"WHERE created_by = {sql(ACTOR)} GROUP BY categoria ORDER BY categoria;")
    Path(salida).write_text('\n'.join(s) + '\n', encoding='utf-8')
    print(f'{salida}: {len(insumos)} insumos, {len(categorias)} categorías')
    for a in avisos:
        print('AVISO', a)


if __name__ == '__main__':
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
