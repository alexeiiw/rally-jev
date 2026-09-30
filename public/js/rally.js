(() => {
  const $ = (selector) => document.querySelector(selector);
  const api = async (url, method = 'GET', body = null) => {
    const response = await fetch(url, {
      method,
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'No se pudo completar la solicitud.');
    return data;
  };

  const canvas = $('#track-canvas');
  const ctx = canvas.getContext('2d');
  let race = null;
  let stage = null;
  let catalog = null;
  let pollTimer = null;
  let pollInFlight = false;
  let animationFrame = null;
  let visualProgress = 0;
  let targetProgress = 0;
  let toastTimer = null;

  const showToast = (message) => {
    const toast = $('#toast');
    toast.textContent = message;
    toast.classList.add('visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('visible'), 3200);
  };

  function trackPoints(width, height) {
    const padX = Math.max(65, width * 0.13);
    const padY = Math.max(42, height * 0.14);
    const points = [];
    for (let i = 0; i <= 160; i++) {
      const t = i / 160;
      const x = padX + t * (width - padX * 2);
      const waves = Math.sin(t * Math.PI * 5) * height * 0.18 + Math.sin(t * Math.PI * 10 + 0.8) * height * 0.065;
      const y = height / 2 + waves;
      points.push({ x, y });
    }
    return points;
  }

  function drawTrack() {
    const rect = canvas.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(rect.width * dpr);
    canvas.height = Math.round(rect.height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const w = rect.width;
    const h = rect.height;
    ctx.clearRect(0, 0, w, h);

    ctx.fillStyle = '#171b17';
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = 'rgba(179,194,169,.055)';
    ctx.lineWidth = 1;
    for (let x = 0; x < w; x += 30) { ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke(); }
    for (let y = 0; y < h; y += 30) { ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke(); }

    const points = trackPoints(w, h);
    const drawPath = () => {
      ctx.beginPath();
      points.forEach((point, index) => index === 0 ? ctx.moveTo(point.x, point.y) : ctx.lineTo(point.x, point.y));
    };
    drawPath(); ctx.strokeStyle = '#090b09'; ctx.lineWidth = 31; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.stroke();
    drawPath(); ctx.strokeStyle = '#555950'; ctx.lineWidth = 25; ctx.stroke();
    drawPath(); ctx.setLineDash([3, 8]); ctx.strokeStyle = 'rgba(220,223,207,.2)'; ctx.lineWidth = 1; ctx.stroke(); ctx.setLineDash([]);

    const sectors = stage?.sectors || [];
    sectors.forEach((sector, index) => {
      const point = points[Math.round((index / sectors.length) * (points.length - 1))];
      ctx.beginPath(); ctx.arc(point.x, point.y, 3.1, 0, Math.PI * 2);
      ctx.fillStyle = index + 1 === (race?.states?.[0]?.sector_number || 1) ? '#ccff70' : '#8a9083'; ctx.fill();
      ctx.font = '9px "DM Mono", monospace'; ctx.fillStyle = 'rgba(205,212,193,.65)';
      ctx.fillText(String(index + 1).padStart(2, '0'), point.x + 7, point.y - 8);
    });

    const start = points[0];
    const finish = points[points.length - 1];
    [[start, 'S'], [finish, 'F']].forEach(([point, label]) => {
      ctx.fillStyle = '#cbff70'; ctx.fillRect(point.x - 4, point.y - 11, 8, 22);
      ctx.fillStyle = '#121511'; ctx.font = 'bold 8px "DM Mono", monospace'; ctx.fillText(label, point.x - 2.5, point.y + 3);
    });

    const displayProgress = race ? race.states.map((state) => state.progress / 100) : [visualProgress, visualProgress];
    displayProgress.forEach((progress, index) => {
      const pointIndex = Math.min(points.length - 2, Math.round(progress * (points.length - 1)));
      const p = points[pointIndex];
      const color = index === 0 ? '#caff70' : '#66d9ff';
      ctx.beginPath(); ctx.arc(p.x, p.y, 13, 0, Math.PI * 2);
      ctx.fillStyle = index === 0 ? 'rgba(202,255,112,.13)' : 'rgba(102,217,255,.13)'; ctx.fill();
      ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(Math.atan2(points[pointIndex + 1].y - p.y, points[pointIndex + 1].x - p.x));
      ctx.fillStyle = color; ctx.beginPath(); ctx.moveTo(9, 0); ctx.lineTo(-6, -5); ctx.lineTo(-4, 0); ctx.lineTo(-6, 5); ctx.closePath(); ctx.fill();
      ctx.fillStyle = '#f5f7ef'; ctx.fillRect(-2, -2, 6, 4); ctx.restore();
    });
  }

  function animate() {
    visualProgress += (targetProgress - visualProgress) * 0.045;
    if (Math.abs(targetProgress - visualProgress) < 0.0005) visualProgress = targetProgress;
    drawTrack();
    animationFrame = requestAnimationFrame(animate);
  }

  function setBar(id, value, inverted = false) {
    const el = $(id);
    el.style.width = `${Math.max(0, Math.min(100, value))}%`;
    el.classList.toggle('low', inverted ? value > 65 : value < 35);
  }

  function render(r) {
    race = r;
    stage = r.stage;
    if ($('#stage-select').options.length) $('#stage-select').value = r.stage.id;
    r.entries.forEach((entry, index) => {
      const suffix = index === 0 ? 'one' : 'two';
      $(`#driver-${suffix}-select`).value = entry.driver.id;
      $(`#vehicle-${suffix}-select`).value = entry.vehicle.id;
    });
    const activeIndex = r.states.findIndex((entryState) => entryState.status === 'running');
    const selectedIndex = activeIndex >= 0 ? activeIndex : 0;
    const state = r.states[selectedIndex];
    const entry = r.entries[selectedIndex];
    targetProgress = state.progress / 100;
    $('#stage-name').innerHTML = `${escapeHtml(stage.name.split(' ')[0])} <span>${escapeHtml(stage.name.split(' ').slice(1).join(' ').toUpperCase())}</span>`;
    $('#weather-label').innerHTML = `<b class="weather-icon">${stage.default_weather === 'lluvia' ? '☂' : '◌'}</b> ${escapeHtml(stage.default_weather.toUpperCase())}`;
    $('#surface-label').textContent = [...new Set(stage.sectors.map((s) => s.surface.toUpperCase()))].join(' / ');
    $('#distance-label').textContent = `${stage.distance_km.toFixed(1)} KM`;
    $('#sector-total').textContent = `${stage.sectors.length} SECTORES`;
    $('#map-sector-count').textContent = `${stage.sectors.length} sectores`;
    $('#distance-total').textContent = stage.distance_km.toFixed(1);
    $('#map-km').textContent = state.distance_km.toFixed(2);
    $('#distance-current').textContent = state.distance_km.toFixed(2);
    $('#progress-fill').style.width = `${state.progress}%`;
    $('#progress-marker').style.left = `${state.progress}%`;
    $('#speed-value').textContent = state.speed_kmh ? Math.round(state.speed_kmh) : '—';
    $('#speed-bar').style.width = `${Math.min(100, state.speed_kmh / 2.1)}%`;
    const [minutes, seconds] = state.time_display.split(':');
    $('#time-value').innerHTML = `${minutes}:${seconds.slice(0, 2)}<span>.${seconds.slice(3)}</span>`;
    $('#map-sector').textContent = `SECTOR ${String(state.sector_number).padStart(2, '0')}`;
    $('#sector-type').textContent = `SECTOR ${String(state.sector_number).padStart(2, '0')} · ${state.sector?.type?.toUpperCase() || ''}`;
    $('#curve-name').textContent = state.sector?.critical_curve || '—';
    $('#curve-distance').textContent = Number(state.next_curve_distance).toLocaleString('es');
    $('#curve-risk').textContent = `RIESGO ${state.sector?.risk ?? '—'}`;
    $('#action-chip').textContent = state.current_action.replaceAll('_', ' ');
    $('#vehicle-name').textContent = `${entry.vehicle.name} · ${entry.driver.name}`;
    setBar('#tires-bar', state.tires); $('#tires-label').textContent = `${Math.round(state.tires)}%`;
    setBar('#engine-bar', state.engine); $('#engine-label').textContent = `${Math.round(state.engine)}%`;
    setBar('#brakes-bar', state.brakes); $('#brakes-label').textContent = `${Math.round(state.brakes)}%`;
    setBar('#damage-bar', state.damage, true); $('#damage-label').textContent = `${Math.round(state.damage)}%`;
    $('#decision-action').textContent = (state.current_action || 'LISTO').replaceAll('_', ' ');
    $('#decision-icon').textContent = state.current_action === 'ATACAR' ? '↗' : state.current_action === 'CONSERVAR' ? '⌁' : state.current_action === 'FRENAR_ANTES' ? '↓' : state.current_action === 'TOMAR_INTERIOR' ? '↰' : state.current_action === 'TOMAR_EXTERIOR' ? '↱' : '→';
    $('#decision-reason').textContent = state.decision_reason || 'Estrategia de carrera';
    $('#decision-sector').textContent = `S${String(state.sector_number).padStart(2, '0')}`;
    renderOptions(r.decisions.filter((decision) => decision.entry_id === entry.id));
    renderClassification(r);
    renderEvents(r.events);
    $('#race-status').textContent = r.result?.winner_entry_id ? `${r.result.classification.find((item) => item.entry_id === r.result.winner_entry_id)?.driver_name} GANA` : statusLabel(r.status);
    $('#session-label').textContent = `DOS PILOTOS · ${r.status === 'running' ? 'EN VIVO' : statusLabel(r.status)}`;
    $('#race-short-id').textContent = r.id.slice(0, 8).toUpperCase();
    $('#start-button').disabled = ['running', 'paused'].includes(r.status);
    $('#start-button').innerHTML = ['finished', 'abandoned'].includes(r.status) ? '<span class="button-icon">↻</span> NUEVA COMPETENCIA <span class="button-arrow">↗</span>' : '<span class="button-icon">▶</span> INICIAR COMPETENCIA <span class="button-arrow">↗</span>';
    $('#pause-button').disabled = !['running', 'paused', 'created'].includes(r.status);
    $('#pause-button').innerHTML = r.status === 'paused' ? '▶ &nbsp; CONTINUAR' : 'Ⅱ &nbsp; PAUSAR';
    $('#restart-button').disabled = ['running', 'created'].includes(r.status);
    if (r.result) renderFinish(r.result);
    else $('#result-panel').hidden = true;
    drawTrack();
  }

  function renderOptions(decisions) {
    const decision = decisions.at(-1);
    if (!decision) {
      $('#option-list').innerHTML = '<div class="empty-options">Las decisiones del piloto aparecerán aquí.</div>';
      return;
    }
    const options = decision.probabilities && Object.keys(decision.probabilities).length
      ? Object.entries(decision.probabilities).map(([name, value]) => ({ name, value: Number(value) }))
      : [{ name: decision.selected_action, value: null }];
    $('#option-list').innerHTML = options.map((item) => `<div class="option-row ${item.name === decision.selected_action ? 'selected' : ''}"><span class="option-name"><i></i>${escapeHtml(item.name.replaceAll('_', ' '))}</span>${item.value === null ? '<span class="option-tag">SELECCIONADA</span>' : `<span class="option-score">${item.value}%</span>`}</div>`).join('');
    $('#decision-sector').textContent = `S${String(decision.sector).padStart(2, '0')} · TICK ${decision.tick}`;
  }

  function renderClassification(r) {
    $('#classification-list').innerHTML = r.entries.map((entry, index) => {
      const state = r.states[index];
      const finished = state.status === 'finished';
      const abandoned = state.status === 'abandoned';
      const time = finished ? state.time_display : abandoned ? `DNF · ${(state.distance_m / 1000).toFixed(2)} KM` : 'EN CARRERA';
      const vehicleClass = entry.vehicle.class || 'Competición';
      return `<div class="class-row ${entry.position === 1 ? 'leader' : ''} ${abandoned ? 'abandoned' : ''}"><span class="class-driver"><b>${String(entry.position).padStart(2, '0')}</b><span class="driver-swatch swatch-${index + 1}"></span><strong>${escapeHtml(entry.driver.name)}</strong><em>${escapeHtml(entry.vehicle.name)}</em></span><span class="class-time">${abandoned ? 'DNF' : time}</span><small class="class-meta">${escapeHtml(entry.driver.age_group || '')} · ${escapeHtml(vehicleClass)} · ${Math.round(state.progress)}%${state.current_action ? ` · ${escapeHtml(state.current_action.replaceAll('_', ' '))}` : ''}</small></div>`;
    }).join('');
  }

  function renderEvents(events) {
    $('#event-count').textContent = events.length;
    if (!events.length) {
      $('#event-list').innerHTML = '<div class="event-empty"><span>—</span> Los eventos de carrera aparecerán aquí.</div>';
      return;
    }
    $('#event-list').innerHTML = events.slice(-8).reverse().map((event) => `<div class="event-row ${event.type === 'derrape' || event.type === 'golpe' ? 'warning' : ''}"><span class="event-time">T${String(event.tick || 0).padStart(3, '0')}</span><span class="event-symbol">${event.type === 'finish' ? '✓' : event.type === 'abandonment' ? '!' : '⚠'}</span><span class="event-description">${event.driver_name ? `<b>${escapeHtml(event.driver_name)}</b> · ` : ''}${escapeHtml(event.label)}</span><span class="event-sector">S${String(event.sector || 1).padStart(2, '0')}</span>${event.time_lost ? `<span class="event-loss">+${event.time_lost.toFixed(1)}s</span>` : ''}</div>`).join('');
  }

  function renderFinish(result) {
    $('#result-panel').hidden = false;
    $('#result-title').textContent = result.winner_entry_id ? `${result.classification.find((item) => item.entry_id === result.winner_entry_id)?.driver_name} GANA LA ETAPA` : 'ETAPA COMPLETADA · SIN FINALISTAS';
    $('#result-stats').innerHTML = result.classification.map((entry) => `<div class="result-driver"><strong>${escapeHtml(entry.driver_name)}</strong><span>${escapeHtml(entry.vehicle_name)}</span><b>${entry.status === 'finished' ? entry.time_display : 'ABANDONO'}</b><span>P${String(entry.position).padStart(2, '0')} · ${entry.decision_count} DECISIONES · ${entry.attacks} ATAQUES</span><span>AVANCE ${((entry.distance_m || 0) / 1000).toFixed(2)} KM · DAÑO ${Math.round(entry.damage)}% · ${entry.incidents} INCIDENTES</span></div>`).join('');
    if (race._finishShown) return;
    race._finishShown = true;
    showToast(result.winner_entry_id ? `${result.classification.find((item) => item.entry_id === result.winner_entry_id)?.driver_name} gana la etapa` : 'La carrera terminó sin un finalista.');
  }

  function statusLabel(status) {
    return ({ created: 'LISTA PARA SALIDA', running: 'EN CARRERA', paused: 'PAUSADA', finished: 'ETAPA COMPLETADA', abandoned: 'ABANDONO' })[status] || status.toUpperCase();
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
  }

  async function poll() {
    if (!race || !['running', 'created'].includes(race.status) || pollInFlight) return;
    pollInFlight = true;
    try {
      const data = await api(`/api/races/${race.id}/tick`, 'POST');
      render(data.race);
    } catch (error) {
      showToast(error.message);
    } finally {
      pollInFlight = false;
    if (race?.status === 'running') pollTimer = setTimeout(poll, 1200);
    }
  }

  async function createRace() {
    catalog ||= await api('/api/catalog');
    const data = await api('/api/races', 'POST', {
      participants: [
        { driver_id: $('#driver-one-select').value || catalog.drivers[0].id, vehicle_id: $('#vehicle-one-select').value || catalog.vehicles[0].id },
        { driver_id: $('#driver-two-select').value || catalog.drivers[1].id, vehicle_id: $('#vehicle-two-select').value || catalog.vehicles[2].id },
      ],
      stage_id: $('#stage-select').value || catalog.stages[0].id,
    });
    render(data.race);
  }

  function populateSelect(select, items, label) {
    select.innerHTML = items.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(label(item))}</option>`).join('');
  }

  async function initialize() {
    catalog = await api('/api/catalog');
    populateSelect($('#stage-select'), catalog.stages, (item) => item.name);
    const drivers = catalog.drivers.filter((item) => item.age_group);
    const vehicles = catalog.vehicles.filter((item) => item.class);
    populateSelect($('#driver-one-select'), drivers, (item) => `${item.name} · ${item.age_group}`);
    populateSelect($('#driver-two-select'), drivers, (item) => `${item.name} · ${item.age_group}`);
    populateSelect($('#vehicle-one-select'), vehicles, (item) => `${item.name} · ${item.class}`);
    populateSelect($('#vehicle-two-select'), vehicles, (item) => `${item.name} · ${item.class}`);
    if (drivers.length >= 2) $('#driver-two-select').value = drivers[1].id;
    if (vehicles.length >= 3) $('#vehicle-two-select').value = vehicles[2].id;
    const history = await api('/api/races');
    const resumable = history.races.find((item) => ['running', 'paused'].includes(item.status));
    if (resumable) {
      const saved = await api(`/api/races/${resumable.id}`);
      render(saved.race);
      if (saved.race.status === 'created') {
        const started = await api(`/api/races/${saved.race.id}/start`, 'POST');
        render(started.race);
      }
      if (saved.race.status === 'running' || saved.race.status === 'created') pollTimer = setTimeout(poll, 800);
    } else if (history.races[0]?.status === 'finished' || history.races[0]?.status === 'abandoned') {
      const saved = await api(`/api/races/${history.races[0].id}`);
      render(saved.race);
    }
  }

  ['stage-select', 'driver-one-select', 'driver-two-select', 'vehicle-one-select', 'vehicle-two-select'].forEach((id) => {
    $(`#${id}`).addEventListener('change', async () => {
      if (!race || ['created', 'finished', 'abandoned'].includes(race.status)) {
        clearTimeout(pollTimer);
        race = null;
        $('#race-status').textContent = 'CONFIGURACIÓN ACTUALIZADA';
      } else {
        showToast('La competencia está activa; espera a su finalización para cambiar participantes.');
        $('#stage-select').value = race.stage.id;
        $('#driver-one-select').value = race.entries[0].driver.id;
        $('#vehicle-one-select').value = race.entries[0].vehicle.id;
        $('#driver-two-select').value = race.entries[1].driver.id;
        $('#vehicle-two-select').value = race.entries[1].vehicle.id;
      }
    });
  });

  $('#start-button').addEventListener('click', async () => {
    try {
      if (!race || ['finished', 'abandoned'].includes(race.status)) {
        await createRace();
      }
      const data = await api(`/api/races/${race.id}/start`, 'POST');
      render(data.race);
      await poll();
    } catch (error) { showToast(error.message); }
  });

  $('#pause-button').addEventListener('click', async () => {
    try {
      const endpoint = race.status === 'paused' ? 'resume' : 'pause';
      const data = await api(`/api/races/${race.id}/${endpoint}`, 'POST');
      render(data.race);
      clearTimeout(pollTimer);
      if (data.race.status === 'running') pollTimer = setTimeout(poll, 1200);
    } catch (error) { showToast(error.message); }
  });

  $('#restart-button').addEventListener('click', async () => {
    try {
      clearTimeout(pollTimer);
      const data = await api(`/api/races/${race.id}/restart`, 'POST');
      $('#result-panel').hidden = true;
      render(data.race);
      showToast('Carrera reiniciada.');
    } catch (error) { showToast(error.message); }
  });

  async function showHistory() {
    try {
      const data = await api('/api/races');
      const list = $('#history-list');
    list.innerHTML = data.races.length ? data.races.map((item) => `<button class="history-row" data-race="${escapeHtml(item.id)}"><span class="history-status ${item.status}">${escapeHtml(statusLabel(item.status))}</span><strong>${escapeHtml(item.stage_name)} · ${item.participant_count || 1} competidores</strong><span>${escapeHtml(item.driver_name)} · ${escapeHtml(item.vehicle_name)}${item.winner_name ? ` · Ganador: ${escapeHtml(item.winner_name)}` : ''}</span><b>${formatTime(item.elapsed_seconds)}</b></button>`).join('') : '<div class="event-empty">Todavía no hay carreras guardadas en este Codespace.</div>';
      list.querySelectorAll('[data-race]').forEach((button) => button.addEventListener('click', async () => {
        try { const raceData = await api(`/api/races/${button.dataset.race}`); clearTimeout(pollTimer); render(raceData.race); if (['running', 'created'].includes(raceData.race.status)) pollTimer = setTimeout(poll, 1000); $('#history-modal').hidden = true; }
        catch (error) { showToast(error.message); }
      }));
      $('#history-modal').hidden = false;
    } catch (error) { showToast(error.message); }
  }

  function formatTime(seconds) {
    const m = Math.floor(seconds / 60).toString().padStart(2, '0');
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    const cs = Math.floor((seconds % 1) * 100).toString().padStart(2, '0');
    return `${m}:${s}.${cs}`;
  }

  $('#history-button').addEventListener('click', showHistory);
  $('#close-history').addEventListener('click', () => { $('#history-modal').hidden = true; });
  $('#history-modal').addEventListener('click', (event) => { if (event.target.id === 'history-modal') event.currentTarget.hidden = true; });
  window.addEventListener('resize', drawTrack);
  if ('ResizeObserver' in window) new ResizeObserver(drawTrack).observe($('.track-wrap'));
  animationFrame = requestAnimationFrame(animate);
  initialize().catch((error) => showToast(error.message));
})();
