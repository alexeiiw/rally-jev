# JEV Rally — arquitectura v1

**Versión del proyecto: 0.3.0.** Jev real está pendiente de acceso y documentación oficial; el proveedor activo para desarrollo es `MockJevService`.

## Decisiones

- Laravel 11/12, PHP 8.2+ y una aplicación web servida por Laravel en GitHub Codespaces.
- `.devcontainer/devcontainer.json` define el entorno Codespaces requerido. No se mantiene configuración alternativa de Docker local.
- El frontend es JavaScript nativo del navegador; no requiere Node, npm ni un paso de compilación.
- Sin base de datos: catálogos y carreras se guardan en `storage/app/data/` como JSON y JSONL.
- El estado persistido del servidor es la verdad oficial; JavaScript dibuja y anima, nunca decide resultados.
- El proveedor inicial es `MockJevService`; las acciones son discretas y el proveedor real deberá implementarse únicamente después de verificar documentación oficial vigente.
- Cada carrera admite dos entradas con pilotos distintos y autos seleccionables de forma independiente. Cada entrada mantiene RNG, estado, decisiones, eventos, desgaste, tiempo y resultado propios; el recorrido/etapa y sus condiciones son compartidos.
- Estado de Jev: en espera de acceso y documentación oficial para evaluar cómo interactuar con el servicio e integrar su decisión estratégica. `JevService` es solo un punto de extensión y todavía no llama a una API real.
- Los ticks de simulación avanzan cinco segundos simulados y el navegador los solicita mediante polling.
- La semilla, nombre/versión del generador RNG y estado del generador de cada carrera se conservan para que la variación del motor sea reproducible.
- El Codespace contiene las partidas. Si se elimina el Codespace, se eliminan sus archivos locales de datos; recrear uno desde Git comienza con catálogos base vacíos de partidas.
- `install.sh` es idempotente y no sobrescribe partidas ni catálogos ya inicializados.

## Carpetas importantes

```text
app/Services/JsonStore.php             persistencia atómica y bloqueo de archivos
app/Services/RaceSimulationService.php simulación, acciones, desgaste, eventos y finalización
app/Services/MockJevService.php        piloto de desarrollo determinista
app/Http/Controllers/RaceController.php API del juego
resources/views/rally.blade.php        vista principal
public/js/rally.js                     Canvas, polling e interfaz
public/css/rally.css                   estética responsive de competición
storage/app/data/catalog/*.json        catálogos modificables
storage/app/data/races/*.json          estado actual e historial por carrera
storage/app/data/races/*.events.jsonl  registro cronológico de eventos
```

## Flujo de una carrera

1. Selección de la etapa compartida, dos pilotos distintos y un vehículo para cada piloto; la carrera recibe semilla común con RNG independiente por entrada.
2. Inicio: ambos estados pasan a `running` y cada participante obtiene decisiones usando su contexto, personalidad y auto.
3. Cada tick procesa los estados de ambos participantes por separado. Recorrido, sectores y clima son compartidos; decisión, tiempo, distancia, RNG, desgaste, daños, eventos y terminalidad son individuales.
4. Si un piloto llega a meta o abandona, su estado queda terminal y deja de avanzar; el rival continúa. La clasificación coloca finalistas por menor tiempo, finalistas antes que abandonos y, si ambos abandonan, compara la distancia completada.
5. Estado, decisiones, eventos y clasificación se persisten en JSON. Un fallo del proveedor usa fallback por participante.

## Formatos

- Cada carrera mantiene en `race-{uuid}.json` estado, participantes, decisiones, eventos y resultado; la carrera es de tamaño modesto y mantenerla en un documento permite escritura atómica.
- `index.json` es una lista pequeña de resúmenes para el historial, actualizada bajo bloqueo.
- Los eventos se agregan a un JSONL independiente. Cada línea es un objeto JSON completo.
- Se escriben JSON temporales y se renombran al destino. Los locks de índice y carrera serializan transacciones del documento; los locks se adquieren consistentemente para reducir corrupción y actualizaciones perdidas.

## Limitaciones deliberadas v1

La pantalla representa ambos autos en la pista esquemática y muestra telemetría del participante activo. No hay multiusuario ni sincronización entre Codespaces. JSON local sirve para instancia única y uso personal. No guardar archivos de carreras en Git. La eliminación del Codespace elimina las partidas.
