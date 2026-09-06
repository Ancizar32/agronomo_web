DELETE rp FROM rol_permisos rp
JOIN permisos p ON p.id=rp.permiso_id
WHERE p.modulo='cosechas';

DELETE FROM permisos WHERE modulo='cosechas';

DROP TABLE IF EXISTS cosechas_reales;
DROP TABLE IF EXISTS cosechas_proyectadas;
DROP TABLE IF EXISTS planes_cosecha;
