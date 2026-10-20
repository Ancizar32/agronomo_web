-- ============================================================================
-- Recalcula las fechas estimadas de los planes de cosecha cargados desde el
-- Excel del cliente (created_by = 'carga_excel').
--
--   fecha estimada de la cosecha n = @inicio + (@meses × n) meses
--
-- Ejemplos:
--   Cosechas anuales a partir de hoy ......... @meses = 12, @inicio = NULL
--   Cada 6 meses desde el 1 de enero de 2027 ... @meses = 6,  @inicio = '2027-01-01'
--
-- Solo se actualizan cosechas que:
--   - siguen en estado PENDIENTE y no tienen cosechas reales registradas;
--   - no han sido editadas desde la web (updated_at = created_at). Para incluir
--     también las editadas, poner @incluir_editadas = 1.
-- Se conserva updated_at para que el script pueda ejecutarse de nuevo con
-- otros valores sin confundir sus cambios con ediciones de los técnicos.
--
-- Uso: ajustar los parámetros, ejecutar primero solo la VISTA PREVIA y, si el
-- resultado es correcto, ejecutar el archivo completo.
-- ============================================================================

-- ---------------- Parámetros ----------------
SET @meses = 12;               -- meses entre cosechas
SET @inicio = NULL;            -- 'AAAA-MM-DD'; NULL conserva la fecha de inicio de cada plan
SET @incluir_editadas = 0;     -- 1 = también cosechas editadas por los técnicos

-- ---------------- Vista previa ----------------
SELECT p.nombre AS plan, cp.numero, cp.fecha_estimada AS fecha_actual,
       DATE_ADD(COALESCE(@inicio, p.fecha_inicio), INTERVAL @meses * cp.numero MONTH) AS fecha_nueva,
       cp.kg_proyectado
FROM planes_cosecha p
JOIN cosechas_proyectadas cp ON cp.plan_id = p.id AND cp.voided = '1'
WHERE p.created_by = 'carga_excel' AND p.voided = '1'
  AND cp.estado = 'PENDIENTE'
  AND NOT EXISTS (SELECT 1 FROM cosechas_reales cr WHERE cr.cosecha_proyectada_id = cp.id AND cr.voided = '1')
  AND (@incluir_editadas = 1 OR cp.updated_at = cp.created_at)
ORDER BY p.nombre, cp.numero;

-- ---------------- Actualización ----------------
START TRANSACTION;

UPDATE cosechas_proyectadas cp
JOIN planes_cosecha p ON p.id = cp.plan_id
SET cp.fecha_estimada = DATE_ADD(COALESCE(@inicio, p.fecha_inicio), INTERVAL @meses * cp.numero MONTH),
    cp.updated_at = cp.updated_at
WHERE p.created_by = 'carga_excel' AND p.voided = '1' AND cp.voided = '1'
  AND cp.estado = 'PENDIENTE'
  AND NOT EXISTS (SELECT 1 FROM cosechas_reales cr WHERE cr.cosecha_proyectada_id = cp.id AND cr.voided = '1')
  AND (@incluir_editadas = 1 OR cp.updated_at = cp.created_at);
SELECT ROW_COUNT() AS cosechas_actualizadas;

-- El inicio del plan y la nota sobre fechas estimadas siguen al nuevo cálculo.
UPDATE planes_cosecha p
SET p.fecha_inicio = COALESCE(@inicio, p.fecha_inicio),
    p.observacion = REGEXP_REPLACE(p.observacion, 'cada [0-9]+ meses', CONCAT('cada ', @meses, ' meses')),
    p.updated_at = p.updated_at
WHERE p.created_by = 'carga_excel' AND p.voided = '1'
  AND (@incluir_editadas = 1 OR p.updated_at = p.created_at);

COMMIT;

-- ---------------- Verificación ----------------
SELECT COUNT(DISTINCT p.id) AS planes, COUNT(cp.id) AS cosechas,
       MIN(cp.fecha_estimada) AS primera_cosecha, MAX(cp.fecha_estimada) AS ultima_cosecha
FROM planes_cosecha p
JOIN cosechas_proyectadas cp ON cp.plan_id = p.id AND cp.voided = '1'
WHERE p.created_by = 'carga_excel' AND p.voided = '1';
