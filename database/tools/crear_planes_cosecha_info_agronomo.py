#!/usr/bin/env python3
"""Crea los planes de cosecha de los lotes del Excel "Info Agrónomo".

Uso:
    python3 database/tools/crear_planes_cosecha_info_agronomo.py ARCHIVO.xlsx DIRECTORIO_SALIDA [AAAA-MM-DD]

La fecha opcional es el inicio de los planes (por defecto, hoy). Produce
planes_cosecha_info_agronomo.sql, que se ejecuta después de carga_info_agronomo.sql.

Reglas:
- Un plan ACTIVO por lote activo que tenga proyección (número de cosechas > 0).
- El Excel no trae fechas: la cosecha n se estima en inicio + 3·n meses.
- Plantas del plan = plantas actuales del lote (total del lote, como viene en el
  Excel; si no hay conteo, las sembradas). kg proyectados = kg/planta × plantas.
- Finca y cultivo se toman del lote ya cargado en la BD.
- Es repetible sin pisar nada: si el plan o la cosecha ya existen se dejan
  como están, para no borrar ajustes de los técnicos ni cosechas reales.
"""
import datetime
import sys
from pathlib import Path

from importar_info_agronomo import ACTOR, construir, leer, sql, to_date, uid

MESES_ENTRE_COSECHAS = 3


def sumar_meses(fecha, meses):
    m = fecha.month - 1 + meses
    anio, mes = fecha.year + m // 12, m % 12 + 1
    dias_mes = (datetime.date(anio + mes // 12, mes % 12 + 1, 1) - datetime.timedelta(days=1)).day
    return datetime.date(anio, mes, min(fecha.day, dias_mes))


def main(xlsx, outdir, inicio):
    _, predios, _ = construir(leer(xlsx))
    s = []
    w = s.append
    w('-- Planes de cosecha generados por database/tools/crear_planes_cosecha_info_agronomo.py')
    w(f'-- Origen: {Path(xlsx).name}. Inicio {inicio}; cosechas cada {MESES_ENTRE_COSECHAS} meses (fechas estimadas).')
    w('-- Ejecutar después de carga_info_agronomo.sql. Es repetible: no modifica planes ya existentes.')
    w('SET NAMES utf8mb4;')
    w('START TRANSACTION;')

    planes = cosechas = 0
    omitidos = []
    for f in predios.values():
        for n, r in f['lotes']:
            if str(r.get('Estatus')).lower() != 'activo':
                continue
            numero = r.get('Numero de Cosechas Proyectadas') or 0
            kgs = [r.get(f'Productividad Proyectada por Cosecha {k} (Kg/pl)') for k in range(1, numero + 1)]
            kgs = [kg for kg in kgs if kg]
            plantas = r.get('Número de plantas /Ha actuales') or r.get('Número de plantas /Ha sembradas')
            etiqueta = f"{f['nombre']} {r['Lote']}"
            if not kgs:
                continue
            if not plantas:
                omitidos.append(f'Fila {n} · {etiqueta}: sin número de plantas')
                continue

            lote_id = uid('lote', f['id'], r['Lote'].upper())
            plan_id = uid('plan', lote_id)
            siembra = to_date(r.get('Fecha de siembra'))
            observacion = (f"Cargado desde Excel ({Path(xlsx).name}). Fechas estimadas cada "
                           f"{MESES_ENTRE_COSECHAS} meses; ajustarlas con el técnico."
                           + (f" Siembra: {siembra}." if siembra else ''))
            w('')
            w(f"-- {etiqueta} · {f['productor']['nombre']} · {len(kgs)} cosecha(s) · {plantas} plantas")
            w(f"INSERT IGNORE INTO planes_cosecha (id, finca_id, lote_id, cultivo_id, nombre, fecha_inicio, "
              f"plantas_productivas, estado, observacion, voided, created_at, updated_at, created_by)\n"
              f"SELECT {sql(plan_id)}, l.finca_id, l.id, l.cultivo_id, {sql('Cosecha ' + etiqueta)}, {sql(inicio)}, "
              f"{plantas}, 'ACTIVO', {sql(observacion)}, '1', NOW(), NOW(), {sql(ACTOR)}\n"
              f"FROM lotes l WHERE l.id = {sql(lote_id)} AND l.voided = '1';")
            for i, kg in enumerate(kgs, start=1):
                w(f"INSERT IGNORE INTO cosechas_proyectadas (id, plan_id, numero, fecha_estimada, kg_planta_proyectado, "
                  f"plantas_proyectadas, kg_proyectado, observacion, estado, voided, created_at, updated_at, created_by)\n"
                  f"SELECT {sql(uid('cosecha', plan_id, i))}, p.id, {i}, "
                  f"{sql(sumar_meses(inicio, MESES_ENTRE_COSECHAS * i))}, {sql(kg)}, {plantas}, {sql(round(kg * plantas, 3))}, "
                  f"NULL, 'PENDIENTE', '1', NOW(), NOW(), {sql(ACTOR)}\n"
                  f"FROM planes_cosecha p WHERE p.id = {sql(plan_id)};")
            planes += 1
            cosechas += len(kgs)

    w('')
    w('COMMIT;')
    w('')
    w('-- Verificación')
    w(f"SELECT COUNT(DISTINCT p.id) AS planes, COUNT(cp.id) AS cosechas, ROUND(SUM(cp.kg_proyectado)) AS kg_proyectados,\n"
      f"       MIN(cp.fecha_estimada) AS primera_cosecha, MAX(cp.fecha_estimada) AS ultima_cosecha\n"
      f"FROM planes_cosecha p LEFT JOIN cosechas_proyectadas cp ON cp.plan_id = p.id AND cp.voided = '1'\n"
      f"WHERE p.created_by = {sql(ACTOR)} AND p.voided = '1';")
    for o in omitidos:
        w(f'-- Omitido: {o}')

    out = Path(outdir)
    out.mkdir(parents=True, exist_ok=True)
    (out / 'planes_cosecha_info_agronomo.sql').write_text('\n'.join(s) + '\n', encoding='utf-8')
    print(f'{planes} planes, {cosechas} cosechas, {len(omitidos)} omitidos → {out}')


if __name__ == '__main__':
    if len(sys.argv) not in (3, 4):
        sys.exit(__doc__)
    fecha = datetime.date.fromisoformat(sys.argv[3]) if len(sys.argv) == 4 else datetime.date.today()
    main(sys.argv[1], sys.argv[2], fecha)
