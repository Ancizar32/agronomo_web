-- Repara visitas que quedaron "finalizadas" sin firma.
-- createVisita insertaba el JSON del dispositivo convirtiendo null en '';
-- MySQL (modo no estricto) lo guardó como 0000-00-00 00:00:00 y la app,
-- al descargar, interpretaba esa fecha como visita finalizada.
-- Solo se reabren visitas SIN firma activa; las firmadas no se tocan.
-- Idempotente. Revisar antes con:
--   SELECT id, finca_id, fecha, finalizada_at FROM visitas_tecnicas
--   WHERE finalizada_at IS NOT NULL AND finalizada_at < '1900-01-01';

SET @old_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '');

UPDATE visitas_tecnicas vt
SET vt.finalizada_at = NULL
WHERE vt.finalizada_at IS NOT NULL
  AND vt.finalizada_at < '1900-01-01'
  AND NOT EXISTS (
      SELECT 1 FROM visita_firmas vf
      WHERE vf.visita_id = vt.id AND vf.voided = '1'
  );

UPDATE visitas_tecnicas
SET location_captured_at = NULL
WHERE location_captured_at IS NOT NULL
  AND location_captured_at < '1900-01-01';

SET SESSION sql_mode = @old_sql_mode;
