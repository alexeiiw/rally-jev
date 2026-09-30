# Continuidad del desarrollo

## Versión y estado del proveedor Jev

- Versión actual documentada: **0.1.0** (prototipo funcional).
- Jev externo: **pendiente**. Estamos a la espera de acceso y documentación oficial vigente para analizar cómo funciona e implementar la integración.
- Mientras tanto se usa `MockJevService`, un conjunto de reglas internas para probar decisiones y consecuencias; no es el servicio Jev real.
- Siguiente paso Jev cuando tengamos acceso: verificar documentación/capacidades y autenticación, acordar el contrato de entrada/salida, implementar el adaptador y probar errores/fallback sin exponer secretos.

## Estado

- Ciclo completado: implementación inicial Laravel + persistencia local JSON + interfaz Canvas.
- Base Laravel mínima preparada para instalar dependencias con Composer en Codespaces.
- Catálogos base: Sierra de la Mina (12 sectores), JEV-01 y RALLY-X1.
- API de carrera: catálogo, crear, leer, historial, start, tick, pause/resume y restart.
- Motor: tick de cinco segundos, semilla reproducible, Mock Jev, desgaste, incidentes y finalización/abandono.
- Proveedor de decisión desacoplado por contrato; integración oficial explícitamente pendiente de documentación.
- Pruebas unitarias iniciales para reproducción determinista, decisiones y resultado final.
- Frontend: mapa Canvas esquemático, telemetría, decisión, clasificación, eventos, controles e historial.
- Panel de resultado derivado de carrera: tiempo, posición, daño, decisiones, ataques e incidentes.
- `install.sh` crea `.env` si falta, instala dependencias PHP e inicializa archivos JSON únicamente si faltan.
- Verificación pendiente: el entorno de autoría no dispone de PHP ni Composer. `install.sh` instala dependencias y ejecuta PHPUnit dentro de Codespaces.
- Codespaces inició en Recovery; se simplificó `.devcontainer` a la imagen oficial PHP 8.3 sin Features adicionales, y ahora ejecuta directamente `bash install.sh`. El proyecto no requiere Node.
- Se agregó `install.sh` en la raíz como comando manual único de instalación/bootstrap para Codespaces.

## Próximo ciclo sugerido

1. Reconstruir el Codespace desde la rama `main` actualizada; el post-create ejecutará `bash install.sh`.
2. Arrancar con `php artisan serve --host=0.0.0.0 --port=8000`.
3. Probar crear, iniciar, pausar/reanudar, terminar, reiniciar y consultar historial.
4. Añadir pruebas PHPUnit de semilla, progresión, fallback, eventos y finalización tras validar la base real.

## Regla de continuación

Al terminar cada nuevo ciclo, actualizar este archivo con archivos tocados, pruebas ejecutadas y el siguiente paso concreto. Leer también `docs/ARQUITECTURA.md` antes de cambiar persistencia o simulación.
