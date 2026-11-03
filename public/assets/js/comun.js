// public/assets/comun.js
// Helpers compartidos por las vistas del panel MDM.

// Header CSRF a agregar en todo fetch de escritura (POST/PUT/DELETE).
// Lee el token del <meta name="csrf-token"> que cada página autenticada
// emite en su <head> (ver Auth::csrfToken()). Redundante con el parche de
// abajo (que ya lo agrega solo, leyendo la cookie ML_CSRF_TOKEN) pero se
// mantiene para las páginas que ya lo spread manual (...csrfHeader()) en
// sus fetch -- el parche no lo pisa si ya está puesto explícito.
function csrfHeader() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? { 'X-CSRF-Token': meta.content } : {};
}

(function protegerFetchConCsrf() {
    const originalFetch = window.fetch.bind(window);
    const tokenCookie = () => document.cookie
        .split('; ')
        .find(row => row.startsWith('ML_CSRF_TOKEN='))
        ?.split('=')
        .slice(1)
        .join('=') || '';

    const esMismoOrigen = (input) => {
        try {
            const url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
            return url.origin === window.location.origin;
        } catch (_) {
            return true;
        }
    };

    window.fetch = (input, init = {}) => {
        const method = String(init.method || 'GET').toUpperCase();
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && esMismoOrigen(input)) {
            const headers = new Headers(init.headers || {});
            const token = decodeURIComponent(tokenCookie());
            if (token && !headers.has('X-CSRF-Token')) {
                headers.set('X-CSRF-Token', token);
            }
            init = { ...init, headers };
        }
        return originalFetch(input, init);
    };
})();

function cargarSweetAlert() {
    if (window.Swal) return Promise.resolve(window.Swal);
    if (window.__swalPromise) return window.__swalPromise;

    window.__swalPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
        script.async = true;
        script.onload = () => resolve(window.Swal);
        script.onerror = () => reject(new Error('No se pudo cargar SweetAlert2'));
        document.head.appendChild(script);
    });

    return window.__swalPromise;
}

async function mlAlert(mensaje, icono = 'error', titulo = '') {
    const Swal = await cargarSweetAlert();
    await Swal.fire({
        icon: icono,
        title: titulo || (icono === 'success' ? 'Listo' : 'Aviso'),
        text: mensaje,
        confirmButtonText: 'Aceptar',
        confirmButtonColor: '#247A3B',
    });
}

async function mlConfirm(mensaje, titulo = 'Confirmar', textoConfirmar = 'Aceptar') {
    const Swal = await cargarSweetAlert();
    const result = await Swal.fire({
        icon: 'warning',
        title: titulo,
        text: mensaje,
        showCancelButton: true,
        confirmButtonText: textoConfirmar,
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#B42335',
        cancelButtonColor: '#214F8B',
        reverseButtons: true,
    });

    return result.isConfirmed;
}

window.cargarSweetAlert = cargarSweetAlert;
window.mlAlert = mlAlert;
window.mlConfirm = mlConfirm;

function cargarLucide() {
    if (window.lucide) return Promise.resolve(window.lucide);
    if (window.__lucidePromise) return window.__lucidePromise;

    window.__lucidePromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'assets/vendor/lucide/lucide.min.js?v=20260811-3';
        script.async = true;
        script.onload = () => resolve(window.lucide);
        script.onerror = () => reject(new Error('No se pudo cargar Lucide'));
        document.head.appendChild(script);
    });

    return window.__lucidePromise;
}

function aplicarIconosMenu() {
    const nav = document.querySelector('.nav-lateral');
    if (!nav) return;

    const iconos = {
        'dashboard.php': 'layout-dashboard',
        'admin.php': 'monitor-cog',
        'telemedicina.php': 'stethoscope',
        'mosi_operacion.php': 'chart-no-axes-combined',
        'mosi_colaboradores.php': 'user-cog',
        'personal_clinico.php': 'users-round',
        'stock_maquinas.php': 'package-check',
        'planeacion.php': 'calendar-range',
        'abastecimiento.php': 'package-open',
        'distribucion.php': 'route',
        'reportes.php': 'chart-no-axes-combined',
        'mantenimiento.php': 'wrench',
        'inventario.php': 'archive',
        'receta_nueva.php': 'clipboard-plus',
        'carrito.php': 'shopping-cart',
        'productos.php': 'pill',
        'vencimientos.php': 'calendar-x',
        'estadisticas.php': 'trending-up',
        'resurtir.php': 'refresh-ccw',
        'pedidos.php': 'package',
        'actualizaciones_app.php': 'smartphone',
        'tecnicos_android.php': 'clipboard-check',
        'usuarios.php': 'users',
        'ayuda.php': 'circle-help',
        'logout.php': 'log-out',
    };

    nav.querySelectorAll('a[href]').forEach(link => {
        const href = (link.getAttribute('href') || '').split('?')[0].split('#')[0];
        const archivo = href.substring(href.lastIndexOf('/') + 1);
        const icono = iconos[archivo];
        if (!icono) return;

        link.querySelectorAll(':scope > svg').forEach(svg => svg.remove());
        if (link.querySelector(':scope > .nav-icon')) return;

        const i = document.createElement('i');
        i.className = 'nav-icon';
        i.setAttribute('data-lucide', icono);
        i.setAttribute('aria-hidden', 'true');
        link.prepend(i);
    });

    document.body.classList.add('nav-icons-lucide');

    cargarLucide()
        .then(lucide => lucide.createIcons({ attrs: { class: 'nav-icon', 'aria-hidden': 'true' } }))
        .catch(() => {});
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', aplicarIconosMenu);
} else {
    aplicarIconosMenu();
}

