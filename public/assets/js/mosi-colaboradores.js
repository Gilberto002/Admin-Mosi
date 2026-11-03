(() => {
    const $ = id => document.getElementById(id);
    const state = { group: 20, rows: [], catalog: [], selected: null, scheduleSelected: null, loading: false };
    const groupNames = { 20: 'Médicos', 24: 'Psicólogos', 23: 'Nutriólogos' };
    const week = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];

    function initials(name) { return String(name || '?').split(/\s+/).slice(0, 2).map(x => x[0]).join('').toUpperCase(); }
    function formatTime(value) {
        if (!value) return '—';
        const [h, m] = String(value).split(':');
        const hour = Number(h), suffix = hour >= 12 ? 'PM' : 'AM';
        return `${hour % 12 || 12}:${m} ${suffix}`;
    }
    function operationalState(person) {
        if (!Number(person.activo)) return { key: 'disabled', text: 'Desactivado' };
        if (Number(person.en_consulta)) return { key: 'busy', text: 'En consulta' };
        if (Number(person.en_notificacion)) return { key: 'busy', text: 'Llamada asignada' };
        if (Number(person.sesion_hoy)) return { key: 'available', text: 'Disponible' };
        return { key: 'idle', text: 'Sin sesión hoy' };
    }
    function matchesStatus(person, filter) {
        if (!filter) return true;
        if (filter === 'unassigned') return !person.mosis.length;
        return operationalState(person).key === filter;
    }
    function setLoading(on) {
        state.loading = on;
        $('refresh').disabled = on;
        $('refresh').innerHTML = `<i data-lucide="${on ? 'loader-circle' : 'refresh-cw'}"></i>${on ? 'Consultando SYBI…' : 'Actualizar estados'}`;
        window.mlRenderIcons?.();
    }

    async function load() {
        if (state.loading) return;
        setLoading(true);
        $('connection-note').hidden = true;
        try {
            const json = await obtenerJSON(`api/mosi_colaboradores.php?grupo=${state.group}`);
            state.rows = json.data || [];
            state.catalog = json.catalogos?.mosis || [];
            renderStats(json.stats || {});
            renderRows();
            $('last-update').textContent = `Actualizado ${new Intl.DateTimeFormat('es-MX', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date())}`;
        } catch (error) {
            state.rows = [];
            renderRows();
            $('connection-note').hidden = false;
            $('connection-note').textContent = `${error.message || 'No fue posible consultar Mosy.SYBI.'} Verifica TELEMEDICINA_DB_* y el controlador SQL Server del servidor.`;
            $('last-update').textContent = 'Sin conexión con Mosy.SYBI';
        } finally { setLoading(false); }
    }
    function renderStats(s) {
        [['s-total','total'],['s-active','activos'],['s-ready','disponibles'],['s-busy','en_consulta'],['s-off','desactivados'],['s-unassigned','sin_mosi']].forEach(([id,key]) => $(id).textContent = Number(s[key] || 0).toLocaleString('es-MX'));
    }
    function renderRows() {
        const query = $('search').value.trim().toLowerCase(), filter = $('status-filter').value;
        const rows = state.rows.filter(p => `${p.nombre} ${p.sucursal} ${(p.mosis || []).join(' ')}`.toLowerCase().includes(query) && matchesStatus(p, filter));
        $('result-count').textContent = `${rows.length} de ${state.rows.length} colaboradores`;
        $('collaborator-rows').innerHTML = rows.length ? rows.map(rowTemplate).join('') : '<tr><td colspan="7"><div style="padding:38px;text-align:center;color:var(--sc-muted)"><b style="display:block;color:var(--sc-text);margin-bottom:4px">Sin colaboradores para mostrar</b>Cambia los filtros o revisa la conexión con SYBI.</div></td></tr>';
        $('collaborator-rows').querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openEditor(Number(b.dataset.edit)));
        $('collaborator-rows').querySelectorAll('[data-schedule]').forEach(b => b.onclick = () => openSchedule(Number(b.dataset.schedule)));
        $('collaborator-rows').querySelectorAll('[data-toggle]').forEach(b => b.onclick = () => toggle(Number(b.dataset.toggle), 'toggle_active', b.dataset.active === '1'));
        $('collaborator-rows').querySelectorAll('[data-session]').forEach(b => b.onclick = () => toggle(Number(b.dataset.session), 'toggle_session', b.dataset.enabled === '1', true));
        window.mlRenderIcons?.();
    }
    function rowTemplate(p) {
        const stateOp = operationalState(p);
        const mosis = p.mosis.length ? p.mosis.slice(0, 5).map(id => `<span class="mosi-chip">MOSI ${escapeHtml(id)}</span>`).join('') + (p.mosis.length > 5 ? `<span class="mosi-chip">+${p.mosis.length - 5}</span>` : '') : '<span class="mosi-empty">Sin MOSI asignada</span>';
        const work = p.horario?.inicio && p.horario?.fin ? `${formatTime(p.horario.inicio)} – ${formatTime(p.horario.fin)}` : 'Sin horario';
        const meal = p.comida?.inicio && p.comida?.fin ? `Comida ${formatTime(p.comida.inicio)} – ${formatTime(p.comida.fin)}` : 'Sin comida registrada';
        return `<tr><td><div class="person-cell"><span class="person-avatar">${initials(p.nombre)}</span><div><b>${escapeHtml(p.nombre)}</b><span>${escapeHtml(p.sucursal || 'Sin sucursal')} · ID ${p.id_medico}</span></div></div></td><td><div class="schedule-cell"><b>${work}</b><small>${meal}</small></div></td><td><div class="state-stack"><span class="state-pill ${stateOp.key}">${stateOp.text}</span>${p.en_consulta ? '<span class="state-pill busy">Consulta activa</span>' : ''}${p.en_notificacion ? '<span class="state-pill busy">Notificación activa</span>' : ''}</div></td><td><div class="permission-cell"><b>${p.activo ? 'Permisos activos' : 'Permisos desactivados'}</b><small>${p.sesion_habilitada ? 'Recepción habilitada' : 'Recepción cerrada'}</small></div></td><td><div class="mosi-chips">${mosis}</div></td><td><span class="platform-pill">${escapeHtml(p.plataforma || 'ALL')}</span></td><td><div class="row-actions"><button class="row-btn" data-schedule="${p.id_medico}"><i data-lucide="calendar-clock"></i>Horario</button><button class="row-btn" data-edit="${p.id_medico}"><i data-lucide="monitor-cog"></i>MOSI</button><button class="row-btn ${p.sesion_habilitada ? 'danger' : 'success'}" data-session="${p.id_medico}" data-enabled="${p.sesion_habilitada ? 1 : 0}" title="${p.sesion_habilitada ? 'Cerrar recepción de llamadas' : 'Abrir recepción de llamadas'}" ${p.activo ? '' : 'disabled'}><i data-lucide="${p.sesion_habilitada ? 'phone-off' : 'phone-call'}"></i></button><button class="row-btn ${p.activo ? 'danger' : 'success'}" data-toggle="${p.id_medico}" data-active="${p.activo ? 1 : 0}">${p.activo ? 'Desactivar' : 'Activar'}</button></div></td></tr>`;
    }
    function scheduleRows(hours = {}) {
        $('schedule-rows').innerHTML = week.map(day => {
            const work = hours[day]?.laboral || {}, meal = hours[day]?.comida || {};
            const label = day === 'Miercoles' ? 'Miércoles' : day === 'Sabado' ? 'Sábado' : day;
            return `<tr data-day="${day}"><td><b>${label}</b></td><td><input type="time" data-kind="laboral" data-edge="inicio" value="${escapeHtml(work.inicio || '')}"></td><td><input type="time" data-kind="laboral" data-edge="fin" value="${escapeHtml(work.fin || '')}"></td><td><input type="time" data-kind="comida" data-edge="inicio" value="${escapeHtml(meal.inicio || '')}"></td><td><input type="time" data-kind="comida" data-edge="fin" value="${escapeHtml(meal.fin || '')}"></td></tr>`;
        }).join('');
    }
    async function openSchedule(id) {
        const source = state.rows.find(p => Number(p.id_medico) === id);
        if (!source) return;
        state.scheduleSelected = source;
        $('schedule-doctor').value = id;
        $('schedule-name').textContent = `Horarios de ${source.nombre}`;
        $('schedule-status').textContent = `${source.sucursal || 'Sin sucursal'} · ID ${source.id_medico}`;
        $('schedule-message').textContent = 'Cargando horario semanal…';
        const busy = Number(source.en_notificacion) || Number(source.en_consulta);
        $('attention-title').textContent = busy ? 'Atención activa detectada' : 'Sin atención activa';
        $('attention-detail').textContent = busy ? `${source.en_notificacion ? 'Notificación pendiente. ' : ''}${source.en_consulta ? 'Consulta marcada como activa.' : ''}` : 'No hay notificaciones ni consultas pendientes.';
        $('attention-box').classList.toggle('active', Boolean(busy));
        $('release-attention').hidden = !busy;
        scheduleRows();
        $('schedule-overlay').classList.add('open');
        $('schedule-overlay').setAttribute('aria-hidden', 'false');
        window.mlRenderIcons?.();
        try {
            const json = await obtenerJSON(`api/mosi_colaboradores.php?accion=schedule&grupo=${state.group}&id_medico=${id}`);
            scheduleRows(json.data?.horarios || {});
            $('schedule-message').textContent = '';
        } catch (error) { $('schedule-message').textContent = error.message || 'No se pudo cargar el horario.'; }
    }
    function closeSchedule() {
        $('schedule-overlay').classList.remove('open');
        $('schedule-overlay').setAttribute('aria-hidden', 'true');
    }
    function readSchedule() {
        const hours = {};
        document.querySelectorAll('#schedule-rows tr[data-day]').forEach(row => {
            hours[row.dataset.day] = { laboral: {}, comida: {} };
            row.querySelectorAll('input[type=time]').forEach(input => hours[row.dataset.day][input.dataset.kind][input.dataset.edge] = input.value);
        });
        return hours;
    }
    function openEditor(id) {
        const source = state.rows.find(p => Number(p.id_medico) === id);
        if (!source) return;
        state.selected = { ...source, mosis: [...source.mosis] };
        $('modal-doctor').value = id;
        $('modal-name').textContent = state.selected.nombre;
        $('modal-status').textContent = `${state.selected.sucursal || 'Sin sucursal'} · ${state.selected.mosis.length} MOSI asignadas`;
        $('modal-platform').value = ['ALL','APP','Kiosco'].includes(state.selected.plataforma) ? state.selected.plataforma : 'ALL';
        $('modal-search').value = '';
        renderMosis();
        $('modal-message').textContent = '';
        $('assignment-overlay').classList.add('open');
        $('assignment-overlay').setAttribute('aria-hidden', 'false');
        window.mlRenderIcons?.();
    }
    function renderMosis() {
        const query = $('modal-search').value.trim().toLowerCase();
        const selected = new Set((state.selected?.mosis || []).map(String));
        const rows = state.catalog.filter(m => `${m.nombre} ${m.id_mosi}`.toLowerCase().includes(query));
        $('modal-mosis').innerHTML = rows.length ? rows.map(m => `<label class="mosi-option"><input type="checkbox" value="${escapeHtml(m.id_mosi)}" ${selected.has(String(m.id_mosi)) ? 'checked' : ''}><span><b>${escapeHtml(m.nombre)}</b><small>MOSI ${escapeHtml(m.id_mosi)}</small></span></label>`).join('') : '<div style="color:var(--sc-muted);font-size:10px">No hay MOSI que coincidan.</div>';
        $('modal-mosis').querySelectorAll('input').forEach(input => input.onchange = () => {
            const value = String(input.value);
            state.selected.mosis = input.checked ? [...new Set([...state.selected.mosis.map(String), value])] : state.selected.mosis.map(String).filter(x => x !== value);
        });
    }
    function closeEditor() { $('assignment-overlay').classList.remove('open'); $('assignment-overlay').setAttribute('aria-hidden', 'true'); }
    async function post(body) {
        const response = await fetch('api/mosi_colaboradores.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, grupo: state.group }) });
        const json = await response.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo guardar el cambio.');
        return json;
    }
    async function toggle(id, action, currentlyEnabled, session = false) {
        const verb = currentlyEnabled ? 'desactivar' : 'activar';
        const subject = session ? 'la recepción de llamadas' : 'los permisos del colaborador';
        if (!await mlConfirm(`Se va a ${verb} ${subject} directamente en Mosy.SYBI.`, `${verb[0].toUpperCase() + verb.slice(1)} colaborador`, verb[0].toUpperCase() + verb.slice(1))) return;
        try { await post({ accion: action, id_medico: id }); await load(); } catch (error) { await mlAlert(error.message); }
    }

    $('assignment-form').onsubmit = async event => {
        event.preventDefault();
        const id = Number($('modal-doctor').value), button = event.submitter;
        button.disabled = true; $('modal-message').textContent = 'Guardando en Mosy.SYBI…';
        try {
            await post({ accion: 'save_access', id_medico: id, plataforma: $('modal-platform').value, mosis: state.selected.mosis });
            closeEditor(); await load();
        } catch (error) { $('modal-message').textContent = error.message || 'No se pudo guardar.'; }
        finally { button.disabled = false; }
    };
    $('schedule-form').onsubmit = async event => {
        event.preventDefault();
        const button = event.submitter;
        button.disabled = true;
        $('schedule-message').textContent = 'Guardando horarios en Mosy.SYBI…';
        try {
            await post({ accion: 'save_schedule', id_medico: Number($('schedule-doctor').value), horarios: readSchedule() });
            closeSchedule();
            await load();
            await mlAlert('Horarios actualizados correctamente.', 'success');
        } catch (error) { $('schedule-message').textContent = error.message || 'No se pudieron guardar los horarios.'; }
        finally { button.disabled = false; }
    };
    $('release-attention').onclick = async () => {
        if (!state.scheduleSelected || !await mlConfirm('Se liberarán la notificación y la consulta activas de hoy para este colaborador.', 'Liberar atención', 'Liberar')) return;
        $('release-attention').disabled = true;
        try {
            const json = await post({ accion: 'release_attention', id_medico: state.scheduleSelected.id_medico });
            const total = Number(json.data?.notificaciones_liberadas || 0) + Number(json.data?.consultas_liberadas || 0);
            closeSchedule();
            await load();
            await mlAlert(total ? 'La atención quedó liberada.' : 'No había una atención activa para liberar.', 'success');
        } catch (error) { await mlAlert(error.message || 'No se pudo liberar la atención.'); }
        finally { $('release-attention').disabled = false; }
    };
    document.querySelectorAll('[data-close]').forEach(b => b.onclick = closeEditor);
    document.querySelectorAll('[data-schedule-close]').forEach(b => b.onclick = closeSchedule);
    $('assignment-overlay').onclick = event => { if (event.target === $('assignment-overlay')) closeEditor(); };
    $('schedule-overlay').onclick = event => { if (event.target === $('schedule-overlay')) closeSchedule(); };
    $('modal-search').oninput = renderMosis;
    $('search').oninput = renderRows;
    $('status-filter').onchange = renderRows;
    $('refresh').onclick = load;
    document.querySelectorAll('[data-group]').forEach(button => button.onclick = () => {
        state.group = Number(button.dataset.group);
        document.querySelectorAll('[data-group]').forEach(x => x.classList.toggle('active', x === button));
        $('table-title').textContent = groupNames[state.group];
        $('search').value = ''; $('status-filter').value = '';
        load();
    });
    load();
})();
