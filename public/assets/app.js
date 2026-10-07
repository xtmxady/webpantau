'use strict';

// A small browser client; PHP owns authentication, persistence, and Telegram access.
const state = { auth: null, services: [], telegram: null, page: 'overview', filter: 'all', query: '' };
const root = document.querySelector('#app');
const dialogRoot = document.querySelector('#dialog-root');
const toastRoot = document.querySelector('#toast-root');
let toastTimer;
let previousFocus;
let pendingImport = null;
let importingBackup = false;

const paths = {
    layers: '<path d="m12 3 10 5-10 5L2 8Z"/><path d="m2 12 10 5 10-5M2 16l10 5 10-5"/>',
    overview: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    domain: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/>',
    server: '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/>',
    telegram: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    search: '<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>',
    arrow: '<path d="M7 17 17 7M7 7h10v10"/>',
    edit: '<path d="m15 5 4 4M4 20l4-1L20 7a3 3 0 0 0-4-4L4 15Z"/>',
    trash: '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    logout: '<path d="M10 4H4v16h6M9 12h12m-4-4 4 4-4 4"/>',
    shield: '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z"/><path d="m8 12 3 3 5-6"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    backup: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
    download: '<path d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5"/>',
    upload: '<path d="M12 15V3m-4 4 4-4 4 4M4 16v5h16v-5"/>',
};
const icon = name => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.layers}</svg>`;
const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value));
const formatDate = value => new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(value + 'T00:00:00Z'));
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const daysLeft = value => Math.round((Date.parse(value + 'T00:00:00Z') - Date.parse(today() + 'T00:00:00Z')) / 86400000);

const cyclePricing = value => {
    const amount = Number(value);
    const base = Number.isFinite(amount) && amount >= 0 ? amount : 0;
    const tax = Math.round(base * 0.11);
    return {base, tax, total: base + tax};
};

function priceBreakdown(value, cycle) {
    const price = cyclePricing(value);
    const unit = cycle === 'monthly' ? 'bulan' : 'tahun';
    return `<div><span>Biaya sebelum PPN / ${unit}</span><strong>${money(price.base)}</strong></div>
        <div><span>PPN 11%</span><strong>${money(price.tax)}</strong></div>
        <div class="price-total"><span>Total bayar / ${unit}</span><strong>${money(price.total)}</strong></div>`;
}

function updatePriceBreakdown() {
    const form = document.querySelector('#service-form');
    if (form) document.querySelector('#price-breakdown').innerHTML = priceBreakdown(form.elements.cost.value, form.elements.cycle.value);
}


async function api(path, method = 'GET', body = {}) {
    const response = await fetch('/api' + path, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.auth?.csrf || '' },
        body: method === 'GET' ? undefined : JSON.stringify(body),
    });
    let result;
    try {
        result = await response.json();
    } catch {
        throw new Error(response.status === 413
            ? 'File terlalu besar. Batas impor 5 MB; pastikan batas upload Nginx sudah diperbarui.'
            : 'Respons server tidak dapat dibaca. Periksa koneksi lalu coba lagi.');
    }
    if (!response.ok) throw new Error(result.error || 'Permintaan gagal.');
    return result;
}

function notify(message, error = false) {
    clearTimeout(toastTimer);
    toastRoot.innerHTML = `<div class="toast ${error ? 'error' : ''}" role="status"><span>${escape(message)}</span><button aria-label="Tutup pesan" data-action="dismiss">${icon('close')}</button></div>`;
    toastTimer = setTimeout(() => { toastRoot.innerHTML = ''; }, 6000);
}

async function pending(button, work) {
    if (button?.disabled) return;
    if (button) button.disabled = true;
    try { await work(); } catch (error) { notify(error.message, true); }
    finally { if (button) button.disabled = false; }
}

function logo() {
    return `<div class="logo"><span>${icon('layers')}</span>webpantau<span class="logo-period">.</span></div>`;
}

async function load() {
    state.auth = await api('/auth');
    if (state.auth.authenticated) {
        [state.services, state.telegram] = await Promise.all([api('/services'), api('/telegram')]);
    }
    render();
}

function renderLogin() {
    const initial = !state.auth.initialized;
    root.innerHTML = `<div class="login">
        <div class="login-story">${logo()}<div><span class="eyebrow">RUANG KERJA PRIBADI</span>
        <h1>Semua website.<br>Tetap terpantau.</h1><p>Satu tempat untuk mencatat masa aktif domain dan server. Supaya tidak ada perpanjangan yang terlewat.</p>
        <div class="login-note">${icon('clock')} Lebih sedikit yang perlu diingat.</div></div><small>WEBPANTAU / DOMAIN & INFRASTRUCTURE</small></div>
        <div class="login-form"><div class="w-full max-w-sm"><div class="section-icon">${icon('shield')}</div>
        <h2>${initial ? 'Buat ruang kerja kamu' : 'Selamat datang kembali'}</h2>
        <p class="muted mt-2 mb-8">${initial ? 'Daftarkan akun pemilik untuk memulai. Akun ini hanya dibuat sekali.' : 'Masuk untuk melihat layanan dan pengingat.'}</p>
        <form id="login-form"><label class="field"><span>Nama pengguna</span><input name="username" minlength="3" maxlength="60" autocomplete="username" required placeholder="Nama pengguna"></label>
        <label class="field"><span>Kata sandi</span><input type="password" name="password" minlength="12" maxlength="256" autocomplete="${initial ? 'new-password' : 'current-password'}" required placeholder="Minimal 12 karakter"></label>
        ${initial ? '<label class="field"><span>Kunci pendaftaran (jika diatur)</span><input name="setupKey" type="password" autocomplete="off" placeholder="Dari konfigurasi VPS"></label>' : ''}
        <button class="btn primary" type="submit">${initial ? 'Buat akun & mulai' : 'Masuk ke dashboard'} ${icon('arrow')}</button></form>
        <p class="field-hint">Akses pribadi. Data tersimpan di server kamu.</p></div></div></div>`;
}

const navigation = [['overview', 'Ringkasan'], ['domain', 'Domain'], ['server', 'Server & hosting'], ['telegram', 'Telegram'], ['backup', 'Backup & Import']];
function render() {
    if (!state.auth.authenticated) return renderLogin();
    const label = navigation.find(([key]) => key === state.page)[1];
    const initial = escape(state.auth.username.slice(0, 1).toUpperCase());
    root.innerHTML = `<div class="app-shell"><aside class="sidebar">${logo()}
        <div class="workspace-tag"><span class="workspace-icon">${initial}</span><div><strong>Workspace pribadi</strong><small>${escape(state.auth.username)}</small></div></div>
        <span class="nav-label">WORKSPACE</span><nav>${navigation.map(([key, title]) => `<button data-page="${key}" class="${state.page === key ? 'active' : ''}">${icon(key)}${title}${key === 'domain' ? `<span class="nav-count">${state.services.filter(s => s.type === 'domain').length}</span>` : ''}</button>`).join('')}</nav>
        <div class="sidebar-bottom"><div class="telegram-note">${icon('telegram')}<strong>Pengingat, langsung ke chat.</strong><p>Hubungkan bot agar tanggal penting tidak terlewat.</p><button data-page="telegram">Atur Telegram ${icon('arrow')}</button></div><button class="logout" data-action="logout">${icon('logout')} Keluar</button></div>
        </aside><div class="main-wrap"><header class="topbar"><button class="mobile-menu" data-action="menu" aria-label="Buka navigasi">${icon('menu')}</button><span class="text-stone-400">Workspace <span class="mx-2">/</span><span class="text-stone-700">${label}</span></span><div class="row-flex"><span class="topbar-date text-xs text-stone-500">${new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Makassar' }).format(new Date())}</span><span class="avatar">${initial}</span></div></header>
        <main id="content"></main></div></div>`;
    renderPage();
}

function renderPage() {
    const titles = { overview: 'Semua dalam kendali.', domain: 'Domain website.', server: 'Server & hosting.', telegram: 'Pengingat Telegram.', backup: 'Data aman, siap dipindahkan.' };
    const telegram = state.page === 'telegram';
    const backup = state.page === 'backup';
    const description = backup ? 'Unduh salinan data atau pulihkan backup saat pindah VPS.' : telegram ? 'Kirim pengingat ke chat pribadi atau grup kerja kamu.' : 'Pantau masa aktif, rencanakan perpanjangan, lanjutkan pekerjaan.';
    document.querySelector('#content').innerHTML = `<div class="page-heading"><div><div class="eyebrow mb-3">${backup ? 'BACKUP & PEMULIHAN' : telegram ? 'KONEKSI & PENGINGAT' : 'DOMAIN & INFRASTRUCTURE'}</div><h1>${titles[state.page]}</h1><p class="muted mt-2">${description}</p></div>${telegram || backup ? '' : `<button class="btn primary" data-action="add">${icon('plus')} Tambah layanan</button>`}</div>${backup ? backupView() : telegram ? telegramView() : dashboardView()}`;
    if (!telegram && !backup) renderRows();
}

function dashboardView() {
    const services = state.services;
    const urgent = services.filter(s => daysLeft(s.expires) <= 30);
    const expired = services.filter(s => daysLeft(s.expires) < 0);
    const stats = [
        ['layers', 'Total layanan', services.length, 'Domain & server yang dicatat'],
        ['clock', 'Perlu diperpanjang', urgent.length, 'Jatuh tempo dalam 30 hari'],
        ['domain', 'Domain aktif', services.filter(s => s.type === 'domain' && daysLeft(s.expires) >= 0).length, 'Masih dalam masa aktif'],
        ['server', 'Server & hosting', services.filter(s => s.type === 'server').length, 'Bulanan & tahunan'],
    ];
    return `<div class="stats-grid">${stats.map(([type, label, count, sub]) => `<div class="stat"><div class="stat-heading"><span>${label}</span>${icon(type)}</div><strong>${String(count).padStart(2, '0')}</strong><small>${sub}</small></div>`).join('')}</div>
        ${state.page === 'overview' ? `<section class="attention"><div class="attention-icon">${icon('clock')}</div><div class="attention-body"><h3>${urgent.length ? `${urgent.length} layanan perlu perhatian` : 'Tanggal penting sudah punya tempat.'}</h3><p>${expired.length ? `${expired.length} layanan melewati jatuh tempo. Periksa status dan perbarui tanggalnya.` : urgent.length ? 'Cek layanan yang mendekati jatuh tempo dan siapkan perpanjangannya.' : 'Tambahkan layanan kamu, lalu hubungkan Telegram untuk pengingat otomatis.'}</p></div><button ${urgent.length ? 'data-filter="urgent"' : 'data-action="add"'}>${urgent.length ? 'Lihat layanan' : 'Mulai mencatat'} ${icon('arrow')}</button></section>` : ''}
        <section class="list-panel"><div class="list-heading"><div><h2>${state.page === 'overview' ? 'Daftar layanan' : state.page === 'domain' ? 'Daftar domain' : 'Daftar server & hosting'} <span id="result-count"></span></h2><p class="muted text-xs mt-1">Diurutkan dari tanggal jatuh tempo terdekat.</p></div><div class="search">${icon('search')}<input id="search" aria-label="Cari layanan" placeholder="Cari layanan, klien, atau kontak…" value="${escape(state.query)}"></div></div>
        <div class="tabs">${[['all', 'Semua layanan'], ['urgent', 'Segera jatuh tempo'], ['expired', 'Lewat jatuh tempo']].map(([key, text]) => `<button class="${state.filter === key ? 'selected' : ''}" data-filter="${key}">${text}</button>`).join('')}</div>
        <div class="overflow-x-auto"><table><thead><tr><th>Layanan / klien</th><th>Jenis & penyedia</th><th>Jatuh tempo</th><th>Biaya per siklus</th><th>Status</th><th><span class="sr-only">Tindakan</span></th></tr></thead><tbody id="service-rows"></tbody></table><div id="empty-state"></div></div>
        <div class="list-footer"><span id="footer-count"></span><span class="saved"><span class="dot"></span>Data tersimpan di server</span></div></section><div class="footer-note">WEBPANTAU <span>Ruang kerja pribadi untuk website yang kamu kelola.</span></div>`;
}

function renderRows() {
    const visible = [...state.services].sort((a, b) => a.expires.localeCompare(b.expires)).filter(s =>
        (!['domain', 'server'].includes(state.page) || s.type === state.page)
        && (state.filter === 'all' || (state.filter === 'urgent' ? daysLeft(s.expires) <= 30 : daysLeft(s.expires) < 0))
        && `${s.name} ${s.client} ${s.clientContact || ''} ${s.provider}`.toLowerCase().includes(state.query.toLowerCase()));
    document.querySelector('#service-rows').innerHTML = visible.map(s => {
        const days = daysLeft(s.expires);
        const badge = days < 0 ? 'red' : days <= 7 ? 'amber' : days <= 30 ? 'blue' : 'green';
        const status = days < 0 ? `Lewat ${-days} hari` : days === 0 ? 'Hari ini' : `${days} hari lagi`;
        const price = cyclePricing(s.cost);
        return `<tr><td><div class="service-cell"><span class="service-icon">${icon(s.type)}</span><div><button class="service-name" data-edit="${escape(s.id)}">${escape(s.name)}</button><small class="service-client">${escape(s.client)}</small>${s.clientContact ? `<small class="service-contact">${escape(s.clientContact)}</small>` : ''}</div></div></td><td>${s.type === 'domain' ? 'Domain' : 'Server / hosting'}<small class="service-provider">${escape(s.provider || 'Belum dicatat')}</small></td><td>${formatDate(s.expires)}</td><td><span>${money(price.base)} / ${s.cycle === 'monthly' ? 'bulan' : 'tahun'}</span><small class="tax-line">PPN 11%: ${money(price.tax)}</small><strong class="cycle-total">Total ${money(price.total)}</strong></td><td><span class="badge ${badge}"><span class="dot"></span>${status}</span></td><td><div class="actions"><button class="icon-btn" data-edit="${escape(s.id)}" aria-label="Edit ${escape(s.name)}">${icon('edit')}</button><button class="icon-btn" data-delete="${escape(s.id)}" aria-label="Hapus ${escape(s.name)}">${icon('trash')}</button></div></td></tr>`;
    }).join('');
    document.querySelector('#result-count').textContent = visible.length;
    document.querySelector('#footer-count').textContent = `${visible.length} layanan ditampilkan`;
    document.querySelector('#empty-state').innerHTML = visible.length ? '' : `<div class="empty">${icon('layers')}<h3>${state.services.length ? 'Tidak ada layanan yang cocok' : 'Mulai dari website pertamamu'}</h3><p>${state.services.length ? 'Coba kata pencarian atau filter lain.' : 'Catat domain atau server beserta tanggal jatuh temponya.'}</p>${state.services.length ? '' : `<button class="btn secondary mt-5" data-action="add">${icon('plus')} Tambah layanan pertama</button>`}</div>`;
}

function telegramView() {
    const cfg = state.telegram;
    return `<div class="telegram-grid"><section class="settings-panel"><div class="section-icon">${icon('telegram')}</div><h2>Koneksi bot</h2><p class="muted text-sm mt-2 mb-7">Gunakan bot milikmu sendiri. Cron di server menjalankan pemeriksaan pengingat.</p>
        <form id="telegram-form"><label class="field"><span>Token bot</span><input name="token" type="password" autocomplete="new-password" placeholder="${cfg.hasToken ? 'Token tersimpan • isi untuk mengganti' : '123456789:AA…'}"></label><p class="field-hint">${cfg.hasToken ? 'Token tersimpan di server dan tidak ditampilkan kembali.' : 'Buat bot melalui @BotFather, lalu salin tokennya ke sini.'}</p>
        <label class="field"><span>Chat ID</span><input name="chatId" value="${escape(cfg.chatId)}" placeholder="contoh: 123456789 atau -100…"></label>
        <div class="field"><span>Ingatkan sebelum jatuh tempo</span><div class="day-options">${[...new Set([30, 14, 7, 3, 1, 0, ...cfg.days])].sort((a, b) => b - a).map(d => `<label class="day-chip ${cfg.days.includes(d) ? 'chosen' : ''}"><input type="checkbox" name="days" value="${d}" ${cfg.days.includes(d) ? 'checked' : ''}>${d === 0 ? 'Hari H' : `H-${d}`}</label>`).join('')}</div></div>
        <label class="toggle-row"><div><strong>Pengingat otomatis</strong><p class="muted text-xs mt-1">Jadwal dijalankan oleh cron di VPS.</p></div><input name="enabled" type="checkbox" ${cfg.enabled ? 'checked' : ''}></label>
        <div class="form-actions start"><button class="btn primary" type="submit">Simpan pengaturan</button><button class="btn secondary" type="button" data-action="telegram-test" ${cfg.hasToken ? '' : 'disabled'}>${icon('telegram')} Uji pengiriman</button></div><p class="field-hint mt-3">Simpan perubahan sebelum mengirim pesan uji.</p></form></section>
        <div><section class="guide-panel"><span class="eyebrow">PANDUAN SINGKAT</span><h2 class="mt-3 mb-6">Hubungkan dalam 3 langkah.</h2>
        ${[['01', 'Buat bot Telegram', 'Buka @BotFather di Telegram, kirim /newbot, lalu ikuti petunjuk untuk mendapatkan token.'], ['02', 'Tentukan tujuan pesan', 'Kirim /start ke bot kamu. Dapatkan chat ID melalui getUpdates di Telegram Bot API. Untuk grup, tambahkan bot dan gunakan ID grup.'], ['03', 'Simpan & kirim pesan uji', 'Masukkan token dan chat ID, pilih jadwal, lalu simpan. Kirim pesan uji untuk memastikan koneksi.']].map(([n, title, text]) => `<div class="guide-step"><span>${n}</span><div><h3>${title}</h3><p>${text}</p></div></div>`).join('')}</section>
        <div class="privacy-note">${icon('shield')}<p>Token hanya digunakan di sisi server. File data mengandung token, jadi jaga akses server dan cadangannya. Pengingat otomatis memerlukan cron aktif.</p></div></div></div>`;
}

function backupView() {
    return `<div class="backup-grid">
        <section class="settings-panel"><div class="section-icon">${icon('download')}</div><h2>Unduh backup</h2>
        <p class="muted mt-2">Salinan seluruh layanan, kontak klien, tanggal, biaya, catatan, dan riwayat pengingat dalam satu file JSON.</p>
        <div class="backup-count"><strong>${state.services.length}</strong><span>layanan siap dicadangkan</span></div>
        <label class="toggle-row"><div><strong>Sertakan pengaturan Telegram</strong><p class="muted text-xs mt-1">Termasuk token bot dan chat ID. Simpan file ini secara privat.</p></div><input id="backup-telegram" type="checkbox"></label>
        <div class="form-actions start"><button class="btn primary" data-action="backup-download">${icon('download')} Unduh backup JSON</button></div>
        <p class="backup-note">Akun login dan kata sandi tidak disertakan. Kamu tetap memakai akun yang dibuat di VPS tujuan.</p></section>
        <section class="settings-panel"><div class="section-icon">${icon('upload')}</div><h2>Impor backup</h2>
        <p class="muted mt-2 mb-7">Pilih file backup Webpantau. Periksa ringkasannya sebelum mengganti data di dashboard ini.</p>
        <form id="backup-form"><label class="field"><span>File backup JSON</span><input name="backupFile" type="file" accept=".json,application/json" required></label>
        <p class="field-hint">Maksimal 5 MB. Gunakan file dari tombol Unduh backup JSON.</p>
        <div class="form-actions start"><button class="btn secondary" type="submit">${icon('upload')} Periksa file backup</button></div></form>
        <p class="backup-note">Impor mengganti daftar layanan dan riwayat pengingat. Sebelum penggantian, salinan data saat ini dibuat otomatis di server.</p></section>
        <section class="guide-panel backup-guide"><span class="eyebrow">PINDAH VPS</span><h2 class="mt-3 mb-6">Bawa data dalam tiga langkah.</h2>
        ${[['01', 'Unduh di VPS lama', 'Unduh backup. Centang pengaturan Telegram jika token dan chat ID juga ingin dipindahkan.'], ['02', 'Pulihkan di VPS baru', 'Pasang Webpantau, buat akun, lalu masuk ke Backup & Import. Pilih file, periksa ringkasan, dan konfirmasi impor.'], ['03', 'Aktifkan pengingat di satu VPS', 'Hentikan cron VPS lama, pasang cron di VPS baru, lalu aktifkan pengingat Telegram. Pengingat yang dipulihkan dari backup dimatikan terlebih dahulu.']].map(([n, title, text]) => `<div class="guide-step"><span>${n}</span><div><h3>${title}</h3><p>${text}</p></div></div>`).join('')}</section>
        </div>`;
}

async function downloadBackup() {
    const includeTelegram = document.querySelector('#backup-telegram').checked;
    const backup = await api('/backup/export' + (includeTelegram ? '?includeTelegram=1' : ''));
    const url = URL.createObjectURL(new Blob([JSON.stringify(backup)], {type: 'application/json'}));
    const link = document.createElement('a');
    link.href = url;
    link.download = `webpantau-backup-${today()}.json`;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    notify('File backup siap diunduh. Simpan di tempat yang aman.');
}

async function inspectBackup(form) {
    pendingImport = null;
    const file = form.elements.backupFile.files[0];
    if (!file) throw new Error('Pilih file backup terlebih dahulu.');
    if (file.size > 5 * 1024 * 1024) throw new Error('File backup maksimal 5 MB.');
    let backup;
    try { backup = JSON.parse(await file.text()); }
    catch { throw new Error('File tidak berisi JSON yang valid. Pilih file backup Webpantau.'); }
    const summary = await api('/backup/validate', 'POST', {backup});
    if (state.page !== 'backup') return;
    pendingImport = summary;
    const exported = new Date(summary.exportedAt);
    const exportedLabel = Number.isNaN(exported.getTime()) ? 'Tidak diketahui' : new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Makassar'
    }).format(exported);
    openDialog(`<div class="row-flex mb-6"><h2 id="dialog-title">Periksa sebelum impor</h2><button data-action="close" aria-label="Tutup">${icon('close')}</button></div>
        <dl class="backup-summary"><div><dt>File</dt><dd>${escape(file.name)}</dd></div><div><dt>Dibuat</dt><dd>${escape(exportedLabel)} WITA</dd></div>
        <div><dt>Isi backup</dt><dd>${summary.serviceCount} layanan</dd></div><div><dt>Telegram</dt><dd>${summary.includesTelegram ? 'Pengaturan & token disertakan' : 'Pengaturan saat ini tetap digunakan'}</dd></div></dl>
        <p class="backup-warning">${summary.currentServiceCount} layanan saat ini akan diganti dengan ${summary.serviceCount} layanan dari backup. Akun login tetap sama. Salinan pemulihan dibuat sebelum perubahan.</p>
        ${summary.includesTelegram ? '<p class="backup-note">Pengingat Telegram dimatikan setelah impor. Aktifkan kembali di VPS baru setelah cron lama dihentikan.</p>' : ''}
        <div class="form-actions"><button class="btn secondary" data-action="close">Batal</button><button class="btn primary" data-action="backup-import">Ganti data & impor</button></div>`);
}

async function importBackup() {
    if (!pendingImport) throw new Error('Periksa file backup terlebih dahulu.');
    importingBackup = true;
    let result;
    try {
        result = await api('/backup/import', 'POST', {importId: pendingImport.importId, confirm: true});
    } catch (error) {
        pendingImport = null;
        closeDialog();
        throw error;
    } finally {
        importingBackup = false;
    }
    pendingImport = null;
    closeDialog();
    try { await load(); }
    catch { throw new Error('Impor berhasil. Muat ulang halaman untuk melihat data yang dipulihkan.'); }
    const message = `${result.serviceCount} layanan berhasil diimpor.${result.telegramDisabled ? ' Aktifkan kembali pengingat di menu Telegram jika sudah siap.' : ''}`;
    notify(message);
    const status = document.createElement('p');
    status.className = 'backup-result';
    status.setAttribute('role', 'status');
    status.textContent = `Salinan data sebelumnya disimpan di data/backups/${result.recoveryBackup}.`;
    document.querySelector('.backup-grid')?.prepend(status);
}

function openDialog(content) {
    previousFocus = document.activeElement;
    dialogRoot.innerHTML = `<div class="modal-backdrop"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="dialog-title">${content}</section></div>`;
    document.body.style.overflow = 'hidden';
    dialogRoot.querySelector('input, button')?.focus();
}
function closeDialog() {
    dialogRoot.innerHTML = '';
    document.body.style.overflow = '';
    previousFocus?.focus();
}

function editService(service = null) {
    const s = service || { name: '', client: '', clientContact: '', website: '', provider: '', type: state.page === 'server' ? 'server' : 'domain', cycle: state.page === 'server' ? 'monthly' : 'yearly', expires: '', cost: '', notes: '' };
    const field = (key, label, placeholder, type = 'text', required = false) => `<label class="field"><span>${label}</span><input name="${key}" type="${type}" value="${escape(s[key])}" placeholder="${placeholder}" ${required ? 'required' : ''} ${type === 'number' ? 'min="0" step="1"' : ''} maxlength="${key === 'clientContact' ? 200 : ['name', 'client'].includes(key) ? 120 : 2000}"></label>`;
    openDialog(`<div class="row-flex mb-6"><div><h2 id="dialog-title">${s.id ? 'Detail & perpanjangan' : 'Tambah layanan'}</h2><p class="muted text-sm mt-1">${s.id ? 'Setelah diperpanjang, ubah tanggal jatuh tempo di sini.' : 'Catat satu domain atau paket server.'}</p></div><button data-action="close" aria-label="Tutup">${icon('close')}</button></div>
        <form id="service-form" data-id="${escape(s.id || '')}"><div class="form-grid">
        ${field('name', 'Nama layanan', 'contoh: tokokita.com', 'text', true)}${field('client', 'Nama klien', 'contoh: Toko Kita', 'text', true)}${field('clientContact', 'Kontak klien (opsional)', 'Nomor WhatsApp, telepon, atau email')}${field('website', 'URL website', 'https://tokokita.com')}${field('provider', 'Penyedia', 'contoh: Niagahoster')}
        <label class="field"><span>Jenis layanan</span><select name="type"><option value="domain" ${s.type === 'domain' ? 'selected' : ''}>Domain</option><option value="server" ${s.type === 'server' ? 'selected' : ''}>Server / hosting</option></select></label>
        <label class="field"><span>Siklus pembayaran</span><select name="cycle"><option value="yearly" ${s.cycle === 'yearly' ? 'selected' : ''}>Tahunan</option><option value="monthly" ${s.cycle === 'monthly' ? 'selected' : ''}>Bulanan</option></select></label>
        ${field('expires', 'Tanggal jatuh tempo', '', 'date', true)}
        <div class="cost-field-group">${field('cost', 'Biaya per siklus (Rp)', '150000', 'number', true)}
        <p class="field-hint">Masukkan biaya sebelum PPN. Pajak 11% dihitung otomatis.</p>
        <div id="price-breakdown" class="price-breakdown" aria-live="polite">${priceBreakdown(s.cost, s.cycle)}</div></div></div>
        <label class="field"><span>Catatan</span><textarea name="notes" maxlength="2000" placeholder="Siapa yang membayar, atau catatan perpanjangan…">${escape(s.notes)}</textarea></label>
        <div class="form-actions"><button type="button" class="btn secondary" data-action="close">Batal</button><button type="submit" class="btn primary">Simpan layanan</button></div></form>`);
}

async function refreshServices() {
    state.services = await api('/services');
    closeDialog();
    render();
}

document.addEventListener('submit', event => {
    const form = event.target;
    if (!['login-form', 'service-form', 'telegram-form', 'backup-form'].includes(form.id)) return;
    event.preventDefault();
    pending(form.querySelector('[type="submit"]'), async () => {
        const fields = new FormData(form);
        const values = Object.fromEntries(fields);
        if (form.id === 'backup-form') {
            await inspectBackup(form);
        } else if (form.id === 'login-form') {
            await api('/auth/' + (state.auth.initialized ? 'login' : 'setup'), 'POST', values);
            await load();
        } else if (form.id === 'service-form') {
            await api('/services' + (form.dataset.id ? '/' + form.dataset.id : ''), form.dataset.id ? 'PUT' : 'POST', values);
            await refreshServices();
            notify('Layanan berhasil disimpan.');
        } else {
            await api('/telegram', 'PUT', { token: values.token, chatId: values.chatId, enabled: fields.has('enabled'), days: fields.getAll('days').map(Number) });
            state.telegram = await api('/telegram');
            renderPage();
            notify('Pengaturan Telegram tersimpan.');
        }
    });
});

document.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button || button.disabled) return;
    if (button.dataset.page) {
        pendingImport = null;
        state.page = button.dataset.page;
        state.filter = 'all';
        state.query = '';
        render();
    } else if (button.dataset.filter) {
        state.filter = button.dataset.filter;
        renderPage();
    } else if (button.dataset.edit) {
        editService(state.services.find(s => s.id === button.dataset.edit));
    } else if (button.dataset.delete) {
        const s = state.services.find(s => s.id === button.dataset.delete);
        openDialog(`<h2 id="dialog-title">Hapus ${escape(s.name)}?</h2><p class="muted mt-3 mb-6">Catatan layanan ini akan dihapus. Domain atau server aslinya tidak terpengaruh.</p><div class="form-actions"><button class="btn secondary" data-action="close">Batal</button><button class="btn danger" data-confirm-delete="${escape(s.id)}">Hapus</button></div>`);
    } else if (button.dataset.confirmDelete) {
        pending(button, async () => { await api('/services/' + button.dataset.confirmDelete, 'DELETE'); await refreshServices(); notify('Layanan dihapus.'); });
    } else {
        switch (button.dataset.action) {
            case 'add': editService(); break;
            case 'close': if (!importingBackup) { pendingImport = null; closeDialog(); } break;
            case 'dismiss': toastRoot.innerHTML = ''; break;
            case 'menu': document.querySelector('.sidebar').classList.toggle('open'); break;
            case 'logout': pending(button, async () => { await api('/auth/logout', 'POST'); await load(); }); break;
            case 'telegram-test': pending(button, async () => { await api('/telegram/test', 'POST'); notify('Pesan uji berhasil dikirim.'); }); break;
            case 'backup-download': pending(button, downloadBackup); break;
            case 'backup-import': pending(button, importBackup); break;
        }
    }
});

document.addEventListener('input', event => {
    if (event.target.name === 'cost' && event.target.closest('#service-form')) updatePriceBreakdown();
    if (event.target.id === 'search') {
        state.query = event.target.value;
        renderRows();
    }
});
document.addEventListener('change', event => {
    if (event.target.name === 'cycle' && event.target.closest('#service-form')) updatePriceBreakdown();
    if (event.target.name === 'days') event.target.closest('label').classList.toggle('chosen', event.target.checked);
});
document.addEventListener('keydown', event => {
    if (!dialogRoot.firstChild) return;
    if (event.key === 'Escape' && !importingBackup) { pendingImport = null; closeDialog(); }
    if (event.key === 'Tab') {
        const elements = [...dialogRoot.querySelectorAll('button, input, select, textarea')].filter(el => !el.disabled);
        const first = elements[0], last = elements.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
load().catch(error => {
    root.innerHTML = '<div class="loading">Dashboard belum dapat dimuat. Periksa koneksi lalu muat ulang halaman.</div>';
    notify(error.message, true);
});
