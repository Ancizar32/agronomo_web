-- 033 · Un plan puede cubrir todo un cultivo de la finca o un lote puntual.

ALTER TABLE planes_cosecha DROP FOREIGN KEY fk_plan_cosecha_lote;
ALTER TABLE planes_cosecha MODIFY lote_id VARCHAR(50) NULL;
ALTER TABLE planes_cosecha
    ADD CONSTRAINT fk_plan_cosecha_lote FOREIGN KEY (lote_id)
    REFERENCES lotes(id) ON UPDATE CASCADE;
