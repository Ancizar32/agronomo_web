#!/usr/bin/env python3
"""Crea los usuarios de los técnicos del Excel "Info Agrónomo" y les asigna sus predios.

Uso:
    python3 database/tools/crear_tecnicos_info_agronomo.py ARCHIVO.xlsx DIRECTORIO_SALIDA

Produce en DIRECTORIO_SALIDA:
    tecnicos_info_agronomo.sql   Ejecutar después de carga_info_agronomo.sql.
    tecnicos_credenciales.md     Usuario y contraseña provisional de cada técnico.
                                 Es información sensible: no se versiona ni se comparte por canales abiertos.

Reglas:
- Igual que el formulario web: ID numérico consecutivo (desde 1000), rol "tecnico",
  contraseña provisional de 4 dígitos y pass_provi = '1', que obliga a cambiarla
  en el primer ingreso. El hash es bcrypt con prefijo $2y$, el mismo de password_hash() de PHP.
- Si ya existe un usuario con el mismo login o el mismo nombre, no se crea otro:
  se reutiliza y se conserva su contraseña.
- Los predios se asignan en usuario_fincas a todos sus técnicos. fincas.tecnico_id
  (que usan las versiones anteriores de la app móvil) recibe el técnico con más
  lotes en el predio, solo si el predio no tenía técnico.

Cada ejecución genera contraseñas nuevas. Solo aplican a los usuarios que ese SQL
crea; para un usuario existente, la contraseña se restablece desde la web.
"""
import re
import secrets
import sys
import unicodedata
from collections import defaultdict
from pathlib import Path

import bcrypt

from importar_info_agronomo import ACTOR, construir, leer, sql

LOGIN = re.compile(r'^[a-z][a-z0-9.]{2,19}$')


def login_de(nombre):
    """'Juan David Davalos' → 'juan.davalos' (primer nombre + último apellido, sin tildes)."""
    ascii_ = unicodedata.normalize('NFKD', nombre).encode('ascii', 'ignore').decode().lower()
    partes = re.findall(r'[a-z0-9]+', ascii_)
    login = f'{partes[0]}.{partes[-1]}'[:20] if len(partes) > 1 else partes[0][:20]
    if not LOGIN.match(login):
        raise ValueError(f'No se pudo derivar un usuario válido para "{nombre}": {login}')
    return login


def hash_php(clave):
    # bcrypt de Python usa el prefijo $2b$; PHP genera $2y$. El algoritmo es el mismo.
    return '$2y$' + bcrypt.hashpw(clave.encode(), bcrypt.gensalt(rounds=10)).decode()[4:]


