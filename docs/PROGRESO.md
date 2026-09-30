# Continuidad del desarrollo

## Sesión de competencia de dos pilotos — cierre v0.3.0

- Solicitud vigente: implementar tres perfiles (joven/intermedio/mayor), tres clases de auto (bueno/medio/básico), selección de dos combinaciones y carrera simultánea en la misma etapa; persistir documentación y publicar a GitHub al terminar.
- Repositorio base sincronizado con GitHub antes de comenzar: `main` en `642ae03` (`composer.lock` ya está versionado).
- Cambios locales listos para commit/push: catálogos aditivos para tres pilotos y tres autos; request para dos pilotos distintos; motor procesa estados por participante y usa RNG por auto, personalidad y estadísticas del vehículo; UI de dos selectores, dos símbolos en mapa, clasificación e informe; persistencia registra conteo/winner; Codespaces inicia servidor en `postStartCommand`.
- Archivos tocados: `.devcontainer/devcontainer.json`, `scripts/start-server.sh` (nuevo), `scripts/start.sh`, `app/Services/JsonStore.php`, `app/Services/RaceSimulationService.php`, `app/Services/MockJevService.php`, `app/Http/Controllers/RaceController.php`, `resources/views/rally.blade.php`, `public/js/rally.js`, `public/css/rally-extra.css`, `tests/Unit/JsonStoreTest.php`, `tests/Unit/RaceSimulationServiceTest.php`, `README.md`, `docs/ARQUITECTURA.md`, `docs/PROGRESO.md`, `CHANGELOG.md`.
- Meta estabilizada: `finish_time` se calcula por participante desde distancia anterior y fracción del tick; dos cruces en el mismo tick se ordenan por ese tiempo. El finalista se congela mientras el rival continúa.
- Doble abandono: el motor ordena por distancia completada, persiste la clasificación y deja `winner_entry_id` en `null`; no se anuncia un ganador que no llegó.
- El endpoint `start` avanza únicamente una carrera `created`; repetir la solicitud sobre una carrera activa ya no añade ticks.
- Pruebas añadidas: llegada simultánea ordenada por tiempo, finalista inmóvil mientras continúa el rival y doble abandono obtenido desde el motor.
- Versión actual: **0.3.0** (competencia local robusta de dos participantes con Mock Jev). `composer.json`, interfaz, README, arquitectura y changelog están alineados.
- Verificaciones locales hechas: `git diff --check` limpio y `bash -n install.sh scripts/start.sh scripts/start-server.sh` correcto.
- Bloqueo conocido: este Windows no tiene PHP, Composer, Node ni `vendor/`. No se pudieron ejecutar `php artisan test`, `php artisan route:list` ni `node --check public/js/rally.js`; `install.sh` los ejecuta dentro de Codespaces.

## Versión y estado del proveedor Jev

- Versión actual documentada: **0.3.0** (competencia local robusta de dos participantes con Mock Jev).
- Jev externo: **pendiente**. Estamos a la espera de acceso y documentación oficial vigente para analizar cómo funciona e implementar la integración.
- Mientras tanto se usa `MockJevService`, un conjunto de reglas internas para probar decisiones y consecuencias; no es el servicio Jev real.
- Siguiente paso Jev cuando tengamos acceso: verificar documentación/capacidades y autenticación, acordar el contrato de entrada/salida, implementar el adaptador y probar errores/fallback sin exponer secretos.

## Estado

- Catálogo inicial ampliado idempotentemente: tres perfiles (joven/intermedio/mayor) y tres autos (bueno/medio/básico); catálogos existentes se conservan y solo se agregan IDs faltantes.
- Las carreras nuevas reciben dos pilotos distintos en una etapa compartida. Cada participante tiene su propio estado y flujo de decisiones; el resultado compara finalizaciones y tiempos, o progreso en caso de doble abandono.
- La inicialización de catálogos agrega solo identificadores que faltan; conserva entradas existentes, carreras y modificaciones del usuario.
- El frontend permite seleccionar independientemente los dos pilotos y autos, anima ambos vehículos, muestra clasificación en carrera e informe final comparativo.
- Pruebas de simulación actualizadas para dos entradas, RNG independiente, rechazo de piloto duplicado, finalista congelado, llegadas simultáneas y doble abandono; ejecución local pendiente porque esta máquina no tiene PHP/Composer.
- `.devcontainer` inicia el servidor Laravel como servicio de Codespaces al crear/reabrir; `scripts/start.sh` permite ejecutarlo en foreground manualmente.

- Ciclo completado: implementación inicial Laravel + persistencia local JSON + interfaz Canvas.
- Base Laravel mínima preparada para instalar dependencias con Composer en Codespaces.
- Catálogos base: Sierra de la Mina (12 sectores), JEV-01 y RALLY-X1.
- API de competencia: catálogo, crear carrera con dos participantes, leer, historial, start, tick, pause/resume y restart.
- Motor: tick de cinco segundos, semilla reproducible, Mock Jev, desgaste, incidentes y finalización/abandono.
- Proveedor de decisión desacoplado por contrato; integración oficial explícitamente pendiente de documentación.
- Pruebas unitarias iniciales para reproducción determinista, decisiones y resultado final.
- Frontend: mapa Canvas con dos autos, telemetría del participante activo, decisiones, clasificación en vivo, eventos, controles e historial.
- Panel de resultado derivado de carrera: tiempo, posición, daño, decisiones, ataques e incidentes.
- `install.sh` crea `.env` si falta, instala dependencias PHP e inicializa archivos JSON únicamente si faltan.
- Verificación pendiente: el entorno de autoría no dispone de PHP ni Composer. `install.sh` instala dependencias y ejecuta PHPUnit dentro de Codespaces.
- Codespaces inició en Recovery; se simplificó `.devcontainer` a la imagen oficial PHP 8.3 sin Features adicionales, y ahora ejecuta directamente `bash install.sh`. El proyecto no requiere Node.
- Se agregó `install.sh` en la raíz como comando manual único de instalación/bootstrap para Codespaces.

## Próximo ciclo sugerido

1. Abrir/reconstruir Codespaces desde `main`; el post-create ejecutará `bash install.sh` y el post-start iniciará Laravel.
2. Ejecutar `php artisan test`, `php artisan route:list` y `node --check public/js/rally.js` si Node está disponible.
3. Probar crear, iniciar, pausar/reanudar, terminar, reiniciar y consultar historial con dos pilotos.
4. Confirmar que finalistas y doble abandono muestran la clasificación esperada en la interfaz.

## Regla de continuación

Al terminar cada nuevo ciclo, actualizar este archivo con archivos tocados, pruebas ejecutadas y el siguiente paso concreto. Leer también `docs/ARQUITECTURA.md` antes de cambiar persistencia o simulación.
