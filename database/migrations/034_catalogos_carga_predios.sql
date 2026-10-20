-- 034 · Valores requeridos por la carga inicial de predios del cliente
-- AGROJAR es un tipo de productor propio del cliente; PODA y DESCANSO son
-- estados fenológicos reportados por los técnicos. Solo se amplían los ENUM,
-- por lo que los registros existentes no cambian y la migración es repetible.

ALTER TABLE productores
    MODIFY COLUMN tipo ENUM('TERCERO','SOCIO','JOIN VENTURE','AGROJAR') NOT NULL;

ALTER TABLE lotes
    MODIFY COLUMN estado_fenologico ENUM('SIEMBRA','VEGETATIVO','PRODUCCION','PODA','DESCANSO') NULL;