def main(xlsx, outdir):
    _, predios, _ = construir(leer(xlsx))

    asignados = defaultdict(list)    # técnico → predios
    principal = defaultdict(list)    # técnico → predios donde es el técnico principal
    sin_tecnico = []
    for f in predios.values():
        if not f['tecnicos']:
            sin_tecnico.append(f)
        for i, t in enumerate(f['tecnicos']):
            asignados[t].append(f)
            if i == 0:
                principal[t].append(f)

    tecnicos = []
    for nombre in sorted(asignados):
        tecnicos.append({'nombre': nombre, 'login': login_de(nombre),
                         'clave': str(1000 + secrets.randbelow(9000))})
    logins = [t['login'] for t in tecnicos]
    if len(set(logins)) != len(logins):
        sys.exit(f'Hay usuarios repetidos: {logins}')

    s = []
    w = s.append
    w('-- Técnicos y asignación de predios generados por database/tools/crear_tecnicos_info_agronomo.py')
    w(f'-- Origen: {Path(xlsx).name}. Ejecutar después de carga_info_agronomo.sql. Es repetible.')
    w('-- Las contraseñas provisionales están en tecnicos_credenciales.md, no en este archivo.')
    w('SET NAMES utf8mb4;')
    w('START TRANSACTION;')
    w("SET @rol = (SELECT id FROM roles WHERE codigo = 'tecnico' AND activo = 1 LIMIT 1);")
    for t in tecnicos:
        nombre, login = sql(t['nombre']), sql(t['login'])
        existe = f"FROM `user` WHERE `user` = {login} OR TRIM(name) = {nombre}"
        w('')
        w(f"-- {t['nombre']} · {len(asignados[t['nombre']])} predio(s)")
        w("SET @next = (SELECT CAST(GREATEST(COALESCE(MAX(CAST(id AS UNSIGNED)), 0) + 1, 1000) AS CHAR) "
          "FROM `user` WHERE id REGEXP '^[0-9]+$');")
        w(f"INSERT INTO `user` (id, name, `user`, code, password_hash, mail, void, roll, rol_id, pass_provi, user_crea, date_crea)\n"
          f"SELECT @next, {nombre}, {login}, {sql(t['clave'])}, {sql(hash_php(t['clave']))}, '', '1', '1', @rol, '1', "
          f"{sql(ACTOR)}, NOW()\n"
          f"FROM DUAL WHERE NOT EXISTS (SELECT 1 {existe});")
        w(f"SET @usuario = (SELECT id {existe} ORDER BY `user` = {login} DESC LIMIT 1);")
        ids = ', '.join(sql(f['id']) for f in asignados[t['nombre']])
        w(f"INSERT IGNORE INTO usuario_fincas (usuario_id, finca_id, created_by)\n"
          f"SELECT @usuario, id, {sql(ACTOR)} FROM fincas WHERE id IN ({ids});")
        if principal[t['nombre']]:
            ids = ', '.join(sql(f['id']) for f in principal[t['nombre']])
            w(f"UPDATE fincas SET tecnico_id = @usuario, sync = '1', updated_at = NOW()\n"
              f"WHERE id IN ({ids}) AND (tecnico_id IS NULL OR tecnico_id = '');")
    w('')
    w('COMMIT;')
    w('')
    w('-- Verificación: predios asignados por técnico')
    w("SELECT u.id, u.name, u.`user`, u.pass_provi, COUNT(uf.finca_id) AS predios,\n"
      "       SUM(f.tecnico_id = u.id) AS predios_principal\n"
      "FROM `user` u LEFT JOIN usuario_fincas uf ON uf.usuario_id = u.id LEFT JOIN fincas f ON f.id = uf.finca_id\n"
      f"WHERE u.`user` IN ({', '.join(sql(l) for l in logins)})\nGROUP BY u.id, u.name, u.`user`, u.pass_provi ORDER BY u.name;")
    w("-- Predios de la carga que quedaron sin técnico")
    w(f"SELECT f.descripcion, f.vereda FROM fincas f WHERE f.created_by = {sql(ACTOR)}\n"
      "  AND NOT EXISTS (SELECT 1 FROM usuario_fincas uf WHERE uf.finca_id = f.id) ORDER BY f.descripcion;")

    out = Path(outdir)
    out.mkdir(parents=True, exist_ok=True)
    (out / 'tecnicos_info_agronomo.sql').write_text('\n'.join(s) + '\n', encoding='utf-8')

    md = ['# Credenciales provisionales de técnicos', '',
          'Información sensible: entregar a cada técnico por un canal privado y no reenviar este archivo.',
          'Al ingresar por primera vez, el sistema pide cambiar la contraseña.',
          'Solo aplican a usuarios creados por el SQL generado junto con este archivo; '
          'si el usuario ya existía, conserva su contraseña.', '',
          '| Técnico | Usuario | Contraseña provisional | Predios |', '|---|---|---|---|']
    md += [f"| {t['nombre']} | {t['login']} | {t['clave']} | {len(asignados[t['nombre']])} |" for t in tecnicos]
    md += ['', f'## Predios sin técnico ({len(sin_tecnico)})', '']
    md += [f"- {f['nombre']} ({f['productor']['nombre']}): "
           f"{', '.join(sorted({r.get('Asistente Técnico') or 'vacío' for _, r in f['lotes']}))}" for f in sin_tecnico]
    (out / 'tecnicos_credenciales.md').write_text('\n'.join(md) + '\n', encoding='utf-8')
    print(f'{len(tecnicos)} técnicos, {sum(map(len, asignados.values()))} asignaciones, '
          f'{len(sin_tecnico)} predios sin técnico → {out}')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
