-- Identificador único por instalación de la app móvil.
-- Antes la sesión anterior se revocaba por device_name (modelo del equipo),
-- por lo que dos equipos del mismo modelo, o dos simuladores, se revocaban
-- mutuamente el token. Ahora solo se reemplaza la sesión de la misma
-- instalación. Idempotente.

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='auth_tokens' AND COLUMN_NAME='installation_id')=0,
    'ALTER TABLE auth_tokens ADD COLUMN installation_id VARCHAR(64) NULL AFTER device_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='auth_tokens' AND INDEX_NAME='idx_auth_tokens_installation')=0,
    'ALTER TABLE auth_tokens ADD KEY idx_auth_tokens_installation (user_id, installation_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
