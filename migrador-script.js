/* global MigradorNoticias */
(function () {
  'use strict';
  let running = false;
  let paused = false;
  let status = MigradorNoticias.initialStatus;
  const $ = (id) => document.getElementById(id);

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  function log(message) {
	message = String(message).replace(/importado\s+\S+\s+/, 'importado como ');
    const panel = $('migrador-log');
    const time = new Date().toLocaleTimeString();
    panel.textContent = `[${time}] ${message}\n` + panel.textContent;
  }
  function render(data) {
    status = data || status;
    const total = Number(status.total || 0);
    const processed = Number(status.processed || 0);
    const percent = total ? Math.round((processed / total) * 100) : 0;
    $('migrador-progress-bar').style.width = `${percent}%`;
    $('migrador-progress-text').textContent = `${processed} / ${total} processados (${percent}%)`;
    $('migrador-queue').textContent = status.queue || 0;
    $('migrador-completed').textContent = status.completed || 0;
    $('migrador-errors').textContent = status.errors || 0;
	$('migrador-categories-site-total').textContent = status.categories_site_total || 0;
	$('migrador-categories-existing').textContent = status.categories_existing || 0;
	$('migrador-categories-pending').textContent = status.categories_pending || 0;
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
  }
  async function runQueue() {
    if (running && !paused) return;
    running = true; paused = false; updateButtons();
    log('Importação iniciada.');
    while (running && !paused) {
      if (!status.next_url) {
        const fresh = await request('migrador_noticias_get_status'); render(fresh);
      }
      if (!status.next_url) { log('Fila concluída.'); break; }
      const url = status.next_url;
      log(`Importando: ${url}`);
      try {
        const data = await request('migrador_noticias_import_single_url', { url });
        render(data); log(`HTTP ${data.http_code}: "${data.title}" importado որպես ${data.content_type}${data.category ? ` — categoria: ${data.category}` : ''}.`);
      } catch (error) {
        if (error.data && error.data.busy) { log('Importação ocupada; nova tentativa em breve.'); await sleep(1000); continue; }
        if (error.data) render(error.data);
        log(`Erro em ${url}: ${error.message || 'erro desconhecido'}`);
      }
      if (running && !paused) await sleep(Number(MigradorNoticias.delay) || 2000);
    }
    running = false; updateButtons();
  }
  document.addEventListener('DOMContentLoaded', () => {
    render(status);
    $('migrador-read-sitemap').addEventListener('click', async () => {
      if (running) return;
      try { log('Lendo sitemap...'); const data = await request('migrador_noticias_read_sitemap'); render(data); log(data.message); }
      catch (error) { log(`Erro ao ler sitemap: ${error.message || 'erro desconhecido'}`); }
    });
    $('migrador-start').addEventListener('click', runQueue);
    $('migrador-pause').addEventListener('click', () => {
      if (running && !paused) { paused = true; log('Pausa solicitada; a notícia atual será finalizada.'); }
      else if (paused) { runQueue(); }
      updateButtons();
    });
  });
}());
