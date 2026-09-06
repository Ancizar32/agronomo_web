ALTER TABLE visitas_tecnicas
  ADD COLUMN finalizada_at DATETIME NULL;

-- Las columnas latitude, longitude, location_accuracy y
-- location_captured_at ya existen en visitas_tecnicas,
-- visita_detalles_lote y visita_detalles_hallazgos.

CREATE TABLE IF NOT EXISTS visita_firmas (
  id VARCHAR(50) NOT NULL PRIMARY KEY,
  visita_id VARCHAR(50) NOT NULL,
  nombre VARCHAR(180) NOT NULL,
  documento VARCHAR(80) NOT NULL,
  rol VARCHAR(60) NOT NULL,
  firma_base64 LONGTEXT NOT NULL,
  signed_at DATETIME NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  location_accuracy DECIMAL(8,2) NULL,
  location_captured_at DATETIME NULL,
  sync CHAR(1) NOT NULL DEFAULT '1',
  voided CHAR(1) NOT NULL DEFAULT '1',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  created_by VARCHAR(50) NOT NULL,
  UNIQUE KEY uq_visita_firmas_visita (visita_id),
  CONSTRAINT fk_visita_firmas_visita FOREIGN KEY (visita_id)
    REFERENCES visitas_tecnicas(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
