-- Los archivos de uploads/certificaciones/ quedan huérfanos; bórralos a mano si se revierte.
ALTER TABLE predio_certificaciones
    DROP COLUMN documento_actualizado,
    DROP COLUMN documento_nombre,
    DROP COLUMN documento_pdf;
