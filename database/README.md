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
La migración `028_catalogo_certificaciones.sql` convierte las certificaciones
fijas del predio en un catálogo administrable y conserva los registros previos.
La migración `034_catalogos_carga_predios.sql` agrega el tipo de productor
AGROJAR y los estados fenológicos PODA y DESCANSO que usa el cliente.
La migración `035_certificacion_documento_pdf.sql` permite adjuntar un PDF de
soporte a cada certificación del predio. Los archivos se guardan en
`uploads/certificaciones/`, que debe existir con permisos de escritura para PHP.

## Carga inicial desde el Excel del cliente

`tools/importar_info_agronomo.py` convierte el Excel "Info Agrónomo" en un SQL
repetible y en un reporte de lo que requiere seguimiento (necesita `openpyxl`):

```bash
python3 database/tools/importar_info_agronomo.py ARCHIVO.xlsx resources/carga
mysql -u USUARIO -p BASE_DATOS < database/migrations/034_catalogos_carga_predios.sql
mysql -u USUARIO -p --default-character-set=utf8mb4 BASE_DATOS < resources/carga/carga_info_agronomo.sql
```

Las consultas al final del SQL listan los técnicos sin usuario y los predios
cuya vereda no está en el catálogo DANE.

Después, `tools/crear_tecnicos_info_agronomo.py` crea los usuarios de los
técnicos con contraseña provisional y les asigna sus predios (necesita además
`bcrypt`). Las contraseñas quedan en `tecnicos_credenciales.md`, que no debe
versionarse:

```bash
python3 database/tools/crear_tecnicos_info_agronomo.py ARCHIVO.xlsx resources/carga
mysql -u USUARIO -p --default-character-set=utf8mb4 BASE_DATOS < resources/carga/tecnicos_info_agronomo.sql
```

Por último, `tools/crear_planes_cosecha_info_agronomo.py` crea un plan ACTIVO
por lote con proyección. El Excel no trae fechas: la cosecha n se estima en
inicio + 3·n meses. Volver a ejecutarlo no modifica planes ya existentes:

```bash
python3 database/tools/crear_planes_cosecha_info_agronomo.py ARCHIVO.xlsx resources/carga 2026-10-07
mysql -u USUARIO -p --default-character-set=utf8mb4 BASE_DATOS < resources/carga/planes_cosecha_info_agronomo.sql
```

Los archivos de `rollbacks/` son ayudas para revertir una fase durante el
desarrollo. Pueden eliminar datos creados por el módulo y no sustituyen una
copia de seguridad.

## Convenciones

- Una modificación de esquema corresponde a un archivo nuevo; no se edita una
  migración que ya haya sido aplicada.
- Los nombres empiezan con un consecutivo de tres dígitos.
- Las migraciones deben ser idempotentes siempre que MySQL lo permita.
- La tabla heredada `user` se conserva por compatibilidad con la app móvil.
