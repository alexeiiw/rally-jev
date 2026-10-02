<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#111411">
  <title>JEV Rally — Race Control</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/rally.css">
  <link rel="stylesheet" href="/css/rally-extra.css">
</head>
<body>
  <div class="app-shell">
    <header class="topbar">
      <a class="brand" href="/"><span class="brand-mark">J</span><span>JEV<span class="brand-light">RALLY</span> <small class="version-tag">V0.3.2 · MOCK</small></span></a>
      <div class="topbar-center"><span class="live-dot"></span><span>RACE CONTROL</span><span class="top-separator">/</span><span id="session-label">SESIÓN DE PRUEBA</span></div>
      <div class="topbar-right"><span class="environment"><i></i> MOCK JEV · 2 PILOTOS</span><button class="icon-button" id="history-button" title="Historial de carreras">◷</button></div>
    </header>

    <main>
      <section class="stage-heading">
        <div><div class="eyebrow"><span>ETAPA DE RALLY</span><span class="eyebrow-line"></span><span id="race-status">LISTA PARA SALIDA</span></div><h1 id="stage-name">SIERRA <span>DE LA MINA</span></h1><div class="stage-meta"><span id="weather-label"><b class="weather-icon">☂</b> LLUVIA</span><span class="meta-divider"></span><span id="surface-label">GRAVA / TIERRA</span><span class="meta-divider"></span><span id="distance-label">18.3 KM</span><span class="meta-divider"></span><span id="sector-total">12 SECTORES</span></div></div>
        <div class="race-number"><span>RACE</span><strong id="race-short-id">— — —</strong></div>
      </section>

      <section class="selection-strip competition-strip" aria-label="Configuración de carrera">
        <label>ETAPA <select id="stage-select"></select></label>
        <fieldset class="competitor-select"><legend>COMPETIDOR 01</legend><label>PILOTO <select id="driver-one-select"></select></label><label>AUTO <select id="vehicle-one-select"></select></label></fieldset>
        <fieldset class="competitor-select"><legend>COMPETIDOR 02</legend><label>PILOTO <select id="driver-two-select"></select></label><label>AUTO <select id="vehicle-two-select"></select></label></fieldset>
        <span>MISMA ETAPA · DOS ESTRATEGIAS</span>
      </section>

      <section class="main-grid">
        <div class="left-column">
          <section class="track-panel panel">
            <div class="panel-heading"><div><span class="panel-kicker">LIVE STAGE MAP</span><h2>Recorrido <span class="subtle">/</span> <span id="map-sector-count">12 sectores</span></h2></div><div class="map-legend"><span><i class="legend-track"></i> RUTA</span><span><i class="legend-car"></i> JOVEN / INTERMEDIO</span><span><i class="legend-car opponent"></i> RIVAL</span></div></div>
            <div class="track-wrap"><canvas id="track-canvas" aria-label="Mapa esquemático de la etapa"></canvas><div class="map-overlay top-left"><span class="overlay-label">UBICACIÓN</span><strong id="map-sector">SECTOR 01</strong></div><div class="map-overlay bottom-right"><span class="overlay-label">DISTANCIA</span><strong><span id="map-km">0.00</span><small> / 18.3 KM</small></strong></div><div class="track-compass">N <span>↑</span></div></div>
            <div class="progress-row"><div class="progress-label"><span>PROGRESO DE ETAPA</span><span><b id="distance-current">0.00</b> <i>/</i> <span id="distance-total">18.3</span> KM</span></div><div class="progress-track"><div class="progress-fill" id="progress-fill"></div><span class="progress-marker" id="progress-marker"></span></div><div class="progress-ends"><span>SALIDA</span><span>META</span></div></div>
          </section>

          <section class="telemetry-grid">
            <div class="metric-card speed-card"><div class="metric-label">VELOCIDAD <span>KM/H</span></div><div class="speed-reading"><strong id="speed-value">—</strong><span>km/h</span></div><div class="speed-bar"><i id="speed-bar"></i></div><div class="metric-foot"><span>OBJETIVO DEL MOTOR</span><span id="action-chip">ESPERANDO</span></div></div>
            <div class="metric-card time-card"><div class="metric-label">TIEMPO TRANSCURRIDO</div><div class="time-reading" id="time-value">00:00<span>.00</span></div><div class="metric-foot"><span>CRONÓMETRO OFICIAL</span><span class="timing-dot"></span></div></div>
            <div class="metric-card curve-card"><div class="metric-label">PRÓXIMA CURVA</div><div class="curve-reading" id="curve-name">—</div><div class="curve-distance"><strong id="curve-distance">—</strong><span>METROS</span></div><div class="metric-foot"><span id="sector-type">SECTOR 01</span><span id="curve-risk">RIESGO 28</span></div></div>
          </section>

          <section class="condition-panel panel"><div class="condition-title"><span class="panel-kicker">ESTADO DEL VEHÍCULO · COMPETIDOR ACTUAL</span><span class="vehicle-name" id="vehicle-name">—</span></div><div class="condition-bars"><div class="condition-item"><div><span>NEUMÁTICOS</span><b id="tires-label">100%</b></div><div class="condition-track"><i id="tires-bar"></i></div></div><div class="condition-item"><div><span>MOTOR</span><b id="engine-label">100%</b></div><div class="condition-track"><i id="engine-bar"></i></div></div><div class="condition-item"><div><span>FRENOS</span><b id="brakes-label">100%</b></div><div class="condition-track"><i id="brakes-bar"></i></div></div><div class="condition-item"><div><span>DAÑO</span><b id="damage-label">0%</b></div><div class="condition-track damage-track"><i id="damage-bar"></i></div></div></div></section>
        </div>

        <aside class="right-column">
          <section class="decision-panel panel"><div class="panel-heading"><div><span class="panel-kicker">PILOT INTELLIGENCE</span><h2>Decisión <span class="subtle">/</span> Jev</h2></div><span class="decision-pulse"></span></div><div class="decision-status"><div class="decision-icon" id="decision-icon">—</div><div><span class="overlay-label">ACCIÓN ACTUAL</span><strong id="decision-action">LISTO</strong></div></div><div class="decision-context"><span id="decision-reason">Inicia una carrera para comenzar la simulación</span><span id="decision-sector">—</span></div><div class="decision-divider"></div><div class="option-heading"><span>OPCIONES DE DECISIÓN</span><span>RESPUESTA MOCK</span></div><div class="option-list" id="option-list"><div class="empty-options">Las decisiones aparecerán durante la etapa.</div></div><div class="decision-note"><span class="note-dot"></span>Sin probabilidades ficticias. Solo se muestran scores si el proveedor los entrega.</div></section>

          <section class="classification-panel panel"><div class="panel-heading"><div><span class="panel-kicker">STAGE TIMING</span><h2>Clasificación</h2></div><span class="class-count">2 PARTICIPANTES</span></div><div class="class-header"><span>POS. / PILOTO · AUTO</span><span>TIEMPO</span></div><div id="classification-list" class="classification-list"><div class="empty-options">Selecciona los dos participantes para competir.</div></div><div class="class-gap"><span>LÍDER DE ETAPA</span><span id="class-gap">—</span></div></section>

          <section class="race-controls"><button class="primary-button" id="start-button"><span class="button-icon">▶</span> INICIAR ETAPA <span class="button-arrow">↗</span></button><div class="control-row"><button class="secondary-button" id="pause-button" disabled>Ⅱ &nbsp; PAUSAR</button><button class="secondary-button" id="restart-button" disabled>↻ &nbsp; REINICIAR</button></div><span class="control-hint">SIMULACIÓN OFICIAL EN SERVIDOR <i>·</i> TICK 5 S</span></section>
        </aside>
      </section>

      <section class="result-panel panel" id="result-panel" hidden><div class="result-heading"><span class="panel-kicker">STAGE CLASSIFICATION</span><strong id="result-title">CARRERA COMPLETADA</strong></div><div class="result-stats" id="result-stats"></div></section>
      <section class="event-log panel"><div class="event-head"><div><span class="panel-kicker">RACE TELEMETRY</span><h2>Registro de eventos</h2></div><span class="event-count"><i></i><b id="event-count">0</b> EVENTOS</span></div><div class="event-list" id="event-list"><div class="event-empty"><span>—</span> Los eventos de carrera aparecerán aquí.</div></div></section>
    </main>
    <footer><span>JEV RALLY <i>·</i> MOTOR DE SIMULACIÓN V1.0</span><span>DECIDE <b>·</b> SIMULA <b>·</b> REGISTRA</span></footer>
  </div>

  <div class="modal-backdrop" id="history-modal" hidden><div class="history-modal panel"><div class="panel-heading"><div><span class="panel-kicker">RACE ARCHIVE</span><h2>Historial de carreras</h2></div><button id="close-history" class="icon-button">×</button></div><div id="history-list" class="history-list"></div></div></div>
  <div class="toast" id="toast" role="status"></div>
  <script src="/js/rally.js" defer></script>
</body>
</html>
