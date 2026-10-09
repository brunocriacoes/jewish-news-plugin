/* global MigradorNoticias, Chart */
(function () {
  'use strict';
  let running = false;
  let paused = false;
  let status = MigradorNoticias.initialStatus;
  let statusChart = null;
  const $ = (id) => document.getElementById(id);
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  function log(message, type) {
    const panel = $('migrador-log');
    const row = document.createElement('div');
    const time = new Date().toLocaleTimeString();
    row.textContent = `[${time}] ${message}`;
    if (type) row.className = `is-${type}`;
    panel.prepend(row);
  }

  function buildChart() {
    if (!window.Chart) return;
    statusChart = new Chart($('migrador-status-chart'), {
      type: 'doughnut',
      data: { labels: ['Sucesso', 'Erros', 'Na fila'], datasets: [{ data: [0, 0, 0], backgroundColor: ['#00a32a', '#d63638', '#2271b1'], borderWidth: 0 }] },
      options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false }, tooltip: { padding: 10 } } }
    });
  }

  window.atualizarDashboard = function (sucesso, erros, fila) {
    if (!statusChart) return;
    statusChart.data.datasets[0].data = [Number(sucesso || 0), Number(erros || 0), Number(fila || 0)];
    statusChart.update();
  };

  function render(data) {
    status = data || status;
    const total = Number(status.total || 0);
    const processed = Number(status.processed || 0);
    const percent = total ? Math.round((processed / total) * 100) : 0;
    $('migrador-total').textContent = total;
    $('migrador-queue').textContent = status.queue || 0;
    $('migrador-completed').textContent = status.completed || 0;
    $('migrador-errors').textContent = status.errors || 0;
    $('migrador-errors-action').textContent = status.errors || 0;
    $('migrador-categories-site-total').textContent = status.categories_site_total || 0;
    $('migrador-categories-existing').textContent = status.categories_existing || 0;
    $('migrador-categories-pending').textContent = status.categories_pending || 0;
    $('migrador-categories-existing-footer').textContent = status.categories_existing || 0;
    $('migrador-progress-bar').style.width = `${percent}%`;
    $('migrador-progress-text').textContent = `${processed} de ${total} processados`;
    $('migrador-progress-badge').textContent = `${percent}%`;
    $('migrador-reprocess-errors').disabled = running || !Number(status.errors || 0);
    window.atualizarDashboard(status.completed, status.errors, status.queue);
  }

  async function request(action, extra = {}) {
    const data = new URLSearchParams({ action, nonce: MigradorNoticias.nonce, ...extra });
    const response = await fetch(MigradorNoticias.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data.toString() });
    const json = await response.json();
    if (!json.success) throw { data: json.data || {}, message: (json.data && json.data.message) || 'Falha inesperada.' };
    return json.data;
  }

  function updateButtons() {
    $('migrador-start').disabled = running && !paused;
    $('migrador-pause').disabled = !running;
    $('migrador-pause').textContent = paused ? 'Retomar' : 'Pausar';
    $('migrador-reprocess-errors').disabled = running || !Number(status.errors || 0);
  }

  async function runQueue() {
    if (running && !paused) return;
    running = true; paused = false; updateButtons(); log('Importação iniciada.');
    while (running && !paused) {
      if (!status.next_url) { render(await request('migrador_noticias_get_status')); }
      if (!status.next_url) { log('Fila concluída.', 'success'); break; }
      const url = status.next_url;
      log(`Importando: ${url}`);
      try {
        const data = await request('migrador_noticias_import_single_url', { url });
        render(data);
        log(`HTTP ${data.http_code}: "${data.title}" importado como ${data.content_type}.`, 'success');
      } catch (error) {
        if (error.data && error.data.busy) { log('Importação ocupada; nova tentativa em breve.'); await sleep(1000); continue; }
        if (error.data) render(error.data);
        log(`Erro em ${url}: ${error.message || 'erro desconhecido'}`, 'error');
      }
      if (running && !paused) await sleep(Number(MigradorNoticias.delay) || 2000);
    }
    running = false; updateButtons();
  }

  document.addEventListener('DOMContentLoaded', () => {
    buildChart(); render(status); updateButtons();
    $('migrador-read-sitemap').addEventListener('click', async () => {
      if (running) return;
      try { log('Lendo sitemap...'); const data = await request('migrador_noticias_read_sitemap'); render(data); log(data.message, 'success'); }
      catch (error) { log(`Erro ao ler sitemap: ${error.message || 'erro desconhecido'}`, 'error'); }
    });
    $('migrador-start').addEventListener('click', runQueue);
    $('migrador-pause').addEventListener('click', () => { if (running && !paused) { paused = true; log('Pausa solicitada; a notícia atual será finalizada.'); } else if (paused) { runQueue(); } updateButtons(); });
    $('migrador-reprocess-errors').addEventListener('click', async () => { try { const data = await request('migrador_noticias_reprocess_errors'); render(data); log(data.message, 'success'); } catch (error) { log(`Não foi possível reprocessar erros: ${error.message || 'erro desconhecido'}`, 'error'); } });
    $('migrador-clear-log').addEventListener('click', () => { $('migrador-log').replaceChildren(); log('Log limpo.'); });
    $('migrador-download-errors').addEventListener('click', () => { window.location.href = `${MigradorNoticias.ajaxUrl}?action=migrador_noticias_download_errors&nonce=${encodeURIComponent(MigradorNoticias.nonce)}`; });
  });
}());
