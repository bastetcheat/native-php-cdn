/**
 * CDN Admin Panel – Single Page Application
 * Hash-based routing, fetch API, CSRF protection
 */
(() => {
    'use strict';

    // ─── State ───
    let state = {
        user: null,
        csrfToken: '',
        currentPage: 'login',
    };

    const app = document.getElementById('app');
    // Relative path from /admin/ up to project root
    const basePath = '../';

    // Compute absolute CDN base URL once (strip hash and trailing /admin/ segment)
    // e.g. https://cdn-loader.bastet.win/v2
    const _adminPath = location.pathname.replace(/\/[^\/]*$/, ''); // strip filename if any
    const _baseParts = _adminPath.split('/');
    _baseParts.pop(); // go up one level (out of /admin)
    window._cdnBase = location.origin + (_baseParts.join('/') || '');

    // ─── Global JS Tooltip Engine ───────────────────────────────────────────
    // CSS ::before/::after approach doesn't work on .btn (overflow:hidden clips it
    // and ::after is already used for the shimmer). A single fixed <div> moved by
    // JS works perfectly everywhere.
    const _tip = document.createElement('div');
    _tip.className = 'tooltip-popup';
    document.body.appendChild(_tip);

    document.addEventListener('mouseover', function (e) {
        const el = e.target.closest('[data-tooltip]');
        if (!el) return;
        _tip.textContent = el.dataset.tooltip;
        _tip.classList.add('visible');
        const r = el.getBoundingClientRect();
        // position above the element, centred
        const tipW = _tip.offsetWidth;
        let left = r.left + r.width / 2 - tipW / 2;
        // clamp to viewport
        left = Math.max(8, Math.min(left, window.innerWidth - tipW - 8));
        _tip.style.left = left + 'px';
        _tip.style.top = (r.top - _tip.offsetHeight - 8) + 'px';
    });
    document.addEventListener('mouseout', function (e) {
        const el = e.target.closest('[data-tooltip]');
        if (el) _tip.classList.remove('visible');
    });
    // Hide when scrolling so it doesn't float away from its anchor element
    document.addEventListener('scroll', function () { _tip.classList.remove('visible'); }, true);

    // ─── API Helper ───
    async function api(endpoint, options = {}) {
        const url = basePath + 'api/' + endpoint;
        const headers = options.headers || {};

        if (state.csrfToken && options.method && options.method !== 'GET') {
            headers['X-CSRF-Token'] = state.csrfToken;
        }

        if (options.body && !(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }

        try {
            const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
            const data = await res.json();
            if (!res.ok) {
                const err = new Error(data.error || `HTTP ${res.status}`);
                Object.assign(err, data); // pass same_version, sha256_hash, etc. through
                throw err;
            }
            return data;
        } catch (err) {
            if (err.message === 'Authentication required') {
                state.user = null;
                state.csrfToken = '';
                navigate('login');
            }
            throw err;
        }
    }

    // ─── Toast Notifications ───
    function toast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const icons = { success: 'check-circle', error: 'alert-circle', info: 'info' };
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.innerHTML = `<i data-lucide="${icons[type] || 'info'}"></i><span>${esc(message)}</span>`;
        container.appendChild(el);
        lucide.createIcons({ attrs: { class: 'w-4 h-4' }, nameAttr: 'data-lucide' });
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px)';
            el.style.transition = 'all 0.3s ease';
            setTimeout(() => el.remove(), 300);
        }, 4000);
    }

    // ─── XSS-safe escape ───
    function esc(str) {
        if (str === null || str === undefined) return '';
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    // ─── Copy to clipboard ───
    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            toast('Copied to clipboard!', 'success');
        } catch {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            toast('Copied to clipboard!', 'success');
        }
    }

    // ─── Format file size ───
    function formatSize(bytes) {
        if (!bytes) return '0 B';
        const k = 1024, sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // ─── Time ago ───
    function timeAgo(dateStr) {
        if (!dateStr) return 'Never';
        const seconds = Math.floor((new Date() - new Date(dateStr + 'Z')) / 1000);
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        if (seconds < 2592000) return Math.floor(seconds / 86400) + 'd ago';
        return new Date(dateStr).toLocaleDateString();
    }

    // ─── Router ───
    function navigate(page) {
        location.hash = '#' + page;
    }

    function getRoute() {
        return location.hash.slice(1) || 'login';
    }

    async function router() {
        const route = getRoute();
        state.currentPage = route;

        // Check auth for protected pages
        if (route !== 'login') {
            if (!state.user) {
                try {
                    const res = await api('auth/me');
                    state.user = res.data;
                    state.csrfToken = res.data.csrf_token;
                } catch {
                    navigate('login');
                    return;
                }
            }
            // Force password change
            if (state.user && state.user.must_change_pw && route !== 'force-password') {
                navigate('force-password');
                return;
            }
        }

        const pages = {
            'login': renderLogin,
            'force-password': renderForcePassword,
            'dashboard': renderDashboard,
            'tokens': renderTokens,
            'settings': renderSettings,
            'docs': renderDocs,
        };

        const renderer = pages[route] || pages['login'];
        renderer();
        lucide.createIcons({ attrs: { class: 'w-5 h-5' }, nameAttr: 'data-lucide' });
    }

    window.addEventListener('hashchange', router);

    // ─── Layout Shell ───
    function renderLayout(activeNav, content) {
        const navItems = [
            { id: 'dashboard', icon: 'layout-dashboard', label: 'Files' },
            { id: 'tokens', icon: 'key-round', label: 'OAuth Tokens' },
            { id: 'settings', icon: 'settings', label: 'Settings' },
            { id: 'docs', icon: 'book-open', label: 'API Docs' },
        ];

        app.innerHTML = `
        <div class="flex min-h-screen">
            <!-- Sidebar -->
            <aside class="sidebar w-64 fixed inset-y-0 left-0 glass border-r border-white/5 z-50 flex flex-col" style="border-radius:0">
                <!-- Logo -->
                <div class="p-6 border-b border-white/5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/25">
                            <i data-lucide="cloud" class="w-5 h-5 text-white"></i>
                        </div>
                        <div>
                            <h1 class="font-bold text-lg gradient-text">CDN Panel</h1>
                            <p class="text-xs text-slate-500">File Management</p>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 p-4 space-y-1 stagger">
                    ${navItems.map(item => `
                        <a onclick="location.hash='#${item.id}'" class="nav-link animate-slide-up ${activeNav === item.id ? 'active' : ''}">
                            <i data-lucide="${item.icon}"></i>
                            <span>${item.label}</span>
                        </a>
                    `).join('')}
                </nav>

                <!-- User Info -->
                <div class="p-4 border-t border-white/5">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500/20 to-purple-500/20 flex items-center justify-center border border-indigo-500/20">
                            <i data-lucide="user" class="w-4 h-4 text-indigo-400"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate">${esc(state.user?.username || 'Admin')}</p>
                            <p class="text-xs text-slate-500">Administrator</p>
                        </div>
                    </div>
                    <button onclick="doLogout()" class="btn btn-ghost btn-sm w-full justify-center">
                        <i data-lucide="log-out" class="w-4 h-4"></i> Sign Out
                    </button>
                </div>
            </aside>

            <!-- Mobile toggle -->
            <button onclick="document.querySelector('.sidebar').classList.toggle('open')" class="fixed top-4 left-4 z-[60] p-2 glass rounded-lg md:hidden">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>

            <!-- Main Content -->
            <main class="main-content flex-1 ml-64 p-8 relative z-10">
                <div class="max-w-6xl mx-auto page-enter">
                    ${content}
                </div>
            </main>
        </div>`;
    }

    // ════════════════════════════════════
    //  LOGIN PAGE
    // ════════════════════════════════════
    function renderLogin() {
        app.innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-4">
            <div class="w-full max-w-md animate-scale-in">
                <!-- Logo -->
                <div class="text-center mb-8 animate-float">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center mx-auto mb-4 shadow-xl shadow-indigo-500/25 animate-pulse-glow">
                        <i data-lucide="cloud" class="w-8 h-8 text-white"></i>
                    </div>
                    <h1 class="text-3xl font-bold gradient-text">CDN Panel</h1>
                    <p class="text-slate-500 mt-1">Sign in to manage your files</p>
                </div>

                <!-- Form -->
                <div id="login-card" class="glass p-8 space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-2">Username</label>
                        <input id="login-user" type="text" class="input" placeholder="Enter username" autocomplete="username" autofocus>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-2">Password</label>
                        <input id="login-pass" type="password" class="input" placeholder="Enter password" autocomplete="current-password">
                    </div>
                    <button id="login-btn" class="btn btn-primary w-full justify-center text-base py-3">
                        <i data-lucide="log-in" class="w-5 h-5"></i> Sign In
                    </button>
                    <p id="login-error" class="text-red-400 text-sm text-center hidden"></p>
                </div>

                <p class="text-center text-slate-600 text-xs mt-6">Secure CDN Management System</p>
            </div>
        </div>`;

        const btn = document.getElementById('login-btn');
        const userInput = document.getElementById('login-user');
        const passInput = document.getElementById('login-pass');
        const errEl = document.getElementById('login-error');
        const card = document.getElementById('login-card');

        async function doLogin() {
            const u = userInput.value.trim();
            const p = passInput.value;
            if (!u || !p) { toast('Please fill in all fields', 'error'); return; }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Signing in...';

            try {
                const res = await api('auth/login', { method: 'POST', body: { username: u, password: p } });
                state.user = res.data;
                state.csrfToken = res.data.csrf_token;
                toast('Welcome back, ' + res.data.username + '!', 'success');

                if (res.data.must_change_pw) {
                    navigate('force-password');
                } else {
                    navigate('dashboard');
                }
            } catch (err) {
                errEl.textContent = err.message;
                errEl.classList.remove('hidden');
                card.classList.add('animate-shake');
                setTimeout(() => card.classList.remove('animate-shake'), 600);
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="log-in" class="w-5 h-5"></i> Sign In';
                lucide.createIcons({ attrs: { class: 'w-5 h-5' }, nameAttr: 'data-lucide' });
            }
        }

        btn.addEventListener('click', doLogin);
        passInput.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
        userInput.addEventListener('keydown', e => { if (e.key === 'Enter') passInput.focus(); });
    }

    // ════════════════════════════════════
    //  FORCE PASSWORD CHANGE
    // ════════════════════════════════════
    function renderForcePassword() {
        app.innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-4">
            <div class="w-full max-w-md animate-scale-in">
                <div class="text-center mb-8">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center mx-auto mb-4 shadow-xl shadow-amber-500/25">
                        <i data-lucide="shield-alert" class="w-8 h-8 text-white"></i>
                    </div>
                    <h1 class="text-2xl font-bold">Set New Password</h1>
                    <p class="text-slate-500 mt-1">You must change your password before continuing</p>
                </div>

                <div class="glass p-8 space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-2">New Password</label>
                        <input id="fp-pass" type="password" class="input" placeholder="Enter new password" autofocus>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-2">Confirm Password</label>
                        <input id="fp-confirm" type="password" class="input" placeholder="Confirm new password">
                    </div>
                    <button id="fp-btn" class="btn btn-primary w-full justify-center text-base py-3">
                        <i data-lucide="check" class="w-5 h-5"></i> Set Password
                    </button>
                </div>
            </div>
        </div>`;

        document.getElementById('fp-btn').addEventListener('click', async () => {
            const pass = document.getElementById('fp-pass').value;
            const confirm = document.getElementById('fp-confirm').value;

            if (!pass) { toast('Password is required', 'error'); return; }
            if (pass !== confirm) { toast('Passwords do not match', 'error'); return; }

            try {
                await api('settings/password', { method: 'PUT', body: { new_password: pass } });
                state.user.must_change_pw = false;
                toast('Password set successfully!', 'success');
                navigate('dashboard');
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        document.getElementById('fp-confirm').addEventListener('keydown', e => {
            if (e.key === 'Enter') document.getElementById('fp-btn').click();
        });
    }

    // ════════════════════════════════════
    //  DASHBOARD / FILE MANAGER
    // ════════════════════════════════════
    function renderDashboard() {
        renderLayout('dashboard', `
            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-bold">File Manager</h2>
                    <p class="text-slate-500 mt-1">Upload, manage, and share your files</p>
                </div>
                <div class="flex gap-3">
                    <div class="relative">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input id="file-search" type="text" class="input pl-10 w-64" placeholder="Search files...">
                    </div>
                </div>
            </div>

            <!-- Upload Zone -->
            <div id="drop-zone" class="drop-zone mb-8 animate-slide-up">
                <i data-lucide="cloud-upload" class="w-12 h-12 mx-auto mb-4 text-slate-500"></i>
                <p class="text-lg font-medium">Drop files here or click to upload</p>
                <p class="text-slate-500 text-sm mt-1">Maximum file size: 100MB</p>
                <input id="file-input" type="file" multiple class="hidden">
            </div>

            <!-- Upload Progress -->
            <div id="upload-progress" class="hidden mb-6 glass p-4 animate-slide-up">
                <div class="flex items-center gap-3">
                    <span class="spinner"></span>
                    <span class="text-sm" id="upload-status">Uploading...</span>
                </div>
            </div>

            <!-- Stats -->
            <div id="file-stats" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 stagger"></div>

            <!-- File Table -->
            <div id="file-table-container" class="animate-slide-up"></div>

            <!-- Pagination -->
            <div id="pagination" class="flex items-center justify-between mt-4"></div>

            <!-- Update Modal -->
            <div id="update-modal" class="hidden"></div>
        `);

        // Setup drop zone
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');

        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            handleUpload(e.dataTransfer.files);
        });
        fileInput.addEventListener('change', () => handleUpload(fileInput.files));

        // Search
        let searchTimeout;
        document.getElementById('file-search').addEventListener('input', e => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => loadFiles(1, e.target.value), 300);
        });

        loadFiles();
    }

    async function handleUpload(fileList) {
        if (!fileList.length) return;
        const prog = document.getElementById('upload-progress');
        const status = document.getElementById('upload-status');
        prog.classList.remove('hidden');

        for (let i = 0; i < fileList.length; i++) {
            status.textContent = `Uploading ${fileList[i].name} (${i + 1}/${fileList.length})...`;
            const fd = new FormData();
            fd.append('file', fileList[i]);
            try {
                await api('files/upload', { method: 'POST', body: fd });
                toast(`Uploaded: ${fileList[i].name}`, 'success');
            } catch (err) {
                toast(`Failed: ${fileList[i].name} – ${err.message}`, 'error');
            }
        }

        prog.classList.add('hidden');
        loadFiles();
    }

    async function loadFiles(page = 1, search = '') {
        const container = document.getElementById('file-table-container');
        const statsEl = document.getElementById('file-stats');
        const pagEl = document.getElementById('pagination');

        try {
            let url = `files?page=${page}&per_page=15`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            const res = await api(url);
            const { files, total, pages, page: curPage } = res.data;

            // Stats
            const totalSize = files.reduce((sum, f) => sum + (f.size || 0), 0);
            const totalDownloads = files.reduce((sum, f) => sum + (f.download_count || 0), 0);
            statsEl.innerHTML = `
                <div class="glass glass-hover p-5 animate-slide-up flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-indigo-500/10 flex items-center justify-center">
                        <i data-lucide="files" class="w-5 h-5 text-indigo-400"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold">${total}</p>
                        <p class="text-xs text-slate-500 uppercase tracking-wider">Total Files</p>
                    </div>
                </div>
                <div class="glass glass-hover p-5 animate-slide-up flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-purple-500/10 flex items-center justify-center">
                        <i data-lucide="hard-drive" class="w-5 h-5 text-purple-400"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold">${formatSize(totalSize)}</p>
                        <p class="text-xs text-slate-500 uppercase tracking-wider">Page Size</p>
                    </div>
                </div>
                <div class="glass glass-hover p-5 animate-slide-up flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-emerald-500/10 flex items-center justify-center">
                        <i data-lucide="download" class="w-5 h-5 text-emerald-400"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold">${totalDownloads}</p>
                        <p class="text-xs text-slate-500 uppercase tracking-wider">Downloads</p>
                    </div>
                </div>`;

            if (files.length === 0) {
                container.innerHTML = `
                    <div class="glass p-12 text-center">
                        <i data-lucide="folder-open" class="w-16 h-16 mx-auto mb-4 text-slate-600"></i>
                        <p class="text-lg font-medium text-slate-400">No files yet</p>
                        <p class="text-sm text-slate-600 mt-1">Upload your first file to get started</p>
                    </div>`;
            } else {
                container.innerHTML = `
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Version</th>
                                <th>Downloads</th>
                                <th>Uploaded</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${files.map(f => `
                            <tr class="group">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-indigo-500/10 flex items-center justify-center flex-shrink-0">
                                            <i data-lucide="${getFileIcon(f.mime_type)}" class="w-4 h-4 text-indigo-400"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-medium truncate max-w-[200px] text-slate-200">${esc(f.original_name)}</p>
                                            <p class="text-xs text-slate-600 font-mono">${esc(f.sha256_hash?.substring(0, 12) || '')}...</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="font-mono text-xs">${formatSize(f.size)}</td>
                                <td><span class="badge badge-active">${esc(f.extension || f.mime_type)}</span></td>
                                <td class="text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-slate-800 text-xs font-bold">v${f.version}</span>
                                </td>
                                <td class="font-mono text-xs">${f.download_count}</td>
                                <td class="text-xs text-slate-500">${timeAgo(f.created_at)}</td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <button onclick="copyDownload(this)" data-name="${esc(f.original_name)}" class="btn btn-ghost btn-xs tip" data-tooltip="Copy Download URL">
                                            <i data-lucide="link" class="w-3 h-3"></i>
                                        </button>
                                        <button onclick="copyMeta(this)" data-name="${esc(f.original_name)}" class="btn btn-ghost btn-xs tip" data-tooltip="Copy Metadata URL">
                                            <i data-lucide="file-json" class="w-3 h-3"></i>
                                        </button>
                                        <button onclick="showUpdateModal(${f.id})" class="btn btn-ghost btn-xs tip" data-tooltip="Update File">
                                            <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                        </button>
                                        <button onclick="deleteFile(${f.id}, this)" data-name="${esc(f.original_name)}" class="btn btn-ghost btn-xs text-red-400 hover:text-red-300 tip" data-tooltip="Delete">
                                            <i data-lucide="trash-2" class="w-3 h-3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>`;
            }

            // Pagination
            if (pages > 1) {
                let pgHtml = '<div class="flex gap-1">';
                for (let p = 1; p <= pages; p++) {
                    pgHtml += `<button onclick="loadFiles(${p}, '${esc(search)}')" class="btn ${p === curPage ? 'btn-primary' : 'btn-ghost'} btn-sm">${p}</button>`;
                }
                pgHtml += '</div>';
                pagEl.innerHTML = `<span class="text-sm text-slate-500">${total} files total</span>${pgHtml}`;
            } else {
                pagEl.innerHTML = '';
            }

            lucide.createIcons({ attrs: { class: ['w-5', 'h-5'] }, nameAttr: 'data-lucide' });
        } catch (err) {
            container.innerHTML = `<div class="glass p-8 text-center text-red-400"><p>${esc(err.message)}</p></div>`;
        }
    }

    function getFileIcon(mime) {
        if (!mime) return 'file';
        if (mime.startsWith('image/')) return 'image';
        if (mime.startsWith('video/')) return 'video';
        if (mime.startsWith('audio/')) return 'music';
        if (mime.includes('pdf')) return 'file-text';
        if (mime.includes('zip') || mime.includes('rar') || mime.includes('7z') || mime.includes('gzip')) return 'archive';
        if (mime.includes('json') || mime.includes('xml')) return 'file-code';
        return 'file';
    }

    // ─── File Actions (global) ───
    window.loadFiles = loadFiles;
    window.copyText = copyText;

    window.copyDownload = function (btn) {
        copyText(window._cdnBase + '/files/download/' + encodeURIComponent(btn.dataset.name));
    };
    window.copyMeta = function (btn) {
        copyText(window._cdnBase + '/files/metadata/' + encodeURIComponent(btn.dataset.name));
    };

    window.deleteFile = async function (id, btn) {
        const name = (btn && btn.dataset && btn.dataset.name) ? btn.dataset.name : String(id);
        if (!confirm(`Delete "${name}"? This action cannot be undone.`)) return;
        try {
            await api(`files/${id}`, { method: 'DELETE' });
            toast('File deleted', 'success');
            loadFiles();
        } catch (err) {
            toast(err.message, 'error');
        }
    };

    window.showUpdateModal = function (id) {
        const modal = document.getElementById('update-modal');
        modal.classList.remove('hidden');
        modal.innerHTML = `
        <div class="modal-overlay" onclick="if(event.target===this)document.getElementById('update-modal').classList.add('hidden')">
            <div class="modal-content glass p-6 space-y-4">
                <h3 class="text-lg font-bold flex items-center gap-2"><i data-lucide="refresh-cw" class="w-5 h-5 text-indigo-400"></i> Update File</h3>
                <p class="text-sm text-slate-400">Upload a new version of this file. The version number will be incremented.</p>
                <input id="update-file-input" type="file" class="input py-2">
                <div class="flex gap-3">
                    <button id="update-confirm" class="btn btn-primary flex-1 justify-center">
                        <i data-lucide="upload" class="w-4 h-4"></i> Upload New Version
                    </button>
                    <button onclick="document.getElementById('update-modal').classList.add('hidden')" class="btn btn-ghost">Cancel</button>
                </div>
            </div>
        </div>`;
        lucide.createIcons({ attrs: { class: 'w-5 h-5' }, nameAttr: 'data-lucide' });

        document.getElementById('update-confirm').addEventListener('click', async () => {
            const fileInput = document.getElementById('update-file-input');
            if (!fileInput.files.length) { toast('Select a file', 'error'); return; }
            const fd = new FormData();
            fd.append('file', fileInput.files[0]);
            try {
                await api(`files/${id}`, { method: 'POST', body: fd });
                modal.classList.add('hidden');
                toast('File updated to a new version!', 'success');
                loadFiles();
            } catch (err) {
                // ── Smart versioning: same-file warning ──────────────────────
                // The API returns HTTP 409 + same_version:true when the uploaded
                // file has the same SHA-256 hash as the current stored version.
                if (err.same_version) {
                    modal.innerHTML = `
                    <div class="modal-overlay" onclick="if(event.target===this)document.getElementById('update-modal').classList.add('hidden')">
                        <div class="modal-content glass p-6 space-y-4 border border-amber-500/30">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-amber-500/15 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="shield-check" class="w-5 h-5 text-amber-400"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-amber-300">No Update Needed</h3>
                                    <p class="text-xs text-slate-400 mt-0.5">Version v${err.current_version || '?'} is already up to date</p>
                                </div>
                            </div>
                            <p class="text-sm text-slate-300 leading-relaxed">
                                The file you selected is <span class="text-amber-300 font-semibold">byte-for-byte identical</span>
                                to the current version — same SHA-256 hash. Uploading it would not create a meaningful new version.
                            </p>
                            <div class="bg-black/30 rounded-lg px-3 py-2 font-mono text-xs text-slate-400 break-all">
                                <span class="text-slate-600">sha256: </span>${err.sha256_hash || ''}
                            </div>
                            <p class="text-xs text-slate-500">If you intended to upload a <em>different</em> version, please select the correct file.</p>
                            <button onclick="document.getElementById('update-modal').classList.add('hidden')" class="btn btn-ghost w-full justify-center">
                                Close
                            </button>
                        </div>
                    </div>`;
                    lucide.createIcons({ attrs: { class: 'w-5 h-5' }, nameAttr: 'data-lucide' });
                } else {
                    toast(err.message, 'error');
                }
            }
        });
    };

    // ════════════════════════════════════
    //  OAUTH TOKENS PAGE
    // ════════════════════════════════════
    function renderTokens() {
        renderLayout('tokens', `
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-bold">OAuth Tokens</h2>
                    <p class="text-slate-500 mt-1">Manage API access tokens for external services</p>
                </div>
                <button id="create-token-btn" class="btn btn-primary">
                    <i data-lucide="plus" class="w-4 h-4"></i> Create Token
                </button>
            </div>

            <!-- Token Created Modal -->
            <div id="token-created-modal" class="hidden"></div>

            <!-- Create Token Form -->
            <div id="create-token-form" class="hidden glass p-6 mb-6 animate-slide-down space-y-4">
                <h3 class="text-lg font-semibold">Create New Token</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-2">Token Name</label>
                        <input id="token-name" class="input" placeholder="e.g. AI Agent Upload Key">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-400 mb-2">Expiration (optional)</label>
                        <input id="token-expiry" type="datetime-local" class="input">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-400 mb-2">Permissions</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" id="perm-upload" checked class="accent-indigo-500 w-4 h-4"> Upload
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" id="perm-download" checked class="accent-indigo-500 w-4 h-4"> Download
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" id="perm-metadata" checked class="accent-indigo-500 w-4 h-4"> Metadata
                        </label>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button id="token-submit" class="btn btn-primary">
                        <i data-lucide="key-round" class="w-4 h-4"></i> Generate Token
                    </button>
                    <button onclick="document.getElementById('create-token-form').classList.add('hidden')" class="btn btn-ghost">Cancel</button>
                </div>
            </div>

            <div id="tokens-list"></div>
        `);

        document.getElementById('create-token-btn').addEventListener('click', () => {
            document.getElementById('create-token-form').classList.toggle('hidden');
        });

        document.getElementById('token-submit').addEventListener('click', createToken);
        loadTokens();
    }

    async function createToken() {
        const name = document.getElementById('token-name').value.trim();
        const expiry = document.getElementById('token-expiry').value;
        const perms = [];
        if (document.getElementById('perm-upload').checked) perms.push('upload');
        if (document.getElementById('perm-download').checked) perms.push('download');
        if (document.getElementById('perm-metadata').checked) perms.push('metadata');

        if (!name) { toast('Token name is required', 'error'); return; }
        if (!perms.length) { toast('Select at least one permission', 'error'); return; }

        try {
            const res = await api('tokens', {
                method: 'POST',
                body: { name, permissions: perms.join(','), expires_at: expiry || null },
            });

            // Show the raw token in a modal (ONE TIME ONLY)
            const modal = document.getElementById('token-created-modal');
            modal.classList.remove('hidden');
            modal.innerHTML = `
            <div class="modal-overlay" onclick="if(event.target===this){document.getElementById('token-created-modal').classList.add('hidden')}">
                <div class="modal-content glass p-6 space-y-4">
                    <div class="flex items-center gap-3 text-emerald-400">
                        <i data-lucide="check-circle" class="w-6 h-6"></i>
                        <h3 class="text-lg font-bold">Token Created!</h3>
                    </div>
                    <div class="bg-amber-500/10 border border-amber-500/20 rounded-lg p-3 flex items-start gap-2">
                        <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-400 mt-0.5 flex-shrink-0"></i>
                        <p class="text-sm text-amber-300">Copy this token now! It will <strong>never</strong> be shown again.</p>
                    </div>
                    <div class="code-block flex items-center gap-2">
                        <code class="flex-1 break-all text-emerald-400">${esc(res.data.token)}</code>
                        <button onclick="copyText('${esc(res.data.token)}')" class="btn btn-ghost btn-sm flex-shrink-0">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <button onclick="document.getElementById('token-created-modal').classList.add('hidden')" class="btn btn-primary w-full justify-center">
                        I've Saved the Token
                    </button>
                </div>
            </div>`;
            lucide.createIcons({ attrs: { class: 'w-5 h-5' }, nameAttr: 'data-lucide' });

            document.getElementById('create-token-form').classList.add('hidden');
            document.getElementById('token-name').value = '';
            document.getElementById('token-expiry').value = '';
            loadTokens();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function loadTokens() {
        const container = document.getElementById('tokens-list');
        try {
            const res = await api('tokens');
            const tokens = res.data;

            if (!tokens.length) {
                container.innerHTML = `
                    <div class="glass p-12 text-center">
                        <i data-lucide="key-round" class="w-16 h-16 mx-auto mb-4 text-slate-600"></i>
                        <p class="text-lg font-medium text-slate-400">No tokens yet</p>
                        <p class="text-sm text-slate-600 mt-1">Create a token to enable API access</p>
                    </div>`;
                lucide.createIcons({ attrs: { class: 'w-5 h-5' }, nameAttr: 'data-lucide' });
                return;
            }

            container.innerHTML = `
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Token</th>
                            <th>Permissions</th>
                            <th>Status</th>
                            <th>Last Used</th>
                            <th>Created</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tokens.map(t => `
                        <tr class="group">
                            <td class="font-medium text-slate-200">${esc(t.name)}</td>
                            <td><code class="text-xs font-mono text-slate-500 bg-slate-800/50 px-2 py-1 rounded">${esc(t.token_prefix)}</code></td>
                            <td>
                                ${t.permissions.split(',').map(p => `<span class="badge badge-active mr-1 text-[10px]">${esc(p)}</span>`).join('')}
                            </td>
                            <td><span class="badge badge-${t.status}">${t.status}</span></td>
                            <td class="text-xs text-slate-500">${timeAgo(t.last_used_at)}</td>
                            <td class="text-xs text-slate-500">${timeAgo(t.created_at)}</td>
                            <td>
                                <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                    ${t.status === 'active' ? `
                                        <button onclick="revokeToken(${t.id})" class="btn btn-ghost btn-xs text-amber-400">
                                            <i data-lucide="pause" class="w-3 h-3"></i> Revoke
                                        </button>
                                    ` : t.status === 'revoked' ? `
                                        <button onclick="activateToken(${t.id})" class="btn btn-ghost btn-xs text-emerald-400">
                                            <i data-lucide="play" class="w-3 h-3"></i> Activate
                                        </button>
                                    ` : ''}
                                    <button onclick="deleteToken(${t.id}, '${esc(t.name)}')" class="btn btn-ghost btn-xs text-red-400">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>`;
            lucide.createIcons({ attrs: { class: 'w-5 h-5' }, nameAttr: 'data-lucide' });
        } catch (err) {
            container.innerHTML = `<div class="glass p-8 text-center text-red-400"><p>${esc(err.message)}</p></div>`;
        }
    }

    window.revokeToken = async function (id) {
        try { await api(`tokens/${id}/revoke`, { method: 'PUT' }); toast('Token revoked', 'success'); loadTokens(); }
        catch (err) { toast(err.message, 'error'); }
    };
    window.activateToken = async function (id) {
        try { await api(`tokens/${id}/activate`, { method: 'PUT' }); toast('Token activated', 'success'); loadTokens(); }
        catch (err) { toast(err.message, 'error'); }
    };
    window.deleteToken = async function (id, name) {
        if (!confirm(`Delete token "${name}" permanently?`)) return;
        try { await api(`tokens/${id}`, { method: 'DELETE' }); toast('Token deleted', 'success'); loadTokens(); }
        catch (err) { toast(err.message, 'error'); }
    };

    // ════════════════════════════════════
    //  SETTINGS PAGE
    // ════════════════════════════════════
    function renderSettings() {
        renderLayout('settings', `
            <div class="mb-8">
                <h2 class="text-2xl font-bold">Settings</h2>
                <p class="text-slate-500 mt-1">Manage your account settings</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 stagger">
                <!-- Username -->
                <div class="glass glass-hover p-6 space-y-4 animate-slide-up">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-xl bg-indigo-500/10 flex items-center justify-center">
                            <i data-lucide="user" class="w-5 h-5 text-indigo-400"></i>
                        </div>
                        <h3 class="text-lg font-semibold">Change Username</h3>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-2">Current: <strong class="text-slate-200">${esc(state.user?.username)}</strong></label>
                        <input id="new-username" class="input" placeholder="Enter new username">
                    </div>
                    <button id="save-username" class="btn btn-primary w-full justify-center">
                        <i data-lucide="check" class="w-4 h-4"></i> Update Username
                    </button>
                </div>

                <!-- Password -->
                <div class="glass glass-hover p-6 space-y-4 animate-slide-up">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center">
                            <i data-lucide="lock" class="w-5 h-5 text-purple-400"></i>
                        </div>
                        <h3 class="text-lg font-semibold">Change Password</h3>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-2">New Password</label>
                        <input id="new-password" type="password" class="input" placeholder="Enter new password">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-2">Confirm Password</label>
                        <input id="confirm-password" type="password" class="input" placeholder="Confirm new password">
                    </div>
                    <button id="save-password" class="btn btn-primary w-full justify-center">
                        <i data-lucide="check" class="w-4 h-4"></i> Update Password
                    </button>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="glass p-6 mt-6 border border-red-500/10 animate-slide-up">
                <h3 class="text-lg font-semibold text-red-400 flex items-center gap-2">
                    <i data-lucide="alert-triangle" class="w-5 h-5"></i> Session Info
                </h3>
                <p class="text-sm text-slate-500 mt-2">User ID: ${state.user?.id} &nbsp;|&nbsp; Session active since login</p>
            </div>
        `);

        document.getElementById('save-username').addEventListener('click', async () => {
            const val = document.getElementById('new-username').value.trim();
            if (!val) { toast('Enter a username', 'error'); return; }
            try {
                const res = await api('settings/username', { method: 'PUT', body: { new_username: val } });
                state.user.username = res.data.username;
                toast('Username updated!', 'success');
                renderSettings();
            } catch (err) { toast(err.message, 'error'); }
        });

        document.getElementById('save-password').addEventListener('click', async () => {
            const pass = document.getElementById('new-password').value;
            const confirm = document.getElementById('confirm-password').value;
            if (!pass) { toast('Enter a password', 'error'); return; }
            if (pass !== confirm) { toast('Passwords do not match', 'error'); return; }
            try {
                await api('settings/password', { method: 'PUT', body: { new_password: pass } });
                toast('Password updated!', 'success');
                document.getElementById('new-password').value = '';
                document.getElementById('confirm-password').value = '';
            } catch (err) { toast(err.message, 'error'); }
        });
    }

    // ════════════════════════════════════
    //  API DOCS PAGE
    // ════════════════════════════════════
    function renderDocs() {
        // Compute absolute base URL dynamically for docs display
        const baseUrl = new URL(basePath, location.href).href.replace(/\/$/, '');
        renderLayout('docs', `
            <div class="mb-8">
                <h2 class="text-2xl font-bold">API Documentation</h2>
                <p class="text-slate-500 mt-1">Complete guide for integrating with the CDN API via OAuth tokens</p>
            </div>

            <div class="space-y-6 stagger">
                <!-- Auth Section -->
                <div class="glass p-6 animate-slide-up">
                    <h3 class="text-lg font-semibold flex items-center gap-2 mb-4">
                        <i data-lucide="key-round" class="w-5 h-5 text-indigo-400"></i> Authentication
                    </h3>
                    <p class="text-sm text-slate-400 mb-4">All API requests require a Bearer token in the <code class="text-indigo-400 bg-indigo-500/10 px-1.5 py-0.5 rounded">Authorization</code> header.</p>
                    <div class="code-block">
                        <button onclick="copyText('Authorization: Bearer YOUR_TOKEN_HERE')" class="copy-btn btn btn-ghost btn-xs">
                            <i data-lucide="copy" class="w-3 h-3"></i> Copy
                        </button>
                        <pre><span class="text-purple-400">Authorization</span>: Bearer <span class="text-emerald-400">YOUR_TOKEN_HERE</span></pre>
                    </div>
                </div>

                <!-- Upload -->
                <div class="glass p-6 animate-slide-up">
                    <h3 class="text-lg font-semibold flex items-center gap-2 mb-4">
                        <i data-lucide="upload" class="w-5 h-5 text-emerald-400"></i> Upload a File
                    </h3>
                    <p class="text-sm text-slate-400 mb-2"><span class="badge badge-active">POST</span> <code class="text-slate-300 ml-2">${esc(baseUrl)}/api/upload</code></p>
                    <div class="code-block mt-3">
                        <button onclick="copyText(\`curl -X POST '${baseUrl}/api/upload' \\\\\\n  -H 'Authorization: Bearer YOUR_TOKEN' \\\\\\n  -F 'file=@/path/to/file.png'\`)" class="copy-btn btn btn-ghost btn-xs">
                            <i data-lucide="copy" class="w-3 h-3"></i> Copy
                        </button>
                        <pre><span class="text-amber-400">curl</span> -X POST <span class="text-emerald-400">'${esc(baseUrl)}/api/upload'</span> \\
  -H <span class="text-purple-400">'Authorization: Bearer YOUR_TOKEN'</span> \\
  -F <span class="text-blue-400">'file=@/path/to/file.png'</span></pre>
                    </div>
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm text-indigo-400 hover:text-indigo-300 transition-colors">View Response Example</summary>
                        <div class="code-block mt-2">
                            <pre><span class="text-slate-500">// 200 OK</span>
{
  <span class="text-blue-400">"success"</span>: <span class="text-emerald-400">true</span>,
  <span class="text-blue-400">"data"</span>: {
    <span class="text-blue-400">"id"</span>: <span class="text-amber-400">42</span>,
    <span class="text-blue-400">"original_name"</span>: <span class="text-emerald-400">"file.png"</span>,
    <span class="text-blue-400">"sha256_hash"</span>: <span class="text-emerald-400">"a1b2c3..."</span>,
    <span class="text-blue-400">"size"</span>: <span class="text-amber-400">102400</span>,
    <span class="text-blue-400">"metadata_url"</span>: <span class="text-emerald-400">"/files/metadata/file.png"</span>,
    <span class="text-blue-400">"download_url"</span>: <span class="text-emerald-400">"/files/download/file.png"</span>
  }
}</pre>
                        </div>
                    </details>
                </div>

                <!-- Metadata -->
                <div class="glass p-6 animate-slide-up">
                    <h3 class="text-lg font-semibold flex items-center gap-2 mb-4">
                        <i data-lucide="file-json" class="w-5 h-5 text-blue-400"></i> Get File Metadata
                    </h3>
                    <p class="text-sm text-slate-400 mb-2"><span class="badge badge-active">GET</span> <code class="text-slate-300 ml-2">${esc(baseUrl)}/files/metadata/{fileName}</code></p>
                    <div class="code-block mt-3">
                        <button onclick="copyText(\`curl '${baseUrl}/files/metadata/example.png'\`)" class="copy-btn btn btn-ghost btn-xs">
                            <i data-lucide="copy" class="w-3 h-3"></i> Copy
                        </button>
                        <pre><span class="text-amber-400">curl</span> <span class="text-emerald-400">'${esc(baseUrl)}/files/metadata/example.png'</span></pre>
                    </div>
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm text-indigo-400 hover:text-indigo-300 transition-colors">View Response Fields</summary>
                        <div class="mt-2 text-sm text-slate-400 space-y-1">
                            <p><code class="text-slate-300">original_name</code> – Original file name</p>
                            <p><code class="text-slate-300">mime_type</code> – Detected MIME type</p>
                            <p><code class="text-slate-300">size</code> – Size in bytes</p>
                            <p><code class="text-slate-300">size_human</code> – Human-readable size</p>
                            <p><code class="text-slate-300">sha256_hash</code> – SHA-256 checksum</p>
                            <p><code class="text-slate-300">extension</code> – File extension</p>
                            <p><code class="text-slate-300">download_count</code> – Number of downloads</p>
                            <p><code class="text-slate-300">version</code> – File version number</p>
                            <p><code class="text-slate-300">uploaded_by</code> – Uploader username</p>
                            <p><code class="text-slate-300">created_at</code> – First upload timestamp</p>
                            <p><code class="text-slate-300">updated_at</code> – Last update timestamp</p>
                        </div>
                    </details>
                </div>

                <!-- Download -->
                <div class="glass p-6 animate-slide-up">
                    <h3 class="text-lg font-semibold flex items-center gap-2 mb-4">
                        <i data-lucide="download" class="w-5 h-5 text-amber-400"></i> Download a File
                    </h3>
                    <p class="text-sm text-slate-400 mb-2"><span class="badge badge-active">GET</span> <code class="text-slate-300 ml-2">${esc(baseUrl)}/files/download/{fileName}</code></p>
                    <div class="code-block mt-3">
                        <button onclick="copyText(\`curl -O '${baseUrl}/files/download/example.png'\`)" class="copy-btn btn btn-ghost btn-xs">
                            <i data-lucide="copy" class="w-3 h-3"></i> Copy
                        </button>
                        <pre><span class="text-amber-400">curl</span> -O <span class="text-emerald-400">'${esc(baseUrl)}/files/download/example.png'</span></pre>
                    </div>
                </div>

                <!-- Error Codes -->
                <div class="glass p-6 animate-slide-up">
                    <h3 class="text-lg font-semibold flex items-center gap-2 mb-4">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-red-400"></i> Error Codes
                    </h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr><th>Code</th><th>Meaning</th></tr>
                            </thead>
                            <tbody>
                                <tr><td class="font-mono">400</td><td>Bad request / validation error</td></tr>
                                <tr><td class="font-mono">401</td><td>Invalid or missing token</td></tr>
                                <tr><td class="font-mono">403</td><td>Insufficient permissions</td></tr>
                                <tr><td class="font-mono">404</td><td>Resource not found</td></tr>
                                <tr><td class="font-mono">405</td><td>Method not allowed</td></tr>
                                <tr><td class="font-mono">429</td><td>Rate limit exceeded</td></tr>
                                <tr><td class="font-mono">500</td><td>Internal server error</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Setup for AI Agents -->
                <div class="glass p-6 animate-slide-up border border-indigo-500/10">
                    <h3 class="text-lg font-semibold flex items-center gap-2 mb-1">
                        <i data-lucide="bot" class="w-5 h-5 text-indigo-400"></i> Quick Setup for AI Agents
                    </h3>
                    <p class="text-sm text-slate-400 mb-4">Send this block to your AI agent. It can also call <code class="text-indigo-300 bg-indigo-500/10 px-1 rounded">/api/docs</code> with its token to self-discover everything:</p>
                    <div class="code-block">
                        <button onclick="copyText(document.getElementById('agent-doc').textContent)" class="copy-btn btn btn-ghost btn-xs tip" data-tooltip="Copy all to clipboard">
                            <i data-lucide="copy" class="w-3 h-3"></i> Copy All
                        </button>
                        <pre id="agent-doc"><span class="text-slate-500"># ── CDN Panel API – Quick Setup ──────────────────────────────</span>
<span class="text-slate-500"># Base URL:</span> <span class="text-emerald-400">${esc(baseUrl)}</span>

<span class="text-slate-500"># ── STEP 1: Fetch full self-documenting API (AI agents: do this first!)</span>
<span class="text-slate-500"># Returns JSON with every endpoint, params, examples, error codes.</span>
curl '${esc(baseUrl)}/api/docs' \
  -H 'Authorization: Bearer YOUR_TOKEN'
<span class="text-slate-400"># Response: { "success": true, "data": { "endpoints": [...], "agent_patterns": {...} } }</span>

<span class="text-slate-500"># ── STEP 2: Upload a file (requires "upload" permission on token)</span>
curl -X POST '${esc(baseUrl)}/api/upload' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -F 'file=@/path/to/model.onnx'
<span class="text-slate-400"># Response:
# { "success": true, "data": {
#     "original_name": "model.onnx",
#     "sha256_hash":   "a1b2c3d4...",
#     "size":          5242880,
#     "metadata_url":  "files/metadata/model.onnx",
#     "download_url":  "files/download/model.onnx"
#   }
# }</span>

<span class="text-slate-500"># ── STEP 3: Get metadata (public – no token needed)</span>
curl '${esc(baseUrl)}/files/metadata/model.onnx'

<span class="text-slate-500"># ── STEP 4: Download the file (public – no token needed)</span>
curl -O '${esc(baseUrl)}/files/download/model.onnx'

<span class="text-slate-500"># ── Supported types ──────────────────────────────────────────</span>
<span class="text-slate-500"># Archives:    zip, rar, 7z, gz, tar, bz2</span>
<span class="text-slate-500"># Executables: exe, dll, msi, sys</span>
<span class="text-slate-500"># ML Models:   pt, onnx, pkl, safetensors, pth, ckpt, h5, pb</span>
<span class="text-slate-500"># Media:       jpg, png, gif, webp, mp4, webm, mp3, wav, pdf</span>
<span class="text-slate-500"># Other:       wasm, bin, dat, iso, ttf, woff, json, csv ...</span>
<span class="text-slate-500"># Max size:    100 MB per file</span></pre>
                    </div>
                </div>
            </div>
        `);
    }

    // ─── Logout ───
    window.doLogout = async function () {
        try {
            await api('auth/logout', { method: 'POST' });
        } catch { /* ignore */ }
        state.user = null;
        state.csrfToken = '';
        navigate('login');
        toast('Signed out', 'info');
    };

    // ─── .htaccess upload (send CSRF via header) ───
    // Override FormData fetch to include CSRF
    const origApi = api;

    // ─── Init ───
    router();
})();
