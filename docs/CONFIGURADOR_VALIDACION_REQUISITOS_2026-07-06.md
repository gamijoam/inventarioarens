# Validacion de requisitos del configurador

## Objetivo

El configurador independiente ahora incluye una revision previa para instalaciones nuevas o soporte tecnico. La idea es detectar problemas comunes antes de intentar sincronizar una empresa.

## Requisitos que valida

- Proyecto Laravel disponible: revisa que exista `artisan`.
- Archivo `.env`: revisa que exista la configuracion local.
- PHP disponible: ejecuta PHP y confirma que responde.
- Driver PostgreSQL de PHP: valida que `pdo_pgsql` este activo.
- .NET disponible: confirma que la computadora puede ejecutar las aplicaciones WPF.
- Configuracion de base local: valida `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.
- Base local PostgreSQL: ejecuta una comprobacion de migraciones para confirmar que Laravel puede conectarse.
- Servidor nube: valida que la URL de la API responda.

## Uso

1. Abrir el configurador.
2. Colocar la URL de la nube.
3. Presionar `Validar requisitos de esta PC`.
4. Revisar la lista `Requisitos`.

Cada punto aparece como:

```text
OK - nombre: detalle
FALTA - nombre: accion sugerida
```

## Resultado esperado

Si todo esta listo, el configurador muestra:

```text
Computadora lista
```

Si falta algo, muestra cuantos puntos deben corregirse antes de continuar.

## Notas

Esta validacion no modifica datos. Solo revisa el entorno local y la respuesta de la nube. La preparacion real de la empresa sigue ocurriendo al presionar `Configurar esta computadora`.
