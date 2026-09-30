# Continuidad del desarrollo

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
- Setup de Codespaces crea `.env`, instala dependencias e inicializa archivos únicamente si faltan.
- Verificación pendiente: el entorno de autoría no dispone de PHP, Composer ni Node y Docker Desktop no tiene daemon activo. `.devcontainer/setup.sh` ejecutará la validación JS y las pruebas PHPUnit al crear el Codespace.
- Codespaces inició en Recovery debido a error de contenedor; se cambió a la etiqueta de imagen PHP documentada `8.3-bookworm`, se actualizó Node Feature a v2 y setup instala Composer como fallback.

## Próximo ciclo sugerido

1. Abrir el repositorio en Codespaces y dejar ejecutar el post-create setup.
2. Revisar dependencias/bootstrap y resolver cualquier error de instalación.
3. Arrancar con `php artisan serve --host=0.0.0.0 --port=8000`.
4. Probar crear, iniciar, pausar/reanudar, terminar, reiniciar y consultar historial.
5. Añadir pruebas PHPUnit de semilla, progresión, fallback, eventos y finalización tras validar la base real.

## Regla de continuación

Al terminar cada nuevo ciclo, actualizar este archivo con archivos tocados, pruebas ejecutadas y el siguiente paso concreto. Leer también `docs/ARQUITECTURA.md` antes de cambiar persistencia o simulación.
