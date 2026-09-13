(function () {
  "use strict";

  const cfg = window.PEYVAST_AUTH_ADMIN || {};
  const $ = (selector, root = document) =>
    root?.querySelector?.(selector) || null;
  const restBase = () => String(cfg.rest || "").replace(/\/$/, "");

  async function post(path, data = {}) {
    const response = await fetch(`${restBase()}/${path}`, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-WP-Nonce": cfg.rest_nonce || "",
      },
      body: JSON.stringify(Object.assign({}, data, { nonce: cfg.nonce || "" })),
    });
    const result = await response.json();
    if (!result.success)
      throw new Error(
        result.data?.message || "The request could not be completed.",
      );
    return result.data || {};
  }

  function hasIssues(status) {
    return Number(status?.conflict || 0) > 0 || Number(status?.failed || 0) > 0;
  }

  function statusClass(status) {
    const value = typeof status === "string" ? status : status?.status;
    if (value === "running") return "is-loading";
    if (value === "busy") return "is-info";
    if (value === "failed") return "is-error";
    if (value === "completed") return hasIssues(status) ? "is-warning" : "is-success";
    if (value === "needs_review") return "is-warning";
    return "is-info";
  }

  function resultClass(status) {
    if (status?.status === "running") return "is-loading";
    if (status?.status === "failed") return "is-error";
    if (status?.status === "completed" && hasIssues(status)) return "is-warning";
    if (status?.status === "completed") return "is-success";
    return "";
  }

  function migrationText(status) {
    if (status.status === "needs_review")
      return (
        cfg.migrationNeedsReview ||
        "Review the configured phone source before starting Batch Migration."
      );
    if (status.status === "busy")
      return (
        cfg.migrationBusy ||
        "Another Batch Migration is already running. Please wait for it to finish."
      );
    if (status.status === "running")
      return `${Number(status.processed || 0)} / ${Number(status.total || 0)} processed — ` +
        (cfg.migrating || "Batch Migration is running…");
    if (status.status === "failed")
      return cfg.migrationError || "Mobile number migration failed. Please review the logs.";
    if (status.status === "completed" && hasIssues(status))
      return (
        formatIssuesMessage(Number(status.conflict || 0), Number(status.failed || 0))
      );
    if (status.status === "completed")
      return cfg.migrationCompleted || "Batch Migration completed successfully.";
    return cfg.migrationNotStarted || "No Batch Migration has been started.";
  }

  function formatIssuesMessage(conflicts, failures) {
    const template = cfg.migrationCompletedWithIssues ||
      "Batch Migration completed with %1$d conflicts and %2$d failed records.";
    return String(template).replace("%1$d", String(conflicts)).replace("%2$d", String(failures));
  }

  // Dependency-free presentation API so the state mapping stays testable without a DOM.
  window.PEYVAST_AUTH_MIGRATION_UI = Object.freeze({ statusClass, resultClass, migrationText });

  const ANIMATION_MS = 160;
  let initialized = false;
  const animationTimers = new WeakMap();
  let lifecycle = 0;
  let pollTimer = 0;
  const nextFrame = window.requestAnimationFrame || ((callback) => window.setTimeout(callback, 0));

  function animatedVisibility(element, visible) {
    if (!element) return;
    const existing = animationTimers.get(element);
    if (existing) window.clearTimeout(existing);

    if (visible) {
      element.hidden = false;
      element.classList.remove('is-exiting');
      element.classList.remove('is-visible');
      nextFrame(() => {
        if (!element.hidden) element.classList.add('is-visible');
      });
      return;
    }

    if (element.hidden) return;
    element.classList.remove('is-visible');
    element.classList.add('is-exiting');
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const timer = window.setTimeout(() => {
      element.hidden = true;
      element.classList.remove('is-exiting');
      animationTimers.delete(element);
    }, reduced ? 1 : ANIMATION_MS);
    animationTimers.set(element, timer);
  }

  function migrationRoot() {
    return document.querySelector('[data-peyvast-auth-migration-box="default"]');
  }

  function notice(root = migrationRoot()) {
    return $('[data-peyvast-auth-migration-notice="default"]', root || document);
  }

  function renderStatus(status, root = migrationRoot()) {
    if (!root) return;
    const n = notice(root);
    const counters = $('[data-peyvast-auth-migration-counters]', root);
    if (counters) {
      ['processed','migrated','already_current','missing','invalid','conflict','failed','skipped'].forEach((key) => {
        const node = counters.querySelector(`[data-counter="${key}"]`);
        if (node) node.textContent = String(Number(status?.[key] || 0));
      });
      animatedVisibility(counters, !!status?.id);
    }
    if (n) {
      const wasHidden = n.hidden;
      const shouldShowNotice =
        status.status !== 'idle' &&
        !(status.has_notice === false && ['needs_review', 'completed', 'failed'].includes(status.status));
      animatedVisibility(n, shouldShowNotice);
      n.classList.remove('is-loading', 'is-success', 'is-warning', 'is-error', 'is-info');
      n.classList.add(statusClass(status));
      const message = n.querySelector('[data-peyvast-auth-migration-message]');
      if (message) message.textContent = migrationText(status);
      const close = n.querySelector('[data-peyvast-auth-migration-close]');
      if (close) close.hidden = status.status === 'running' || status.status === 'busy' || status.status === 'idle';
      const icons = n.querySelectorAll('[data-peyvast-auth-migration-icon]');
      const iconState = statusClass(status).replace('is-', '');
      icons.forEach((icon) => { icon.hidden = icon.getAttribute('data-peyvast-auth-migration-icon') !== iconState; });
      const isAlert = status.status === 'failed';
      n.setAttribute('role', isAlert ? 'alert' : 'status');
      n.setAttribute('aria-live', isAlert ? 'assertive' : 'polite');
      if (status.status === 'running' && Number(status.total || 0) > 0) n.setAttribute('aria-busy', 'true');
      else n.removeAttribute('aria-busy');
      if (wasHidden && !n.hidden && ['completed', 'failed', 'needs_review'].includes(status.status)) {
        n.focus({ preventScroll: true });
      }
    }
    const progress = $('[data-peyvast-auth-migration-progress]', root);
    if (progress) {
      const total = Math.max(0, Number(status?.total || 0));
      const processed = Math.min(total, Math.max(0, Number(status?.processed || 0)));
      animatedVisibility(progress, status.status === 'running' && total > 0);
      if (total > 0) {
        progress.setAttribute('aria-valuemax', String(total));
        progress.setAttribute('aria-valuenow', String(processed));
        progress.style.setProperty('--peyvast-auth-progress', `${Math.round((processed / total) * 100)}%`);
      }
    }
  }

  async function getStatus() {
    const data = await post('migration-status');
    return data.migration_status || {};
  }

  async function dismiss(button, root) {
    if (!button || button.disabled) return;
    button.disabled = true;
    const token = ++lifecycle;
    if (pollTimer) {
      window.clearTimeout(pollTimer);
      pollTimer = 0;
    }

    // Reset presentation immediately; the server reset is authoritative but must not be awaited.
    renderStatus({ status: 'idle', has_notice: false }, root);

    try {
      const data = await post('migration-notice');
      if (token !== lifecycle) return;
      // Keep the client and persisted lifecycle in sync after dismissal.
      renderStatus(data.migration_status || { status: 'idle', has_notice: false }, root);
    } catch (error) {
      if (token !== lifecycle) return;
      renderStatus({ status: 'failed', has_notice: true }, root);
      const n = notice(root);
      const message = n?.querySelector('[data-peyvast-auth-migration-message]');
      if (message) message.textContent = cfg.migrationDismissError || 'The migration notice could not be closed. Please try again.';
    } finally {
      button.disabled = false;
    }
  }

  async function poll(token, root) {
    for (let attempts = 0; attempts < 600; attempts++) {
      if (token !== lifecycle) return { status: 'idle', canceled: true };
      const status = await getStatus();
      if (token !== lifecycle) return { status: 'idle', canceled: true };
      renderStatus(status, root);
      if (status.status !== 'running') return status;
      await new Promise((resolve) => {
        pollTimer = window.setTimeout(resolve, 800);
      });
    }
    throw new Error(cfg.migrationError || 'Migration status polling timed out.');
  }

  async function run(button, root) {
    if (!button || button.disabled) return;
    if (!window.confirm(cfg.migrationBackupConfirm || 'Confirm that you have a current database backup before starting.')) return;
    button.disabled = true;
    try {
      const token = ++lifecycle;
      const data = await post('migrate-user-phone');
      if (token !== lifecycle) return;
      renderStatus(data.migration_status || {}, root);
      await poll(token, root);
    } catch (error) {
      renderStatus({ status: 'failed', has_notice: true }, root);
      const n = notice(root);
      const message = n?.querySelector('[data-peyvast-auth-migration-message]');
      if (message) message.textContent = error.message || cfg.migrationError || 'Migration failed.';
    } finally {
      button.disabled = false;
    }
  }

  function init() {
    if (initialized) return;
    initialized = true;

    // Delegate from document: settings tab panels may be hidden or re-rendered without a reload.
    document.addEventListener('click', (event) => {
      const target = event.target?.closest?.('[data-peyvast-auth-migrate-user], [data-peyvast-auth-migration-close]');
      if (!target) return;
      const root = target.closest('[data-peyvast-auth-migration-box="default"]');
      if (!root) return;
      event.preventDefault();
      if (target.matches('[data-peyvast-auth-migrate-user]')) run(target, root);
      else dismiss(target, root);
    });

    const root = migrationRoot();
    if (root) {
      getStatus().then((status) => {
        if (lifecycle === 0) renderStatus(status, root);
      }).catch(() => {});
    }
  }

  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
})();
