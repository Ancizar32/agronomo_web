# Migraciones de AgroSoft Agrónomo

Los archivos de `migrations/` se ejecutan en orden numérico y una sola vez.
Antes de aplicarlos en producción se debe crear una copia de seguridad.

```bash
mysql -u USUARIO -p BASE_DATOS < database/migrations/001_usuarios_roles_permisos.sql
```

Para habilitar los desplegables encadenados del registro de predios, aplica
`007_division_territorial.sql` después de las migraciones `001` a `006`. Esta
migración crea la jerarquía Departamento → Municipio → Vereda/Corregimiento y
precarga los departamentos. Los municipios y las localidades rurales deben
importarse con su código oficial antes de usar el formulario en producción.
La migración `008_municipios_divipola.sql` carga los 1.122 municipios del
catálogo DIVIPOLA del DANE (MGN 2025).
La migración `009_veredas_dane_2024.sql` carga el nivel nacional de referencia
de veredas del DANE. El archivo puede regenerarse desde la fuente oficial con
`php database/tools/generate_veredas_dane.php`.
La migración `010_permisos_modulos_agronomo.sql` incorpora a la matriz de
seguridad los módulos territoriales, productivos y técnicos de Agrónomo.
La migración `011_usuarios_fincas.sql` permite asignar varias fincas o predios
a cada usuario y migra las asignaciones existentes de `fincas.tecnico_id`.
La migración `012_integridad_insumos_formulas.sql` agrega índices y relaciones
para proteger la composición de fórmulas utilizada por web y móvil.

Los archivos de `rollbacks/` son ayudas para revertir una fase durante el
desarrollo. Pueden eliminar datos creados por el módulo y no sustituyen una
copia de seguridad.

## Convenciones

- Una modificación de esquema corresponde a un archivo nuevo; no se edita una
  migración que ya haya sido aplicada.
- Los nombres empiezan con un consecutivo de tres dígitos.
- Las migraciones deben ser idempotentes siempre que MySQL lo permita.
- La tabla heredada `user` se conserva por compatibilidad con la app móvil.