async function obtenerJSON(url) {
    const res = await fetch(url);
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Error desconocido');
    return json;
}

function badgeMantenimiento(dias) {
    if (dias === null || dias === undefined) return '<span class="badge badge-danger">Nunca cargada</span>';
    if (dias > 45) return `<span class="badge badge-danger">${dias} días</span>`;
    if (dias > 30) return `<span class="badge badge-warn">${dias} días</span>`;
    return `<span class="badge badge-ok">${dias} días</span>`;
}

// Peor (mayor) número de días entre una lista, tratando "nunca cargada" (null) como el peor caso.
function peorDias(lista) {
    let peor = -1;
    let huboNull = false;
    for (const d of lista) {
        if (d === null || d === undefined) { huboNull = true; continue; }
        if (d > peor) peor = d;
    }
    if (huboNull) return null;
    return peor === -1 ? null : peor;
}

const iconoEnlace = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="icono-enlace">
    <rect x="2.5" y="7" width="9" height="9" rx="2.5" stroke="currentColor" stroke-width="1.6"/>
    <rect x="12.5" y="7" width="9" height="9" rx="2.5" stroke="currentColor" stroke-width="1.6" opacity=".45"/>
    <path d="M11.3 11.5H12.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
</svg>`;

function diasParaCaducar(fechaCaducidad) {
    if (!fechaCaducidad) return null;
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    const f = new Date(fechaCaducidad);
    return Math.round((f - hoy) / 86400000);
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

(function aplicarTemaClaroLegible() {
    // Sustituido por el selector persistente de tema MDM del menu compartido.
    return;
    if (!document.body || !document.body.classList.contains('tema-oscuro')) return;
    if (document.getElementById('tema-claro-legible')) return;

    const css = `
        body.tema-oscuro {
            --bg: #F5F7FA !important;
            --bg-elevada: #FFFFFF !important;
            --bg-elevada-2: #F8FAFC !important;
            --bg-panel: #FFFFFF !important;
            --bg-hover: #EEF3F7 !important;
            --linea: #D8E0E7 !important;
            --linea-suave: #E8EEF3 !important;
            --texto: #14212B !important;
            --texto-suave: #344856 !important;
            --texto-tenue: #647583 !important;
            --petroleo: #087D76 !important;
            --petroleo-fuerte: #075F5A !important;
            --petroleo-wash: rgba(8, 125, 118, .10) !important;
            --azul: #2563B8 !important;
            --azul-wash: rgba(37, 99, 184, .10) !important;
            --ambar: #9A620B !important;
            --ambar-wash: rgba(154, 98, 11, .12) !important;
            --critico: #B42335 !important;
            --critico-wash: rgba(180, 35, 53, .10) !important;
            background: #F5F7FA !important;
            color: #14212B !important;
        }
        body.tema-oscuro .sidebar {
            background: #FFFFFF !important;
            border-right-color: #D8E0E7 !important;
            box-shadow: 12px 0 30px rgba(20,33,43,.06) !important;
        }
        body.tema-oscuro .marca { border-bottom-color: #E8EEF3 !important; }
        body.tema-oscuro .marca-logo { filter: none !important; }
        body.tema-oscuro .nav-lateral a {
            color: #344856 !important;
            background: transparent !important;
        }
        body.tema-oscuro .nav-lateral a:hover {
            background: #EEF3F7 !important;
            border-color: #D8E0E7 !important;
            color: #14212B !important;
        }
        body.tema-oscuro .nav-lateral a.activa {
            background: rgba(8, 125, 118, .10) !important;
            border-color: rgba(8, 125, 118, .28) !important;
            color: #087D76 !important;
            box-shadow: inset 3px 0 0 #087D76 !important;
        }
        body.tema-oscuro h1,
        body.tema-oscuro h2,
        body.tema-oscuro h3,
        body.tema-oscuro .display,
        body.tema-oscuro .serie,
        body.tema-oscuro b {
            color: #14212B !important;
        }
        body.tema-oscuro p,
        body.tema-oscuro td,
        body.tema-oscuro .muted,
        body.tema-oscuro .sub,
        body.tema-oscuro .subtle-note,
        body.tema-oscuro .mini,
        body.tema-oscuro .nota,
        body.tema-oscuro .rol-tag {
            color: #344856 !important;
        }
        body.tema-oscuro .card,
        body.tema-oscuro .bento-card,
        body.tema-oscuro .panel,
        body.tema-oscuro .panel-oscuro,
        body.tema-oscuro .stat,
        body.tema-oscuro .modal,
        body.tema-oscuro .toolbar-card,
        body.tema-oscuro .status-pill,
        body.tema-oscuro .hero-pulso,
        body.tema-oscuro .chip-estado,
        body.tema-oscuro .unidad,
        body.tema-oscuro .kpi-dist,
        body.tema-oscuro .ruta-item {
            background: #FFFFFF !important;
            border-color: #D8E0E7 !important;
            box-shadow: 0 12px 28px rgba(20,33,43,.08) !important;
        }
        body.tema-oscuro .hero-pulso,
        body.tema-oscuro .hero-red {
            background: #FFFFFF !important;
            color: #14212B !important;
        }
        body.tema-oscuro .panel-cabecera,
        body.tema-oscuro .panel-cabecera-osc,
        body.tema-oscuro .panel-head,
        body.tema-oscuro .card-encabezado,
        body.tema-oscuro .modal-encabezado,
        body.tema-oscuro .modal-cabecera,
        body.tema-oscuro .toolbar,
        body.tema-oscuro .barra,
        body.tema-oscuro .barra-inferior,
        body.tema-oscuro th,
        body.tema-oscuro td {
            border-color: #E8EEF3 !important;
        }
        body.tema-oscuro table {
            background: #FFFFFF !important;
        }
        body.tema-oscuro th {
            background: #F8FAFC !important;
            color: #647583 !important;
        }
        body.tema-oscuro tbody tr:hover {
            background: #EEF3F7 !important;
        }
        body.tema-oscuro input,
        body.tema-oscuro select,
        body.tema-oscuro textarea,
        body.tema-oscuro .campo-osc {
            background: #FFFFFF !important;
            border-color: #C8D3DC !important;
            color: #14212B !important;
        }
        body.tema-oscuro input::placeholder,
        body.tema-oscuro textarea::placeholder {
            color: #7B8B98 !important;
        }
        body.tema-oscuro .btn,
        body.tema-oscuro .btn-reporte,
        body.tema-oscuro .btn-cargar-stock,
        body.tema-oscuro .btn-encabezado,
        body.tema-oscuro .btn-icono,
        body.tema-oscuro .acciones-tabla button,
        body.tema-oscuro .paginacion button,
        body.tema-oscuro button:not(.primario):not(.btn-nueva-maquina):not(.btn-guardar):not(.btn-actualizar) {
            background: #FFFFFF !important;
            color: #14212B !important;
            border: 1px solid #C8D3DC !important;
            box-shadow: 0 2px 6px rgba(20,33,43,.06) !important;
        }
        body.tema-oscuro .btn:hover,
        body.tema-oscuro .btn-reporte:hover,
        body.tema-oscuro .btn-cargar-stock:hover,
        body.tema-oscuro .acciones-tabla button:hover,
        body.tema-oscuro .paginacion button:not(:disabled):hover {
            background: #F2F7F6 !important;
            border-color: #087D76 !important;
            color: #087D76 !important;
        }
        body.tema-oscuro .btn.primario,
        body.tema-oscuro button.primario,
        body.tema-oscuro .btn-nueva-maquina,
        body.tema-oscuro .btn-guardar,
        body.tema-oscuro .btn-actualizar {
            background: #0B8F86 !important;
            border-color: #0B8F86 !important;
            color: #FFFFFF !important;
            box-shadow: 0 8px 18px rgba(8,125,118,.22) !important;
        }
        body.tema-oscuro .btn.primario:hover,
        body.tema-oscuro button.primario:hover,
        body.tema-oscuro .btn-nueva-maquina:hover,
        body.tema-oscuro .btn-guardar:hover,
        body.tema-oscuro .btn-actualizar:hover {
            background: #087D76 !important;
            color: #FFFFFF !important;
        }
        body.tema-oscuro .badge-ok { background: rgba(8, 125, 118, .10) !important; color: #087D76 !important; }
        body.tema-oscuro .badge-warn { background: rgba(154, 98, 11, .12) !important; color: #9A620B !important; }
        body.tema-oscuro .badge-danger { background: rgba(180, 35, 53, .10) !important; color: #B42335 !important; }
        body.tema-oscuro .badge-info { background: rgba(37, 99, 184, .10) !important; color: #2563B8 !important; }
        body.tema-oscuro .badge-neutro { background: #F8FAFC !important; color: #647583 !important; border-color: #D8E0E7 !important; }
        body.tema-oscuro .overlay-modal {
            background: rgba(20,33,43,.24) !important;
        }
        body.tema-oscuro .cargando,
        body.tema-oscuro .vacio {
            color: #647583 !important;
        }
    `;

    const style = document.createElement('style');
    style.id = 'tema-claro-legible';
    style.textContent = css;
    document.head.appendChild(style);
})();
