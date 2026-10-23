-- 035 · Documento PDF de soporte por certificación del predio.
-- El archivo vive en uploads/certificaciones/ y aquí solo se guarda la ruta.
-- savePredioCompletoWeb recrea las filas de predio_certificaciones en cada
-- guardado: conserva estas columnas por (finca_id, tipo) y elimina el archivo
-- cuando la certificación se desmarca.
ALTER TABLE predio_certificaciones
    ADD COLUMN documento_pdf VARCHAR(255) NULL AFTER observacion,
    ADD COLUMN documento_nombre VARCHAR(255) NULL AFTER documento_pdf,
    ADD COLUMN documento_actualizado DATETIME NULL AFTER documento_nombre;
