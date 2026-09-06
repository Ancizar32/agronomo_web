-- Solo puede revertirse cuando no existan planes generales sin lote.
ALTER TABLE planes_cosecha DROP FOREIGN KEY fk_plan_cosecha_lote;
ALTER TABLE planes_cosecha MODIFY lote_id VARCHAR(50) NOT NULL;
ALTER TABLE planes_cosecha
    ADD CONSTRAINT fk_plan_cosecha_lote FOREIGN KEY (lote_id)
    REFERENCES lotes(id) ON UPDATE CASCADE;
