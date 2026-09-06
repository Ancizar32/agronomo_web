-- 032 · Planeación y ejecución de cosechas

CREATE TABLE IF NOT EXISTS planes_cosecha (
    id VARCHAR(50) NOT NULL,
    finca_id VARCHAR(50) NOT NULL,
    lote_id VARCHAR(50) NOT NULL,
    cultivo_id VARCHAR(50) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    fecha_inicio DATE NOT NULL,
    plantas_productivas INT UNSIGNED NOT NULL DEFAULT 0,
    estado VARCHAR(20) NOT NULL DEFAULT 'BORRADOR',
    observacion TEXT NULL,
    voided CHAR(1) NOT NULL DEFAULT '1',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    created_by VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_planes_cosecha_finca (finca_id, voided),
    KEY idx_planes_cosecha_lote (lote_id, estado, voided),
    CONSTRAINT fk_plan_cosecha_finca FOREIGN KEY (finca_id) REFERENCES fincas(id) ON UPDATE CASCADE,
    CONSTRAINT fk_plan_cosecha_lote FOREIGN KEY (lote_id) REFERENCES lotes(id) ON UPDATE CASCADE,
    CONSTRAINT fk_plan_cosecha_cultivo FOREIGN KEY (cultivo_id) REFERENCES cultivos(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cosechas_proyectadas (
    id VARCHAR(50) NOT NULL,
    plan_id VARCHAR(50) NOT NULL,
    numero INT UNSIGNED NOT NULL,
    fecha_estimada DATE NOT NULL,
    kg_planta_proyectado DECIMAL(12,4) NOT NULL DEFAULT 0,
    plantas_proyectadas INT UNSIGNED NOT NULL DEFAULT 0,
    kg_proyectado DECIMAL(14,3) NOT NULL DEFAULT 0,
    observacion VARCHAR(500) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    voided CHAR(1) NOT NULL DEFAULT '1',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    created_by VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cosecha_proyectada_numero (plan_id, numero),
    KEY idx_cosecha_proyectada_fecha (fecha_estimada, voided),
    CONSTRAINT fk_cosecha_proyectada_plan FOREIGN KEY (plan_id) REFERENCES planes_cosecha(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cosechas_reales (
    id VARCHAR(50) NOT NULL,
    cosecha_proyectada_id VARCHAR(50) NOT NULL,
    fecha DATE NOT NULL,
    kg_real DECIMAL(14,3) NOT NULL,
    plantas_cosechadas INT UNSIGNED NULL,
    observacion VARCHAR(500) NULL,
    voided CHAR(1) NOT NULL DEFAULT '1',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    created_by VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cosecha_real_proyeccion (cosecha_proyectada_id, voided),
    KEY idx_cosecha_real_fecha (fecha),
    CONSTRAINT fk_cosecha_real_proyeccion FOREIGN KEY (cosecha_proyectada_id) REFERENCES cosechas_proyectadas(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permisos(modulo,accion,descripcion) VALUES
('cosechas','ver','Consultar planes y ejecución de cosecha'),
('cosechas','crear','Crear planes de cosecha'),
('cosechas','editar','Editar planes y registrar cosechas reales')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

INSERT IGNORE INTO rol_permisos(rol_id,permiso_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permisos p
WHERE p.modulo='cosechas' AND r.activo=1;
