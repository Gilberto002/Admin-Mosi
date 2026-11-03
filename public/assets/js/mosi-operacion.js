(() => {
    const $ = id => document.getElementById(id);
    const state = { data: null, doctors: [], calls: [], callFilter: 'all', chart: null, loading: false };
    const statusLabel = { disponible: 'Disponible', en_consulta: 'En consulta', desconectado: 'Desconectado' };
    const professionLabel = { medico: 'Médico', psicologo: 'Psicólogo', nutriologo: 'Nutriólogo' };

    function iso(date) {
        const offset = date.getTimezoneOffset();
        return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 10);
    }
    function setRange(days) {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - days + 1);
        $('op-desde').value = iso(start);
        $('op-hasta').value = iso(end);
        document.querySelectorAll('[data-days]').forEach(b => b.classList.toggle('active', Number(b.dataset.days) === days));
    }
    function number(value) { return new Intl.NumberFormat('es-MX').format(Number(value || 0)); }
    function duration(seconds) {
        if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) return '—';
        const s = Math.max(0, Math.round(Number(seconds)));
        const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), rest = s % 60;
        if (h) return `${h} h ${m} min`;
        if (m) return `${m} min ${rest ? `${rest} s` : ''}`.trim();
        return `${rest} s`;
    }
    function dateTime(value, short = false) {
        if (!value) return 'Sin registro';
        const normalized = String(value).replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('es-MX', short
            ? { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }
            : { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
    }
    function initials(p) { return `${(p.nombre || '?')[0]}${(p.apellido || '').trim()[0] || ''}`.toUpperCase(); }
    function showLoading(on) {
        state.loading = on;
        $('op-aplicar').disabled = on;
        $('op-aplicar').classList.toggle('loading', on);
    }

    async function load() {
        if (state.loading) return;
        showLoading(true);
        const params = new URLSearchParams({ desde: $('op-desde').value, hasta: $('op-hasta').value });
        if ($('op-mosi').value) params.set('id_mosi', $('op-mosi').value);
        try {
            const json = await obtenerJSON(`api/mosi_operacion.php?${params}`);
            state.data = json.data;
            state.doctors = json.data.medicos || [];
            state.calls = json.data.llamadas || [];
            render(json);
        } catch (error) {
            $('op-notice').hidden = false;
            $('op-notice').textContent = error.message || 'No fue posible cargar el tablero.';
        } finally {
            showLoading(false);
            window.mlRenderIcons?.();
        }
    }

    function render(json) {
        const d = json.data;
        const current = $('op-mosi').value;
        $('op-mosi').innerHTML = (d.mosis || []).length
            ? d.mosis.map(m => `<option value="${m.id_mosi}">${escapeHtml(m.nombre)} · ${escapeHtml(m.numero_serie)}</option>`).join('')
            : '<option value="">No hay módulos disponibles</option>';
        if (d.seleccion) $('op-mosi').value = String(d.seleccion.id_mosi);
        else if (current) $('op-mosi').value = current;
        renderContext(d);
        renderNotice(json.legacy);
        renderKpis(d.stats || {}, d.rango || {});
        renderChart(d.tendencia || []);
        renderTeam(d.medicos || []);
        renderDoctors();
        renderCalls();
        $('team-link').href = d.seleccion ? `personal_clinico.php?id_mosi=${d.seleccion.id_mosi}` : 'personal_clinico.php';
    }
    function renderContext(d) {
        const m = d.seleccion;
        if (!m) {
            $('op-context').innerHTML = '<div class="op-context-copy"><h2>Sin MOSI disponibles</h2><p>Registra o asigna un módulo para comenzar.</p></div>';
            return;
        }
        const connection = m.estado_conexion === 'online' ? 'En línea' : m.estado_conexion === 'offline' ? 'Sin conexión' : 'Nunca conectada';
        $('op-context').innerHTML = `<div class="op-context-icon"><i data-lucide="radio"></i></div><div class="op-context-copy"><small>MOSI seleccionada</small><h2>${escapeHtml(m.nombre)}</h2><p>${escapeHtml(m.ubicacion || m.sucursal_nombre || 'Ubicación no registrada')} · ${escapeHtml(m.consultorio || 'Sin consultorio')} · Serie ${escapeHtml(m.numero_serie)}</p></div><div class="op-context-meta"><span class="op-chip ${m.estado_conexion === 'online' ? 'online' : 'offline'}"><i></i>${connection}</span><span class="op-chip">${number(m.total_personal)} profesionales</span>${m.grupo_nombre ? `<span class="op-chip">${escapeHtml(m.grupo_nombre)}</span>` : ''}</div>`;
    }
    function renderNotice(legacy) {
        const notice = $('op-notice');
        const hide = legacy?.available && legacy?.precision === 'mosi';
        notice.hidden = hide;
        notice.textContent = hide ? '' : (legacy?.message || 'No se pudo determinar el origen de las llamadas.');
    }
    function renderKpis(s, range) {
        $('k-total').textContent = number(s.total);
        $('k-atendidas').textContent = number(s.atendidas);
        $('k-perdidas').textContent = number(s.perdidas);
        $('k-reasignadas').textContent = number(s.reasignadas);
        $('k-tasa').textContent = `${Number(s.tasa_atencion || 0).toLocaleString('es-MX')}% de atención`;
        $('k-espera').textContent = duration(s.espera_promedio_segundos);
        $('k-duracion').textContent = duration(s.duracion_promedio_segundos);
        $('k-periodo').textContent = range.desde && range.hasta ? `${range.desde} a ${range.hasta}` : 'En el periodo';
    }
    function renderChart(rows) {
        const empty = $('chart-empty');
        empty.hidden = rows.length > 0;
        $('op-chart').hidden = !rows.length;
        if (state.chart) state.chart.destroy();
        state.chart = null;
        if (!rows.length || !window.Chart) return;
        const dark = document.body.classList.contains('tema-mdm-oscuro');
        state.chart = new Chart($('op-chart'), {
            type: 'bar',
            data: {
                labels: rows.map(r => new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short' }).format(new Date(`${r.fecha}T12:00:00`))),
                datasets: [
                    { label: 'Atendidas', data: rows.map(r => r.atendidas), backgroundColor: dark ? '#2CD9BE' : '#087D76', borderRadius: 4, maxBarThickness: 24 },
                    { label: 'No atendidas', data: rows.map(r => r.perdidas), backgroundColor: dark ? '#F1707E' : '#D96875', borderRadius: 4, maxBarThickness: 24 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { display: false }, tooltip: { padding: 10, displayColors: true } }, scales: { x: { stacked: true, grid: { display: false }, ticks: { color: dark ? '#8DA2AD' : '#718691', maxTicksLimit: 12, font: { size: 9 } } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0, color: dark ? '#8DA2AD' : '#718691', font: { size: 9 } }, grid: { color: dark ? 'rgba(180,205,215,.09)' : 'rgba(45,75,90,.08)' } } } }
        });
    }
    function renderTeam(doctors) {
        const available = doctors.filter(d => d.estado_operativo === 'disponible').length;
        const consulting = doctors.filter(d => d.estado_operativo === 'en_consulta').length;
        const disconnected = doctors.length - available - consulting;
        const percent = doctors.length ? Math.round(available * 100 / doctors.length) : 0;
        $('team-summary').innerHTML = `<div class="team-ring"><div class="team-ring-visual" style="--available:${percent}"><strong>${available}</strong></div><div class="team-ring-copy"><b>${available === 1 ? 'profesional disponible' : 'profesionales disponibles'}</b><span>${percent}% del equipo asignado está listo para atender.</span></div></div><div class="team-stat-list"><div class="team-stat"><span>En consulta</span><b>${consulting}</b></div><div class="team-stat"><span>Desconectados</span><b>${disconnected}</b></div><div class="team-stat"><span>Total asignado</span><b>${doctors.length}</b></div></div>`;
    }
    function renderDoctors() {
        const query = $('doctor-search').value.trim().toLowerCase();
        const rows = state.doctors.filter(p => `${p.nombre} ${p.apellido} ${p.especialidad || ''}`.toLowerCase().includes(query));
        $('doctor-rows').innerHTML = rows.length ? rows.map(p => `<tr><td><div class="doctor-cell"><span class="doctor-avatar">${initials(p)}</span><div><b>${escapeHtml(`${p.nombre} ${p.apellido || ''}`.trim())}</b><small>${escapeHtml(professionLabel[p.tipo_profesional] || p.tipo_profesional)}${p.especialidad ? ` · ${escapeHtml(p.especialidad)}` : ''}</small></div></div></td><td><span class="status-pill ${escapeHtml(p.estado_operativo)}">${statusLabel[p.estado_operativo] || p.estado_operativo}</span></td><td class="num metric-main">${number(p.total)}</td><td class="num metric-main">${number(p.atendidas)}</td><td class="num metric-main">${number(p.perdidas)}</td><td class="num"><div class="rate"><span>${Number(p.tasa_atencion || 0).toLocaleString('es-MX')}%</span><span class="rate-bar"><i style="width:${Math.min(100, Number(p.tasa_atencion || 0))}%"></i></span></div></td><td><div class="time-stack"><span>${duration(p.espera_promedio_segundos)} <small>espera</small></span><span>${duration(p.duracion_promedio_segundos)} <small>llamada</small></span></div></td><td>${dateTime(p.ultima_llamada, true)}</td></tr>`).join('') : '<tr><td colspan="8"><div class="op-empty" style="position:static;padding:34px"><b>Sin profesionales coincidentes</b><span>Asigna personal a la MOSI o cambia la búsqueda.</span></div></td></tr>';
    }
    function renderCalls() {
        const rows = state.callFilter === 'all' ? state.calls : state.calls.filter(c => c.estado === state.callFilter);
        $('call-rows').innerHTML = rows.length ? rows.map(c => `<tr><td>${dateTime(c.fecha)}</td><td><b style="color:var(--op-text)">${escapeHtml(c.medico)}</b></td><td><span class="origin">${escapeHtml(c.origen)}</span>${c.reasignada ? '<span class="reassigned">Reasignada</span>' : ''}</td><td><span class="result-pill ${c.estado}">${c.estado === 'atendida' ? 'Atendida' : 'No atendida'}</span></td><td>${duration(c.espera_segundos)}</td><td>${duration(c.duracion_segundos)}</td></tr>`).join('') : '<tr><td colspan="6"><div class="op-empty" style="position:static;padding:34px"><b>Sin llamadas para mostrar</b><span>No hay actividad con este filtro.</span></div></td></tr>';
    }
    function exportDoctors() {
        if (!state.doctors.length) return;
        const header = ['Profesional', 'Profesión', 'Estado', 'Recibidas', 'Atendidas', 'No atendidas', 'Reasignadas', 'Nivel de atención', 'Espera promedio', 'Duración promedio', 'Última llamada'];
        const rows = state.doctors.map(p => [`${p.nombre} ${p.apellido || ''}`.trim(), professionLabel[p.tipo_profesional] || p.tipo_profesional, statusLabel[p.estado_operativo] || p.estado_operativo, p.total, p.atendidas, p.perdidas, p.reasignadas, `${p.tasa_atencion}%`, duration(p.espera_promedio_segundos), duration(p.duracion_promedio_segundos), p.ultima_llamada || '']);
        const csv = [header, ...rows].map(row => row.map(v => `"${String(v ?? '').replaceAll('"', '""')}"`).join(',')).join('\r\n');
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `operacion-mosi-${$('op-mosi').value}-${$('op-desde').value}-${$('op-hasta').value}.csv`; a.click(); URL.revokeObjectURL(a.href);
    }

    document.querySelectorAll('[data-days]').forEach(b => b.addEventListener('click', () => { setRange(Number(b.dataset.days)); load(); }));
    document.querySelectorAll('[data-call-filter]').forEach(b => b.addEventListener('click', () => { state.callFilter = b.dataset.callFilter; document.querySelectorAll('[data-call-filter]').forEach(x => x.classList.toggle('active', x === b)); renderCalls(); }));
    $('op-aplicar').addEventListener('click', load);
    $('op-mosi').addEventListener('change', load);
    $('doctor-search').addEventListener('input', renderDoctors);
    $('export-doctors').addEventListener('click', exportDoctors);
    window.addEventListener('mdmthemechange', () => state.data && renderChart(state.data.tendencia || []));
    setRange(30);
    load();
})();
