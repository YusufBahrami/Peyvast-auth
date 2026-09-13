(function () {
  "use strict";

  const $ = (selector, root = document) =>
    root && typeof root.querySelector === "function"
      ? root.querySelector(selector)
      : null;
  const $$ = (selector, root = document) =>
    root && typeof root.querySelectorAll === "function"
      ? Array.from(root.querySelectorAll(selector))
      : [];
  const digitMap = {
    "۰": "0",
    "۱": "1",
    "۲": "2",
    "۳": "3",
    "۴": "4",
    "۵": "5",
    "۶": "6",
    "۷": "7",
    "۸": "8",
    "۹": "9",
    "٠": "0",
    "١": "1",
    "٢": "2",
    "٣": "3",
    "٤": "4",
    "٥": "5",
    "٦": "6",
    "٧": "7",
    "٨": "8",
    "٩": "9",
  };

  const normalizePhoneForValidation = (value) => {
    let v = normalizeDigits(String(value || "").trim());
    if (!v) return "";
    // Accept the same input forms as the server-side PhoneNumber.
    if (/[^0-9+().\-\s\u00A0]/.test(v)) return "";
    if ((v.match(/\+/g) || []).length > 1 || (v.includes("+") && !v.startsWith("+"))) return "";
    if (v.startsWith("+")) v = v.slice(1);
    v = v.replace(/[().\-\s\u00A0]+/g, "");
    if (!/^\d+$/.test(v)) return "";
    if (v.startsWith("0098")) v = v.slice(2);
    if (v.startsWith("09")) return v.slice(1);
    if (v.startsWith("98")) return v.slice(2);
    if (/^9\d{9}$/.test(v)) return v;
    return "";
  };

  const isPhone = (value) => /^9\d{9}$/.test(normalizePhoneForValidation(value));
  const isEmail = (value) =>
    /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(
      String(value || "").trim(),
    );
  const normalizeDigits = (value) =>
    String(value || "").replace(/[۰-۹٠-٩]/g, (digit) => digitMap[digit]);
  const formatTime = (seconds) => {
    const value = Math.max(0, parseInt(seconds, 10) || 0);
    return `${String(Math.floor(value / 60)).padStart(2, "0")}:${String(value % 60).padStart(2, "0")}`;
  };

  function createNoticeQueue(root, cfg, i18n) {
    const host = $("[data-peyvast-auth-notice-queue]", root);
    let sequence = 0;
    let active = null;
    let activeTimer = 0;
    const iconFor = (type) => cfg.icons?.notice?.[type] || "";
    const closeIcon = () => cfg.icons?.notice?.close || "";
    const remove = (item) => {
      if (!item) return;
      item.classList.remove("is-visible");
      item.classList.add("is-removing");
      window.setTimeout(() => item.remove(), 180);
    };
    const discard = () => {
      window.clearTimeout(activeTimer);
      activeTimer = 0;
      active = null;
      $$(".peyvast-auth-notice", host).forEach((item) => item.remove());
    };
    const dismiss = (item) => {
      if (item === active) {
        window.clearTimeout(activeTimer);
        activeTimer = 0;
        active = null;
      }
      remove(item);
    };
    const push = (message, type = "info", options = {}) => {
      if (!host || !message) return null;
      discard();
      const item = document.createElement("div");
      item.className = `peyvast-auth-notice peyvast-auth-notice--${type}`;
      item.dataset.noticeId = `notice-${Date.now()}-${sequence++}`;
      item.setAttribute("role", type === "error" ? "alert" : "status");
      const icon = document.createElement("span");
      icon.className = "peyvast-auth-notice__icon";
      icon.setAttribute("aria-hidden", "true");
      const iconValue = iconFor(type);
      if (typeof iconValue === "string" && iconValue.trim().startsWith("<svg"))
        icon.innerHTML = iconValue;
      else icon.setAttribute("hidden", "hidden");
      const content = document.createElement("div");
      content.className = "peyvast-auth-notice__content";
      content.textContent = message;
      const close = document.createElement("button");
      close.type = "button";
      close.className = "peyvast-auth-notice__close";
      close.setAttribute(
        "aria-label",
        options.closeLabel || i18n("close", "Close"),
      );
      const closeValue = closeIcon();
      if (
        typeof closeValue === "string" &&
        closeValue.trim().startsWith("<svg")
      )
        close.innerHTML = closeValue;
      close.addEventListener("click", () => dismiss(item));
      item.append(icon, content, close);
      host.appendChild(item);
      active = item;
      requestAnimationFrame(() => item.classList.add("is-visible"));
      const duration =
        options.duration == null
          ? 20000
          : Math.max(0, Number(options.duration) || 0);
      if (duration > 0) activeTimer = window.setTimeout(() => dismiss(item), duration);
      return item.dataset.noticeId;
    };
    return { push, clear: discard };
  }

  const security = (() => {
    const TOKEN_KEY = "peyvast_auth_security_token";
    const STATE_KEY = "peyvast_auth_security_state";
    let blocked = false;
    let checked = false;
    let checkRequest = null;
    let timer = null;
    const read = (key) => {
      try {
        const value = window.localStorage.getItem(key);
        if (value !== null) return value;
      } catch (e) {

      }
      try {
        const value = window.sessionStorage.getItem(key);
        return value !== null ? value : null;
      } catch (e) {
        return null;
      }
    };
    const write = (key, value) => {
      try {
        window.localStorage.setItem(key, value);
        return true;
      } catch (e) {

      }
      try {
        window.sessionStorage.setItem(key, value);
        return true;
      } catch (e) {
        return false;
      }
    };
    const remove = (key) => {
      try {
        window.localStorage.removeItem(key);
      } catch (e) {

      }
      try {
        window.sessionStorage.removeItem(key);
      } catch (e) {

      }
    };
    const token = () => read(TOKEN_KEY) || "";
    const readState = () => {
      try {
        return JSON.parse(read(STATE_KEY) || "{}") || {};
      } catch (e) {
        return {};
      }
    };
    const hasLocalState = () => !!read(TOKEN_KEY);
    const notify = (value) => {
      blocked = !!value;
      document.dispatchEvent(
        new CustomEvent("peyvast:security", { detail: { blocked } }),
      );
    };
    const clearBlocked = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
      remove(TOKEN_KEY);
      remove(STATE_KEY);
      notify(false);
    };
    const setBlocked = (tok, retryAfter, clientBlock) => {
      const seconds = Math.max(0, Number(retryAfter) || 0);
      if (!clientBlock || seconds <= 0) {
        clearBlocked();
        return;
      }
      if (!tok) {
        if (timer) window.clearTimeout(timer);
        timer = window.setTimeout(clearBlocked, seconds * 1000 + 250);
        notify(true);
        return;
      }
      write(TOKEN_KEY, tok);
      const until = Date.now() + seconds * 1000;
      write(STATE_KEY, JSON.stringify({ until }));
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(clearBlocked, seconds * 1000 + 250);
      notify(true);
    };
    const initial = readState();
    const initialToken = read(TOKEN_KEY);
    if (!initialToken) {
      remove(STATE_KEY);
    } else if (Number(initial.until || 0) > Date.now()) {
      blocked = true;
      timer = window.setTimeout(
        clearBlocked,
        Number(initial.until) - Date.now() + 250,
      );
    } else if (initial.until) {
      clearBlocked();
    }
    const checkStatus = (cfg) => {
      if (checkRequest) return checkRequest;
      if (checked) return Promise.resolve(blocked);
      checked = true;
      const body = { token: token(), nonce: cfg.nonce || "" };
      checkRequest = fetch(
        `${String(cfg.rest || "").replace(/\/$/, "")}/security-status`,
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
          },
          body: JSON.stringify(body),
        },
      )
        .then((r) => r.json())
        .then((json) => {
          const data = json && json.success && json.data ? json.data : {};
          if (data.blocked && data.client_block && Number(data.retry_after) > 0)
            setBlocked(body.token, Number(data.retry_after), true);
          else clearBlocked();
          return !!data.blocked;
        })
        .catch(() => blocked)
        .finally(() => {
          checkRequest = null;
        });
      return checkRequest;
    };
    const subscribe = (callback) => {
      callback(blocked);
      document.addEventListener("peyvast:security", (event) =>
        callback(!!(event.detail && event.detail.blocked)),
      );
    };
    return {
      token,
      hasLocalState,
      blocked: () => blocked,
      setBlocked,
      clearBlocked,
      checkStatus,
      subscribe,
    };
  })();

  async function post(path, data, cfg) {
    const payload = Object.assign({}, data || {}, { nonce: cfg.nonce || "" });
    const headers = {
      Accept: "application/json",
      "Content-Type": "application/json",
    };
    if (cfg.logged_in === true && cfg.rest_nonce)
      headers["X-WP-Nonce"] = cfg.rest_nonce;
    const response = await fetch(
      `${String(cfg.rest || "").replace(/\/$/, "")}/${path}`,
      {
        method: "POST",
        credentials: "same-origin",
        headers,
        body: JSON.stringify(payload),
      },
    );
    const text = await response.text();
    let json = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch (error) {
      const err = new Error("Invalid server response.");
      err.code = "invalid_response";
      err.httpStatus = response.status;
      throw err;
    }
    if (!json || typeof json !== "object") {
      const err = new Error("Invalid server response.");
      err.code = "invalid_response";
      err.httpStatus = response.status;
      throw err;
    }
    return { json, httpStatus: response.status, ok: response.ok };
  }

  async function request(action, data, root, cfg, i18n) {
    if (security.blocked()) {
      const err = new Error(
        i18n(
          "too_many_requests",
          "The number of requests exceeds the allowed limit.",
        ),
      );
      err.code = "security_blocked";
      err.payload = {};
      throw err;
    }
    let result;
    try {
      result = await post(action.replaceAll("_", "-"), data, cfg);
    } catch (error) {
      const err = new Error(
        i18n(
          "generic_error",
          "A server error occurred. Please try again in a few minutes.",
        ),
      );
      err.code = error?.code === "invalid_response" ? "invalid_response" : "network";
      err.httpStatus = error?.httpStatus || 0;
      throw err;
    }
    const json = result?.json;
    if (!json || typeof json !== "object") {
      const err = new Error(
        i18n(
          "generic_error",
          "A server error occurred. Please try again in a few minutes.",
        ),
      );
      err.code = "invalid_response";
      throw err;
    }
    if (!json.success) {
      const errorData = json.data && typeof json.data === "object" ? json.data : {};
      const errorCode = errorData.code || json.code || "request_failed";
      const errorMessage =
        errorData.message ||
        json.message ||
        i18n(
          "generic_error",
          "A server error occurred. Please try again in a few minutes.",
        );
      if (errorCode === "security_blocked") {
        security.setBlocked(
          typeof errorData.token === "string" && errorData.token !== ""
            ? errorData.token
            : null,
          Number(errorData.retry_after || 0),
          !!errorData.client_block,
        );
        const err = new Error(
          i18n(
            "too_many_requests",
            "The number of requests exceeds the allowed limit.",
          ),
        );
        err.code = "security_blocked";
        err.payload = errorData;
        throw err;
      }
      const err = new Error(errorMessage);
      err.code = errorCode;
      err.payload = errorData;
      err.httpStatus = result?.httpStatus || 0;
      throw err;
    }
    return json.data || {};
  }

  function boot(root) {
    if (!root || root.dataset.ready === "1") return;
    root.dataset.ready = "1";

    let cfg = {};
    try {
      cfg =
        JSON.parse($("[data-peyvast-auth-config]", root)?.textContent || "{}") ||
        {};
    } catch (error) {
      cfg = {};
    }
    const i18n = (key, fallback = "") =>
      (cfg.i18n && cfg.i18n[key]) || fallback;
    const messages = cfg.messages || {};
    const infoTemplates = cfg.information || {};
    const noticeIcons = cfg.icons?.notice || {};
    const queue = createNoticeQueue(root, cfg, i18n);
    let steps = [];
    let otpStep = null;
    let otpInputs = [];
    let otpInput = null;
    // Auto-focus digit one only on first entry.
    let otpFocusLocked = false;
    const form = $("[data-peyvast-auth-form]", root);
    const refreshStructure = () => {
      steps = $$("[data-step]", root);
      otpStep = $('[data-step="otp"]', root);
      otpInputs = otpStep ? $$("[data-otp-input]", otpStep) : [];
      otpInput = otpInputs[0] || null;
    };
    refreshStructure();
    const otpLength = Math.max(
      4,
      Math.min(8, parseInt(cfg.otp_length || "6", 10)),
    );
    const normalizeOtpCode = (value, maxLength = otpLength) =>
      normalizeDigits(value).replace(/\D/g, "").slice(0, maxLength);
    const state = {
      step: "phone",
      previous: [],
      challengeId: "",
      verificationToken: "",
      purpose: "otp_login",
      identifier: "",
      timerId: null,
      timerEndsAt: 0,
      busy: false,
      information: null,
      autoVerifyLocked: false,
      webOtpController: null,
      activeAction: "",
      resendAvailable: false,
      expiresAt: 0,
      webOtpGeneration: 0,
      deliveryPollId: null,
      deliveryPollGeneration: 0,
    };

    const historyRootId =
      root.id ||
      root.dataset.peyvastAuth ||
      `peyvast-auth-${Math.random().toString(36).slice(2)}`;
    const historyState = () => ({
      plugin: "peyvast-otp-login",
      rootId: historyRootId,
      stage: state.step,
    });
    const ownsHistoryState = (value) =>
      value?.plugin === "peyvast-otp-login" && value?.rootId === historyRootId;
    const activeStep = () =>
      steps.find(
        (step) =>
          step.classList.contains("is-active") &&
          !step.hidden &&
          step.getAttribute("aria-hidden") !== "true" &&
          !step.hasAttribute("inert"),
      ) || null;
    const infoHost = () =>
      $("[data-peyvast-auth-information]", activeStep() || root);
    const notice = (message, type = "info") => queue.push(message, type);
    if (cfg.initial_notice)
      notice(cfg.initial_notice, cfg.initial_notice_type || "warning", {
        duration: 0,
      });

    if (cfg.security_enabled !== false && security.hasLocalState()) {
      security.checkStatus(cfg);
    }
    security.subscribe((isBlocked) => {
      $$(
        '[data-peyvast-auth-action-button], [data-peyvast-auth-action="back"]',
        root,
      ).forEach((button) => {
        if (button.dataset.action === "resend") {
          if (!isBlocked && state.step === "otp" && state.timerEndsAt <= Date.now() && state.resendAvailable === true) {
            enableResend();
          } else {
            syncResendButton();
          }
        } else {
          button.disabled = isBlocked;
        }
      });
      $$("input,select,textarea", root).forEach((control) => {
        control.disabled = isBlocked;
      });
      if (isBlocked)
        notice(
          i18n(
            "too_many_requests",
            "The number of requests exceeds the allowed limit.",
          ),
          "warning",
        );
    });
    const fieldFromInput = (input) =>
      input?.closest?.("[data-peyvast-auth-field]") || null;
    const setInlineError = (input, message) => {
      if (!input) return;
      const field = fieldFromInput(input);
      const host = field?.querySelector("[data-peyvast-auth-inline-error]");
      const text = String(message || "");
      const active = text !== "";
      if (
        field?.dataset.inlineError === (active ? text : "") &&
        input.classList.contains("is-invalid") === active
      )
        return;
      if (field) field.dataset.inlineError = active ? text : "";
      field?.classList.toggle("has-error", active);
      field?.classList.toggle("is-error", active);
      input.classList.toggle("is-invalid", active);
      input.setAttribute("aria-invalid", active ? "true" : "false");
      if (host) {
        if (active) {
          host.hidden = false;
          host.removeAttribute("hidden");
        } else {
          host.hidden = true;
          host.setAttribute("hidden", "hidden");
        }
        if (host.textContent !== text) host.textContent = text;
        if (active && host.id) input.setAttribute("aria-describedby", host.id);
        else input.removeAttribute("aria-describedby");
      }
    };
    const clearInlineError = (input) => {
      if (!input) return;
      const field = fieldFromInput(input);
      if (field && !field.classList.contains("has-error")) return;
      setInlineError(input, "");
    };
    const clearAllInlineErrors = () =>
      $$("[data-peyvast-auth-field]", root).forEach((field) => {
        field.classList.remove("has-error", "is-error");
        delete field.dataset.inlineError;
        const error = field.querySelector("[data-peyvast-auth-inline-error]");
        if (error) {
          error.hidden = true;
          error.setAttribute("hidden", "hidden");
          error.textContent = "";
        }
        field.querySelectorAll("input,select,textarea").forEach((input) => {
          input.classList.remove("is-invalid");
          input.setAttribute("aria-invalid", "false");
          input.removeAttribute("aria-describedby");
        });
      });
    const boundFieldInputs = new WeakSet();
    const bindFieldInputs = () => {
      $$(
        "[data-peyvast-auth-field] input, [data-peyvast-auth-field] select, [data-peyvast-auth-field] textarea",
        root,
      ).forEach((input) => {
        if (boundFieldInputs.has(input)) return;
        boundFieldInputs.add(input);
        let valueBeforeEdit = input.value;
        input.addEventListener("focus", () => {
          valueBeforeEdit = input.value;
        });
        input.addEventListener("input", () => {
          if (input.value !== valueBeforeEdit) {
            clearInlineError(input);
            valueBeforeEdit = input.value;
          }
        });
      });
    };
    bindFieldInputs();

    const fieldInput = (name) => {
      const byName = {
        otp: () => otpInput,
        identifier: () => {
          if (state.step === "forgot")
            return $("[data-forgot-identifier]", root);
          if (state.step === "password")
            return $("[data-password-identifier]", root);
          return $("[data-identifier]", root);
        },
        first_name: () => $("[data-first-name]", root),
        last_name: () => $("[data-last-name]", root),
        email: () => $("[data-register-email]", root),
        password: () => $("[data-register-password]", root),
        login_password: () => $("[data-login-password]", root),
        new_password: () => $("[data-new-password]", root),
      };
      return (byName[name] || (() => null))();
    };
    // Keep the caret on the last filled digit unless the code was actually wrong (invalid_otp).
    const focusLastFilledOtp = () => {
      refreshStructure();
      for (let i = otpInputs.length - 1; i >= 0; i--) {
        if (otpInputs[i].value !== "") {
          otpInputs[i].focus();
          return;
        }
      }
      otpInputs[0]?.focus();
    };
    const applyServerError = (error) => {
      const field = error?.payload?.field;
      if (!field) return false;
      const input = fieldInput(field);
      if (!input) return false;
      setInlineError(
        input,
        error.message ||
          i18n(
            "generic_error",
            "A server error occurred. Please try again in a few minutes.",
          ),
      );
      if (field === "otp") {
        if (error?.code === "otp_expired") {
          clearOtpValue(true);
          clearAllInlineErrors();
        } else {
          focusLastFilledOtp();
        }
      } else input.focus();
      return true;
    };

    const escapeHtml = (value) =>
      String(value ?? "").replace(
        /[&<>"']/g,
        (c) =>
          ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;",
          })[c],
      );
    const templateVars = (extra = {}) =>
      Object.assign(
        {
          identifier: state.information?.identifier || "",
          phone: state.information?.phone || "",
          email: state.information?.email || "",
          site_name: cfg.site_name || document.title || "",
          otp_length: otpLength,
          channel: state.information?.channel || "",
          seconds: "",
          valid_seconds: Number(cfg.otp_valid_seconds || 0),
          valid_minutes: Math.max(
            1,
            Number(
              cfg.otp_valid_minutes ||
                Math.ceil(Number(cfg.otp_valid_seconds || 60) / 60),
            ),
          ),
        },
        extra,
      );
    const fillTemplate = (value, extra, escape) => {
      const map = templateVars(extra);
      return String(value || "").replace(/\{([a-z_]+)\}/gi, (_, key) => {
        if (map[key] == null) return "";
        const text = escape ? escapeHtml(map[key]) : String(map[key]);
        // Identifier-ish keys get a styling hook; the value itself stays escaped.
        if (
          escape &&
          ["identifier", "channel", "phone", "email"].includes(key)
        ) {
          return `<strong class="peyvast-auth__information-${key}">${text}</strong>`;
        }
        return text;
      });
    };
    const template = (value, extra = {}) => fillTemplate(value, extra, false);
    const templateHtml = (value, extra = {}) =>
      fillTemplate(value, extra, true);

    // Server resolves static variables; client only fills {identifier}/{channel} from the response.
    const renderStageTemplates = () => {
      // Server-supplied markup is wp_kses'd; only placeholders are client-filled.
      $$("[data-stage-heading], [data-stage-description]", root).forEach(
        (node) => {
          const value = node ? node.innerHTML : "";
          if (value && /\{[a-z_]+\}/i.test(value)) {
            node.innerHTML = templateHtml(value);
          }
        },
      );
    };
    renderStageTemplates();

    const appendInformationItem = (host, message) => {
      const item = document.createElement("div");
      item.className = "peyvast-auth__information-item";
      const parts = String(message || "").split(/(\{[a-z_]+\})/gi);
      for (const part of parts) {
        if (!part) continue;
        const match = part.match(/^\{([a-z_]+)\}$/i);
        const vars = templateVars();
        if (match && vars[match[1]] != null) {
          const key = match[1];
          const value = String(vars[key]);
          if (["identifier", "channel", "phone", "email"].includes(key)) {
            const strong = document.createElement("strong");
            strong.className = `peyvast-auth__information-${key}`;
            strong.textContent = value;
            item.appendChild(strong);
          } else {
            item.appendChild(document.createTextNode(value));
          }
          continue;
        }
        item.appendChild(document.createTextNode(part));
      }
      host.appendChild(item);
    };

    const loadingIcon =
      cfg.icons?.loading ||
      '<span class="peyvast-auth-button__loading-icon" aria-hidden="true"></span>';

    function syncResendButton() {
      const resend = $('[data-action="resend"]', root);
      if (!resend) return;
      const loadingResend =
        state.busy && state.activeAction === "resend";
      const ready =
        state.step === "otp" &&
        !!state.challengeId &&
        (loadingResend ||
          (state.resendAvailable === true &&
            (state.timerEndsAt === 0 || state.timerEndsAt <= Date.now())));
      const disabled = state.busy || security.blocked() || !ready;
      resend.disabled = disabled;
      resend.hidden = !ready;
      resend.setAttribute("aria-disabled", disabled ? "true" : "false");
      resend.dataset.resendReady = ready ? "1" : "0";
      const slot = $("[data-resend-slot]", root);
      slot?.classList.toggle("is-ready", ready);
    }

    function setBusy(value, action = "") {
      state.busy = !!value;
      state.activeAction = state.busy ? String(action || "") : "";
      root.classList.toggle("is-loading", state.busy);
      $$("[data-peyvast-auth-action-button]", root).forEach((button) => {
        const active =
          state.busy &&
          (!state.activeAction || button.dataset.action === state.activeAction);
        if (button.dataset.action === "resend") {
          button.classList.toggle("is-loading", active);
          button.setAttribute("aria-busy", active ? "true" : "false");
        }
        button.classList.toggle("is-loading", active);
        button.setAttribute("aria-busy", active ? "true" : "false");
        const existing = button.querySelector(
          ":scope > .peyvast-auth-button__loading",
        );
        if (active) {
          if (existing) existing.replaceChildren();
          if (!existing && loadingIcon) {
            const div = document.createElement("div");
            div.className = "peyvast-auth-button__loading";
            div.setAttribute("aria-hidden", "true");
            div.innerHTML = loadingIcon;
            button.appendChild(div);
          }
        } else if (existing) {
          existing.remove();
        }
      });
      $$("button,input,select,textarea", root).forEach((control) => {
        if (control.matches("[data-otp-input]")) {
          control.disabled = false;
          return;
        }
        if (control.matches('[data-action="resend"]')) return;
        control.disabled = state.busy;
      });
      syncResendButton();
    }

    function clearDeliveryStatusPoll() {
      if (state.deliveryPollId) window.clearTimeout(state.deliveryPollId);
      state.deliveryPollId = null;
      state.deliveryPollGeneration += 1;
    }

    function scheduleDeliveryStatusPoll(challengeId, delay = 1000, attempt = 0) {
      clearDeliveryStatusPoll();
      const generation = state.deliveryPollGeneration;
      const maxAttempts = 7;
      if (!challengeId || attempt >= maxAttempts || !state.expiresAt || state.expiresAt <= Date.now()) return;
      const wait = Math.max(250, Math.min(Number(delay) || 1000, 10000));
      state.deliveryPollId = window.setTimeout(async () => {
        state.deliveryPollId = null;
        if (generation !== state.deliveryPollGeneration || challengeId !== state.challengeId || state.step !== "otp") return;
        if (state.expiresAt <= Date.now()) return;
        try {
          const data = await request(
            "delivery_status",
            { challenge_id: challengeId },
            root,
            cfg,
            i18n,
          );
          if (generation !== state.deliveryPollGeneration || challengeId !== state.challengeId) return;
          const status = String(data?.delivery_status || "");
          if (status === "delivered") return;
          if (status === "failed") {
            state.resendAvailable = true;
            state.timerEndsAt = 0;
            syncResendButton();
            notice(
              i18n(
                "delivery_failed",
                "The verification code could not be delivered. Please request a new code.",
              ),
              "warning",
            );
            return;
          }
          scheduleDeliveryStatusPoll(challengeId, Math.min(wait * 2, 10000), attempt + 1);
        } catch (error) {
          if (generation === state.deliveryPollGeneration && challengeId === state.challengeId) {
            scheduleDeliveryStatusPoll(challengeId, Math.min(wait * 2, 10000), attempt + 1);
          }
        }
      }, wait);
    }

    function clearTimer() {
      if (state.timerId) window.clearTimeout(state.timerId);
      state.timerId = null;
      state.timerEndsAt = 0;
      state.resendAvailable = false;
      const timer = $("[data-otp-timer]", root);
      const resend = $('[data-action="resend"]', root);
      const slot = $("[data-resend-slot]", root);
      if (timer) {
        timer.hidden = true;
        timer.textContent = "";
      }
      if (resend) {
        resend.hidden = true;
        resend.disabled = true;
        resend.setAttribute("aria-disabled", "true");
        resend.dataset.resendReady = "0";
      }
      slot?.classList.remove("is-ready");
    }

    function canResendOtp() {
      return (
        state.step === "otp" &&
        !state.busy &&
        !!state.challengeId &&
        !security.blocked() &&
        state.resendAvailable === true &&
        (state.timerEndsAt === 0 || state.timerEndsAt <= Date.now())
      );
    }

    function enableResend() {
      const resend = $('[data-action="resend"]', root);
      const slot = $("[data-resend-slot]", root);
      if (!resend) return;
      state.timerEndsAt = 0;
      state.resendAvailable = true;
      resend.hidden = false;
      resend.disabled = false;
      resend.removeAttribute("disabled");
      resend.setAttribute("aria-disabled", "false");
      resend.dataset.resendReady = "1";
      slot?.classList.add("is-ready");
    }

    function startTimer(seconds) {
      clearTimer();
      const total = Math.max(0, parseInt(seconds, 10) || 0);
      const timer = $("[data-otp-timer]", root);
      const resend = $('[data-action="resend"]', root);
      const slot = $("[data-resend-slot]", root);
      if (!resend) return;
      if (!timer) {
        if (total <= 0) enableResend();
        return;
      }
      resend.disabled = true;
      resend.setAttribute("aria-disabled", "true");
      if (total <= 0) {
        timer.hidden = true;
        enableResend();
        return;
      }
      timer.hidden = false;
      resend.hidden = true;
      slot?.classList.remove("is-ready");
      state.timerEndsAt = Date.now() + total * 1000;
      let lastLeft = null;
      const update = () => {
        const left = Math.max(
          0,
          Math.ceil((state.timerEndsAt - Date.now()) / 1000),
        );
        if (left !== lastLeft) {
          lastLeft = left;
          timer.textContent = template(
            cfg.texts?.resend_timer_template ||
              i18n(
                "resend_timer_default",
                "Resend verification code in {seconds}",
              ),
            { seconds: formatTime(left) },
          );
        }
        if (left <= 0) {
          if (state.timerId) window.clearTimeout(state.timerId);
          state.timerId = null;
          timer.hidden = true;
          enableResend();
          return;
        }
        state.timerId = window.setTimeout(update, 1000);
      };
      update();
    }

    function renderInformation(data) {
      const host = infoHost();
      if (!host) return;
      state.information = data || {};
      host.replaceChildren();

      const message = infoTemplates.otp_sent || messages.otp_sent_message;
      if (!message) {
        host.hidden = true;
        return;
      }
      appendInformationItem(host, message);
      host.hidden = false;
      renderStageTemplates();
    }

    function clearAllInformation() {
      $$("[data-peyvast-auth-information]", root).forEach((host) => {
        host.hidden = true;
        host.replaceChildren();
      });
    }

    function clearStageFields(stage) {
      const target = steps.find((step) => step.dataset.step === stage);
      if (!target) return;
      target
        .querySelectorAll('input:not([type="hidden"]), select, textarea')
        .forEach((input) => {
          input.value = "";
          input.checked = false;
          input.classList.remove("is-invalid");
          input.setAttribute("aria-invalid", "false");
          input.removeAttribute("aria-describedby");
        });
    }

    const stageTransitions = {
      phone: new Set(["otp", "password", "forgot"]),
      password: new Set(["phone"]),
      otp: new Set(["phone", "register", "reset"]),
      register: new Set(["phone"]),
      forgot: new Set(["phone", "otp"]),
      reset: new Set(["forgot"]),
    };

    function clearSensitiveFields() {
      [
        "[data-otp-input]",
        "[data-login-password]",
        "[data-register-password]",
        "[data-new-password]",
      ].forEach((selector) => {
        $$(selector, root).forEach((input) => {
          input.value = "";
          input.classList.remove("is-invalid");
          input.setAttribute("aria-invalid", "false");
          input.removeAttribute("aria-describedby");
        });
      });
    }

    function clearAuthenticationState({ keepIdentifier = false } = {}) {
      state.challengeId = "";
      state.verificationToken = "";
      state.purpose = "otp_login";
      state.expiresAt = 0;
      state.autoVerifyLocked = false;
      if (!keepIdentifier) {
        state.identifier = "";
      }
      clearTimer();
      abortWebOtp();
    }

    const interactiveFieldSelector =
      'input:not([type="hidden"]),select,textarea';
    const isInteractiveField = (field, stage) => {
      if (
        !field ||
        !field.matches?.(interactiveFieldSelector) ||
        field.disabled ||
        field.hidden ||
        !stage?.contains(field) ||
        stage.hidden ||
        stage.getAttribute("aria-hidden") === "true" ||
        stage.hasAttribute("inert") ||
        field.closest("[hidden], [aria-hidden=\"true\"], [inert]")
      )
        return false;
      const style = window.getComputedStyle?.(field);
      return !style || (style.display !== "none" && style.visibility !== "hidden");
    };
    const firstInteractiveField = (stage) => {
      if (!stage) return null;
      const candidates = stage.matches('[data-step="otp"]')
        ? $$('[data-otp-input]', stage)
        : $$(interactiveFieldSelector, stage);
      return candidates.find((field) => isInteractiveField(field, stage)) || null;
    };

    function syncStageVisibility(target) {
      steps.forEach((step) => {
        const active = step === target;
        step.classList.toggle("is-active", active);
        step.hidden = !active;
        step.toggleAttribute("inert", !active);
        step.setAttribute("aria-hidden", active ? "false" : "true");
      });
    }

    const viewportFocusState = {
      focused: null,
      baselineScrollY: null,
      keyboardOpen: false,
      userScrolled: false,
      lastManagedScrollY: null,
      baselineViewportHeight: null,
      lastGestureAt: 0,
      scheduleId: 0,
      rafId: 0,
      ignoreScrollUntil: 0,
    };
    const visualViewport = window.visualViewport || null;
    const iOS_KEYBOARD =
      /iPhone|iPad|iPod/i.test(navigator.userAgent || "") ||
      (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
    const keyboardIsOpen = () => {
      const layoutHeight = window.innerHeight || document.documentElement.clientHeight;
      if (visualViewport)
        return visualViewport.height < layoutHeight - 80;
      return (
        viewportFocusState.baselineViewportHeight != null &&
        layoutHeight < viewportFocusState.baselineViewportHeight - 80
      );
    };
    const ensureFieldVisible = (field) => {
      const stage = field?.closest?.("[data-step]");
      if (!isInteractiveField(field, stage) || !keyboardIsOpen()) return;
      const viewport = window.visualViewport;
      const top = viewport?.offsetTop || 0;
      const bottom = top + (viewport?.height || window.innerHeight);
      const margin = 16;
      const rect = field.getBoundingClientRect();
      const action = keyboardIsOpen()
        ? stage.querySelector('button[type="submit"]:not([disabled])')
        : null;
      const actionRect = action?.getBoundingClientRect?.();
      const requiredBottom = Math.max(
        rect.bottom,
        actionRect?.bottom || rect.bottom,
      );
      let delta = Math.max(0, requiredBottom - (bottom - margin));
      if (delta && rect.top - delta < top + margin)
        delta = Math.max(0, rect.top - (top + margin));
      if (!delta) return;
      viewportFocusState.ignoreScrollUntil = Date.now() + 350;
      if (typeof window.scrollBy === "function") {
        window.scrollBy({ top: delta, left: 0, behavior: "auto" });
        viewportFocusState.lastManagedScrollY = window.scrollY + delta;
      }
    };
    const scheduleFieldVisibility = (field, delays = [0, 120, 320]) => {
      if (!field) return;
      window.clearTimeout(viewportFocusState.scheduleId);
      if (viewportFocusState.rafId) {
        if (window.cancelAnimationFrame) window.cancelAnimationFrame(viewportFocusState.rafId);
        else window.clearTimeout(viewportFocusState.rafId);
      }
      const run = () => {
        if (!field.isConnected || document.activeElement !== field) return;
        const requestFrame = window.requestAnimationFrame || ((callback) => window.setTimeout(callback, 0));
        viewportFocusState.rafId = requestFrame(() => {
          viewportFocusState.rafId = 0;
          ensureFieldVisible(field);
        });
      };
      run();
      delays.slice(1).forEach((delay) => {
        viewportFocusState.scheduleId = window.setTimeout(run, delay);
      });
    };

    const focusActiveStageField = ({ force = false } = {}) => {
      refreshStructure();
      const stage = activeStep();
      const current = document.activeElement;
      if (!stage) return null;
      if (isInteractiveField(current, stage)) {
        scheduleFieldVisibility(current);
        return current;
      }
      if (
        !force &&
        current &&
        current !== document.body &&
        current !== document.documentElement &&
        !root.contains(current)
      )
        return current;
      if (
        !force &&
        current &&
        current !== document.body &&
        current !== document.documentElement &&
        !current.matches?.(interactiveFieldSelector)
      )
        return current;
      if (
        stage.matches('[data-step="otp"]') &&
        otpFocusLocked &&
        !force
      ) {
        return current || null;
      }
      const field = firstInteractiveField(stage);
      if (!field) return null;
      if (
        iOS_KEYBOARD &&
        !keyboardIsOpen() &&
        Date.now() - viewportFocusState.lastGestureAt > 300
      ) {
        return null;
      }
      field.focus({ preventScroll: true });
      if (stage.matches('[data-step="otp"]')) otpFocusLocked = true;
      scheduleFieldVisibility(field);
      return field;
    };

    function show(stage, options = {}) {
      refreshStructure();
      if (stage === "otp" && !state.challengeId) {
        if (state.step !== "phone") {
          show("phone", { pushHistory: false, focus: true, allowTransition: true });
        }
        return;
      }
      const target = steps.find((step) => step.dataset.step === stage);
      if (!target) return;
      const previousStage = state.step;
      if (previousStage !== stage) {
        const allowed = stageTransitions[previousStage];
        if (allowed && !allowed.has(stage) && !options.allowTransition) return;
        if (options.pushHistory !== false && previousStage !== "register")
          state.previous.push(previousStage);
        clearStageFields(previousStage);
      }
      clearTimer();
      clearAllInformation();
      clearAllInlineErrors();
      if (previousStage !== stage && (stage === "phone" || stage === "password"))
        clearAuthenticationState();
      if (previousStage === "register" && stage === "phone")
        clearAuthenticationState();
      if (stage !== "otp") clearSensitiveFields();
      state.step = stage;
      state.autoVerifyLocked = false;
      if (stage === "otp" && previousStage !== stage) {
        otpFocusLocked = false;
      }
      if (options.pushHistory === true) {
        const current = window.history.state;
        if (!ownsHistoryState(current) || current.stage !== stage) {
          window.history.pushState(historyState(), "", window.location.href);
        }
      }
      const focused = document.activeElement;
      if (
        focused &&
        steps.some(
          (step) => !step.hidden && step.contains(focused) && step !== target,
        )
      ) {
        focused.blur();
      }
      syncStageVisibility(target);
      if (stage !== "otp") abortWebOtp();
      if (options.focus !== false) {
        const focusStage = () => {
          if (activeStep() === target) focusActiveStageField({ force: true });
        };
        if (Date.now() - viewportFocusState.lastGestureAt < 300) focusStage();
        else window.setTimeout(focusStage, 0);
      }
      if (stage === "otp" && state.challengeId && options.retryAfter != null) {
        startTimer(options.retryAfter);
        startWebOtp();
      } else if (stage === "otp" && state.challengeId) {
        startWebOtp();
        if (state.resendAvailable === true && !state.timerEndsAt) enableResend();
      }
    }

    function back() {
      const targets = {
        register: "phone",
        otp: "phone",
        password: "phone",
        forgot: "password",
        reset: "forgot",
      };
      const target = targets[state.step] || "phone";
      state.previous = [];
      show(target, { pushHistory: false, focus: true, allowTransition: true });
    }

    function fieldForPurpose(purpose = "otp_login") {
      const key =
        purpose === "password_reset"
          ? "password_reset"
          : purpose === "password_login"
            ? "password_login"
            : "otp_login";
      const configured = cfg.identifier_fields?.[key];
      if (configured) return configured;
      const phone =
        purpose === "password_reset"
          ? cfg.reset_phone_enabled === true
          : purpose === "password_login"
            ? cfg.password_phone_enabled === true
            : cfg.otp_login_phone_enabled === true;
      const email =
        purpose === "password_reset"
          ? cfg.reset_email_enabled === true
          : purpose === "password_login"
            ? cfg.password_email_enabled === true
            : cfg.otp_login_email_enabled === true;
      return {
        enabled: phone || email,
        identifiers: [phone ? "phone" : "", email ? "email" : ""].filter(
          Boolean,
        ),
      };
    }

    function phoneEnabledForPurpose(purpose = "otp_login") {
      if (purpose === "password_reset") return cfg.reset_phone_enabled === true;
      if (purpose === "password_login")
        return cfg.password_phone_enabled === true;
      return cfg.otp_login_phone_enabled === true;
    }

    function identifierValidation(value, purpose = "otp_login") {
      const v = String(value || "").trim();
      const field = fieldForPurpose(purpose);
      const emailEnabled = field.identifiers?.includes("email") === true;
      const userLoginEnabled =
        purpose === "password_login"
          ? cfg.password_user_login_enabled === true
          : false;
      const passwordPathEnabled =
        purpose === "otp_login" &&
        cfg.password_enabled === true &&
        (cfg.password_phone_enabled === true ||
          cfg.password_email_enabled === true);
      if (v.includes("@")) {
        return (emailEnabled || passwordPathEnabled) && isEmail(v)
          ? { valid: true, type: "email", value: v }
          : { valid: false, type: "email" };
      }
      if (
        (phoneEnabledForPurpose(purpose) || passwordPathEnabled) &&
        isPhone(v)
      ) {
        return { valid: true, type: "phone", value: v };
      }
      if (userLoginEnabled && /^[^\s@]{1,60}$/.test(v)) {
        return { valid: true, type: "user_login", value: v };
      }
      return { valid: false, type: "identifier" };
    }

    function showIdentifierError(purpose = "otp_login") {
      const input =
        purpose === "password_reset"
          ? $("[data-forgot-identifier]", root)
          : purpose === "password_login"
            ? $("[data-password-identifier]", root)
            : $("[data-identifier]", root);
      if (!input) return;
      const raw = String(input.value || "").trim();
      if (raw === "") {
        setInlineError(
          input,
          i18n("required", "Please do not leave this field empty."),
        );
        input.focus();
        return;
      }
      const emailEnabled =
        purpose === "password_reset"
          ? cfg.reset_email_enabled === true
          : purpose === "password_login"
            ? cfg.password_email_enabled === true
            : cfg.otp_login_email_enabled === true;
      const userLoginEnabled =
        purpose === "password_login"
          ? cfg.password_user_login_enabled === true
          : false;
      const field = fieldForPurpose(purpose);
      const message = userLoginEnabled
        ? i18n(
            "invalid_phone_email_or_username",
            "Please enter a valid mobile number, email address, or username.",
          )
        : emailEnabled
          ? i18n(
              "invalid_phone_or_email",
              "Please enter a valid mobile number or email address.",
            )
          : i18n("invalid_phone", "Please enter a valid mobile number.");
      setInlineError(input, message);
      input.focus();
    }

    function showSuccessAndRedirect(message, url) {
      notice(message, "success", { duration: 20000 });
      if (url) window.setTimeout(() => window.location.assign(url), 800);
    }

    function showPasswordStageAfterReset() {
      state.verificationToken = "";
      state.challengeId = "";
      state.identifier = "";
      const passwordIdentifier = $("[data-password-identifier]", root);
      const resetIdentifier = $("[data-forgot-identifier]", root);
      if (passwordIdentifier && resetIdentifier)
        passwordIdentifier.value = resetIdentifier.value;
      show("password", { pushHistory: true, focus: true });
      notice(
        i18n("password_reset_success", "Your password was reset successfully."),
        "success",
        { duration: 20000 },
      );
    }

    async function sendOtp(
      forceResend = false,
      purpose = "otp_login",
      action = "",
    ) {
      if (state.busy) return;

      let identifier = state.identifier;

      if (forceResend) {
        if (!canResendOtp() || !state.identifier || !state.purpose) {
          syncResendButton();
          return;
        }
        purpose = state.purpose;
        identifier = state.identifier;
      } else {
        const input =
          purpose === "password_reset"
            ? $("[data-forgot-identifier]", root)
            : $("[data-identifier]", root);
        const raw = input?.value || "";
        const value = identifierValidation(raw, purpose);
        if (!value.valid) {
          showIdentifierError(purpose);
          input?.focus();
          return;
        }
        identifier = value.value;

        if (
          purpose === "otp_login" &&
          value.type === "email" &&
          cfg.otp_login_email_enabled !== true
        ) {
          if (cfg.password_email_enabled === true) {
            const passwordIdentifier = $("[data-password-identifier]", root);
            if (passwordIdentifier) passwordIdentifier.value = value.value;
            state.identifier = value.value;
            show("password", { pushHistory: true, focus: true });
            return;
          }
          showIdentifierError("otp_login");
          return;
        }
      }

      state.identifier = identifier;
      state.purpose = purpose;
      if (forceResend) {
        state.resendAvailable = false;
        state.timerEndsAt = Number.POSITIVE_INFINITY;
        const resend = $('[data-action="resend"]', root);
        if (resend) {
          resend.hidden = false;
          resend.disabled = true;
          resend.setAttribute("aria-disabled", "true");
          resend.dataset.resendReady = "0";
        }
      }
      setBusy(
        true,
        action ||
          (forceResend
            ? "resend"
            : purpose === "password_reset"
              ? "forgot-send"
              : "continue-identifier"),
      );
      try {
        const data = await request(
          "send_otp",
          {
            identifier,
            purpose,
            force_resend: forceResend ? "1" : "0",
          },
          root,
          cfg,
          i18n,
        );

        const deliveryStatus = String(data.delivery_status || "");
        const legacyPendingDelivery =
          deliveryStatus === "queued" || deliveryStatus === "retrying";

        if (
          !data.challenge_id ||
          !Array.isArray(data.channels) ||
          data.channels.length === 0 ||
          (deliveryStatus !== "delivered" && !legacyPendingDelivery)
        ) {
          const err = new Error(
            i18n(
              "delivery_failed",
              "The verification code could not be sent. Please try again in a few minutes.",
            ),
          );
          err.code = "delivery_failed";
          throw err;
        }

        state.identifier = identifier;
        state.purpose = purpose;
        clearDeliveryStatusPoll();
        state.challengeId = String(data.challenge_id);
        state.expiresAt = Date.now() + Math.max(0, Number(data.expires_in || 0)) * 1000;
        const retryAfter = Math.max(0, Number(data.retry_after || 0));
        state.resendAvailable = retryAfter <= 0;
        const information = Object.assign({}, data.information || {});
        show("otp", {
          pushHistory: true,
          retryAfter,
          focus: true,
        });
        renderInformation(information);
        if (legacyPendingDelivery) {
          scheduleDeliveryStatusPoll(state.challengeId, deliveryStatus === "retrying" ? 1500 : 1000);
        }
        const deliveryNotice = forceResend
          ? i18n("otp_resent", "A new verification code has been sent.")
          : i18n("otp_sent", "The verification code has been sent.");
        notice(
          data.restored && !legacyPendingDelivery
            ? template(
                i18n(
                  "restored",
                  "The code sent to you remains valid for {valid_minutes} minutes. If you have not received it, use Resend code.",
                ),
              )
            : deliveryNotice,
          "success",
        );
      } catch (error) {
        if (state.step !== "otp") state.challengeId = "";
        if (forceResend && state.step === "otp") {
          const retryAfter = Math.max(0, Number(error?.payload?.retry_after || 0));
          if (retryAfter > 0) {
            startTimer(retryAfter);
          } else if (state.challengeId) {
            state.resendAvailable = true;
            state.timerEndsAt = 0;
            const timer = $("[data-otp-timer]", root);
            if (timer) {
              timer.hidden = true;
              timer.textContent = "";
            }
            syncResendButton();
          }
        }
        if (!applyServerError(error)) {
          notice(
            error.code === "send_rate_limited"
              ? i18n(
                  "too_many_requests",
                  "The number of requests exceeds the allowed limit.",
                )
              : error.message ||
                  i18n(
                    "generic_error",
                    "A server error occurred. Please try again in a few minutes.",
                  ),
            error.code === "send_rate_limited" ? "warning" : "error",
          );
        }
      } finally {
        setBusy(false);
        if (forceResend && state.step === "otp") syncResendButton();
      }
    }

    function otpValue() {
      refreshStructure();
      return otpInputs
        .map((input) => normalizeOtpCode(input.value, 1))
        .join("")
        .slice(0, otpLength);
    }

    function setOtpValue(value, focusIndex = -1) {
      refreshStructure();
      const code = normalizeOtpCode(value);
      otpInputs.forEach((input, index) => {
        input.value = code[index] || "";
        input.classList.remove("is-invalid");
        input.setAttribute("aria-invalid", "false");
      });
      if (focusIndex >= 0 && otpInputs[focusIndex]) otpInputs[focusIndex].focus();
      return code;
    }

    function clearOtpError() {
      if (!otpInput) return;
      clearInlineError(otpInput);
      otpStep?.classList.remove("is-success");
      otpInputs.forEach((input) => {
        input.classList.remove("is-invalid");
        input.setAttribute("aria-invalid", "false");
      });
    }

    function clearOtpValue(focus = false) {
      otpInputs.forEach((input) => {
        input.value = "";
        input.classList.remove("is-success");
        input.setAttribute("aria-invalid", "false");
      });
      otpStep?.classList.remove("is-success");
      if (focus) otpInputs[0]?.focus();
    }

    function markOtpSuccess() {
      if (!otpStep) return;
      otpStep.classList.add("is-success");
      otpInputs.forEach((input) => {
        input.classList.remove("is-invalid");
        input.classList.add("is-success");
        input.setAttribute("aria-invalid", "false");
      });
      clearInlineError(otpInput);
    }

    async function verifyOtp() {
      if (state.busy || state.autoVerifyLocked) return;
      const code = otpValue();
      if (code.length !== otpLength) {
        setInlineError(
          otpInput,
          template(
            i18n(
              "otp_incomplete",
              "The verification code is incomplete. Enter all {otp_length} digits.",
            ),
          ),
        );
        otpInput?.focus();
        return;
      }
      if (!state.challengeId) {
        notice(
          i18n(
            "session_invalid",
            "Your verification session is no longer available. Please request a new code.",
          ),
          "error",
        );
        return;
      }
      state.autoVerifyLocked = true;
      setBusy(true, "verify-otp");
      try {
        const data = await request(
          "verify_otp",
          {
            challenge_id: state.challengeId,
            code,
            identifier: state.identifier,
          },
          root,
          cfg,
          i18n,
        );
        state.verificationToken = data.verification_token || "";
        markOtpSuccess();
        if (data.purpose === "password_reset") {
          show("reset", { focus: true });
        } else if (data.next_stage === "login" || data.user_exists === true) {
          const login = await request(
            "otp_login",
            {
              verification_token: state.verificationToken,
              return_to: cfg.return_to || "",
            },
            root,
            cfg,
            i18n,
          );
          showSuccessAndRedirect(
            i18n("login_success", "You have signed in successfully."),
            login.redirect || window.location.href,
          );
          return;
        } else {
          show("register", { focus: true });
          renderRegistrationInformation(data);
        }
      } catch (error) {
        state.autoVerifyLocked = false;
        if (error.code === "invalid_otp") {
          clearOtpValue(true);
          clearOtpError();
        }
        if (!applyServerError(error)) {
          notice(
            error.code === "otp_locked" || error.code === "verify_rate_limited"
              ? i18n(
                  "too_many_requests",
                  "The number of requests exceeds the allowed limit.",
                )
              : error.message ||
                  i18n("otp_invalid", "The verification code is incorrect."),
            error.code === "otp_locked" || error.code === "verify_rate_limited"
              ? "warning"
              : "error",
          );
        }
      } finally {
        setBusy(false);
      }
    }

    function renderRegistrationInformation(data) {
      const host = infoHost();
      if (!host) return;
      const message = infoTemplates.registration || data?.information || "";
      if (!message) {
        host.hidden = true;
        host.replaceChildren();
        return;
      }
      host.hidden = false;
      host.replaceChildren();
      appendInformationItem(host, message);
      renderStageTemplates();
    }

    async function passwordLogin() {
      const identifier = $("[data-password-identifier]", root);
      const password = $("[data-login-password]", root);
      const value = passwordIdentifierValidation(identifier?.value || "");
      if (!value.valid) {
        showIdentifierError("password_login");
        identifier?.focus();
        return;
      }
      clearInlineError(identifier);
      clearInlineError(password);
      if (!password?.value) {
        setInlineError(
          password,
          i18n("required", "Please do not leave this field empty."),
        );
        password?.focus();
        return;
      }
      setBusy(true, "password-login");
      try {
        const data = await request(
          "password_login",
          {
            identifier: value.value,
            password: password.value,
            return_to: cfg.return_to || "",
          },
          root,
          cfg,
          i18n,
        );
        showSuccessAndRedirect(
          i18n("login_success", "You have signed in successfully."),
          data.redirect || window.location.href,
        );
      } catch (error) {
        if (!applyServerError(error))
          notice(
            error.message ||
              i18n(
                "generic_error",
                "A server error occurred. Please try again in a few minutes.",
              ),
            "error",
          );
      } finally {
        setBusy(false);
      }
    }

    function passwordIdentifierValidation(value) {
      const emailEnabled = cfg.password_email_enabled === true;
      const phoneEnabled = cfg.password_phone_enabled === true;
      const userLoginEnabled = cfg.password_user_login_enabled === true;
      const v = String(value || "").trim();
      if (v.includes("@"))
        return emailEnabled && isEmail(v)
          ? { valid: true, type: "email", value: v }
          : { valid: false, type: "email" };
      if (phoneEnabled && isPhone(v))
        return { valid: true, type: "phone", value: v };
      if (userLoginEnabled && /^[^\s@]{1,60}$/.test(v))
        return { valid: true, type: "user_login", value: v };
      return { valid: false, type: "identifier" };
    }

    async function register() {
      const firstName = $("[data-first-name]", root);
      const lastName = $("[data-last-name]", root);
      const email = $("[data-register-email]", root);
      const password = $("[data-register-password]", root);
      const required = (name) =>
        cfg.registration_fields?.[name]?.required === true;
      clearInlineError(firstName);
      clearInlineError(lastName);
      clearInlineError(email);
      clearInlineError(password);
      if (required("first_name") && !firstName?.value.trim()) {
        setInlineError(firstName, i18n("required", "Please do not leave this field empty."));
        firstName?.focus();
        return;
      }
      if (required("last_name") && !lastName?.value.trim()) {
        setInlineError(lastName, i18n("required", "Please do not leave this field empty."));
        lastName?.focus();
        return;
      }
      if (required("email") && !email?.value.trim()) {
        setInlineError(
          email,
          i18n("required", "Please do not leave this field empty."),
        );
        email?.focus();
        return;
      }
      if (required("password") && !password?.value) {
        setInlineError(
          password,
          i18n("required", "Please do not leave this field empty."),
        );
        password?.focus();
        return;
      }
      if (email?.value && !isEmail(email.value)) {
        setInlineError(
          email,
          i18n("invalid_email", "Please enter a valid email address."),
        );
        email?.focus();
        return;
      }
      if (password?.value && password.value.length < 8) {
        setInlineError(
          password,
          i18n(
            "weak_password",
            "Please choose a password with at least 8 characters.",
          ),
        );
        password?.focus();
        return;
      }
      setBusy(true, "register");
      try {
        const data = await request(
          "register",
          {
            verification_token: state.verificationToken,
            phone: state.identifier,
            first_name: firstName?.value || "",
            last_name: lastName?.value || "",
            email: email?.value || "",
            password: password?.value || "",
            return_to: cfg.return_to || "",
          },
          root,
          cfg,
          i18n,
        );
        showSuccessAndRedirect(
          i18n(
            "registration_success",
            "Your account was created successfully.",
          ),
          data.redirect || window.location.href,
        );
      } catch (error) {
        if (!applyServerError(error))
          notice(
            error.message ||
              i18n(
                "generic_error",
                "A server error occurred. Please try again in a few minutes.",
              ),
            "error",
          );
      } finally {
        setBusy(false);
      }
    }

    async function resetPassword() {
      const password = $("[data-new-password]", root);
      clearInlineError(password);
      if (!password?.value) {
        setInlineError(
          password,
          i18n("required", "Please do not leave this field empty."),
        );
        password?.focus();
        return;
      }
      if (password.value.length < 8) {
        setInlineError(
          password,
          i18n(
            "weak_password",
            "Please choose a password with at least 8 characters.",
          ),
        );
        password?.focus();
        return;
      }
      setBusy(true, "reset-password");
      try {
        const data = await request(
          "reset_password",
          {
            verification_token: state.verificationToken,
            password: password.value,
            return_to: cfg.return_to || "",
          },
          root,
          cfg,
          i18n,
        );
        if (data.next_stage === "password") {
          showPasswordStageAfterReset();
        } else {
          showSuccessAndRedirect(
            i18n(
              "password_reset_success",
              "Your password was reset successfully.",
            ),
            data.redirect || window.location.href,
          );
        }
      } catch (error) {
        if (!applyServerError(error))
          notice(
            error.message ||
              i18n(
                "generic_error",
                "A server error occurred. Please try again in a few minutes.",
              ),
            "error",
          );
      } finally {
        setBusy(false);
      }
    }

    async function googleLogin(credential) {
      if (!credential || state.busy) return;
      setBusy(true);
      try {
        const data = await request(
          "google_login",
          { credential, google_nonce: cfg.google_nonce || "", return_to: cfg.return_to || "" },
          root,
          cfg,
          i18n,
        );
        showSuccessAndRedirect(
          i18n("login_success", "You have signed in successfully."),
          data.redirect || window.location.href,
        );
      } catch (error) {
        notice(
          error.message ||
            i18n(
              "generic_error",
              "A server error occurred. Please try again in a few minutes.",
            ),
          "error",
        );
      } finally {
        setBusy(false);
      }
    }

    function initGoogle() {
      const host = $("[data-google-login]", root);
      if (!host || cfg.google_enabled !== true || !cfg.google_client_id) return;
      let attempts = 0;
      let lastWidth = 0;
      const render = () => {
        if (!window.google?.accounts?.id) {
          if (attempts++ < 50) window.setTimeout(render, 200);
          return;
        }
        const container = host.parentElement;
        const width = Math.min(
          400,
          Math.max(200, Math.round(container?.clientWidth || 360)),
        );
        if (width === lastWidth) return;
        lastWidth = width;
        host.innerHTML = "";
        window.google.accounts.id.initialize({
          client_id: cfg.google_client_id,
          nonce: cfg.google_nonce || "",
          callback: (response) => googleLogin(response.credential),
        });
        window.google.accounts.id.renderButton(host, {
          theme: "outline",
          size: "large",
          width,
          text: "continue_with",
        });
      };
      render();
      let resizeTimer = 0;
      window.addEventListener("resize", () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(render, 200);
      });
    }

    function abortWebOtp() {
      state.webOtpGeneration += 1;
      try {
        state.webOtpController?.abort();
      } catch (error) {}
      state.webOtpController = null;
    }

    async function startWebOtp() {
      if (
        cfg.web_otp_enabled !== true ||
        !window.isSecureContext ||
        !("OTPCredential" in window) ||
        !navigator.credentials?.get
      )
        return;
      abortWebOtp();
      const generation = state.webOtpGeneration;
      const challengeId = state.challengeId;
      const controller = new AbortController();
      state.webOtpController = controller;
      try {
        const credential = await navigator.credentials.get({
          otp: { transport: ["sms"] },
          signal: controller.signal,
        });
        if (
          credential?.code &&
          state.step === "otp" &&
          state.challengeId === challengeId &&
          state.webOtpGeneration === generation
        ) {
          const code = normalizeOtpCode(credential.code);
          if (otpInput) {
            setOtpValue(code, -1);
            clearOtpError();
          }
          if (code.length === otpLength) verifyOtp();
        }
      } catch (error) {}
    }

    const writeOtpDigits = (startIndex, digits) => {
      refreshStructure();
      const normalized = normalizeOtpCode(digits, otpInputs.length - startIndex);
      if (!normalized) return false;
      for (let offset = 0; offset < normalized.length; offset++) {
        const target = otpInputs[startIndex + offset];
        if (!target) break;
        target.value = normalized[offset];
        target.classList.remove("is-invalid");
        target.setAttribute("aria-invalid", "false");
      }
      const nextIndex = Math.min(startIndex + normalized.length, otpInputs.length - 1);
      otpInputs[nextIndex]?.focus();
      return true;
    };

    const boundOtpInputs = new WeakSet();
    const bindOtpInputs = () => {
      refreshStructure();
      otpInputs.forEach((input, index) => {
        if (boundOtpInputs.has(input)) return;
        boundOtpInputs.add(input);
        input.addEventListener("beforeinput", (event) => {
          if (!event.data) return;

          if (event.inputType === "insertText" && /^[0-9]$/.test(event.data))
            return;
          event.preventDefault();
          if (writeOtpDigits(index, event.data)) {
            clearOtpError();
            if (otpValue().length === otpLength) verifyOtp();
          }
        });

      input.addEventListener("input", () => {
        const raw = String(input.value || "");
        clearOtpError();
        if (raw.length > 1) {
          if (writeOtpDigits(index, raw)) {
            if (otpValue().length === otpLength) verifyOtp();
          }
          return;
        }
        const value = normalizeOtpCode(raw, 1);
        input.value = value;
        if (value && index < otpInputs.length - 1) otpInputs[index + 1].focus();
        if (otpValue().length === otpLength) verifyOtp();
      });

      input.addEventListener("paste", (event) => {
        event.preventDefault();
        const pasted = event.clipboardData?.getData("text") || "";
        if (!writeOtpDigits(index, pasted)) return;
        clearOtpError();
        if (otpValue().length === otpLength) verifyOtp();
      });

      input.addEventListener("keydown", (event) => {
        if (event.key === "ArrowLeft" && index > 0) {
          event.preventDefault();
          otpInputs[index - 1].focus();
        } else if (event.key === "ArrowRight" && index < otpInputs.length - 1) {
          event.preventDefault();
          otpInputs[index + 1].focus();
        } else if (event.key === "Home") {
          event.preventDefault();
          otpInputs[0]?.focus();
        } else if (event.key === "End") {
          event.preventDefault();
          otpInputs[otpInputs.length - 1]?.focus();
        } else if (event.key === "Backspace") {
          if (!input.value && index > 0) {
            event.preventDefault();
            otpInputs[index - 1].value = "";
            otpInputs[index - 1].focus();
            clearOtpError();
          } else {
            window.setTimeout(() => {
              input.value = normalizeOtpCode(input.value, 1);
              clearOtpError();
            }, 0);
          }
        } else if (event.key === "Delete") {
          event.preventDefault();
          input.value = "";
          clearOtpError();
        }
        });
      });
    };
    bindOtpInputs();

    form?.addEventListener("keydown", (event) => {
      if (
        event.key !== "Enter" ||
        event.shiftKey ||
        event.ctrlKey ||
        event.metaKey
      )
        return;
      const target = event.target;
      if (
        !(target instanceof HTMLElement) ||
        !target.matches("input,select,textarea")
      )
        return;
      if (target.closest('[data-step="otp"]')) return;
      const active = target.closest("[data-step]");
      if (!active || active !== activeStep()) return;
      const fields = $$(
        'input:not([type="hidden"]),select,textarea',
        active,
      ).filter((field) => isInteractiveField(field, active));
      const index = fields.indexOf(target);
      if (index >= 0 && index < fields.length - 1) {
        event.preventDefault();
        fields[index + 1].focus();
      } else {
        event.preventDefault();
        $('button[type="submit"]', active)?.click();
      }
    });

    function continueIdentifier() {
      const input = $("[data-identifier]", root);
      const value = identifierValidation(input?.value || "", "otp_login");
      if (!value.valid) {
        showIdentifierError("otp_login");
        input?.focus();
        return;
      }
      state.identifier = value.value;
      if (value.type === "email") {
        if (cfg.otp_login_email_enabled === true) {
          return sendOtp(false, "otp_login", "continue-identifier");
        }
        if (cfg.password_email_enabled === true) {
          const passwordIdentifier = $("[data-password-identifier]", root);
          if (passwordIdentifier) passwordIdentifier.value = value.value;
          return show("password", { pushHistory: true, focus: true });
        }
        showIdentifierError("otp_login");
        return;
      }
      if (cfg.otp_login_phone_enabled === true)
        return sendOtp(false, "otp_login", "continue-identifier");
      if (cfg.password_phone_enabled === true) {
        const passwordIdentifier = $("[data-password-identifier]", root);
        if (passwordIdentifier) passwordIdentifier.value = value.value;
        return show("password", { pushHistory: true, focus: true });
      }
      showIdentifierError("otp_login");
    }

    root.addEventListener(
      "pointerdown",
      () => {
        viewportFocusState.lastGestureAt = Date.now();
      },
      { passive: true },
    );

    form?.addEventListener("submit", (event) => {
      event.preventDefault();
      if (state.busy) return;
      const active = activeStep();
      const action =
        $('button[type="submit"][data-action]', active)?.dataset.action ||
        $("[data-action]", active)?.dataset.action;
      if (action === "continue-identifier") continueIdentifier();
      else if (action === "password-login") passwordLogin();
      else if (action === "verify-otp") verifyOtp();
      else if (action === "register") register();
      else if (action === "forgot-send")
        sendOtp(false, "password_reset", "forgot-send");
      else if (action === "reset-password") resetPassword();
    });

    root.addEventListener("click", (event) => {
      const target = event.target;
      const button = target?.closest?.("[data-action]");
      if (!button || !root.contains(button)) return;
      const action = button.dataset.action;
      if (action === "show-password") {
        const input = $("[data-identifier]", root);
        const raw = input?.value || "";
        const passwordIdentifier = $("[data-password-identifier]", root);
        const value = passwordIdentifierValidation(raw);
        if (value.valid) {
          state.identifier = value.value;
          if (passwordIdentifier) passwordIdentifier.value = value.value;
        } else if (passwordIdentifier) {
          passwordIdentifier.value = "";
        }
        show("password", { pushHistory: true, focus: true });
      } else if (action === "show-forgot") {
        show("forgot", {
          pushHistory: true,
          focus: true,
          allowTransition: true,
        });
      } else        if (action === "resend") {
        event.preventDefault();
        event.stopPropagation();
        if (!canResendOtp()) return;
        void sendOtp(true, state.purpose, "resend");
      }
    });

    root.addEventListener("focusin", (event) => {
      const field = event.target;
      const stage = field?.closest?.("[data-step]");
      if (!isInteractiveField(field, stage) || stage !== activeStep()) return;
      if (viewportFocusState.focused !== field) {
        viewportFocusState.focused = field;
        if (viewportFocusState.baselineScrollY == null) {
          viewportFocusState.baselineScrollY = window.scrollY;
          viewportFocusState.baselineViewportHeight =
            window.innerHeight || document.documentElement.clientHeight;
          viewportFocusState.userScrolled = false;
        }
        viewportFocusState.lastManagedScrollY = null;
        viewportFocusState.ignoreScrollUntil = Date.now() + 500;
      }
      scheduleFieldVisibility(field);
    });
    window.addEventListener("scroll", () => {
      if (!keyboardIsOpen() || Date.now() < viewportFocusState.ignoreScrollUntil) return;
      if (
        viewportFocusState.lastManagedScrollY == null ||
        Math.abs(window.scrollY - viewportFocusState.lastManagedScrollY) > 2
      )
        viewportFocusState.userScrolled = true;
    }, { passive: true });
    const handleViewportChange = () => {
      const open = keyboardIsOpen();
      if (open) {
        viewportFocusState.keyboardOpen = true;
        scheduleFieldVisibility(viewportFocusState.focused, [0, 100, 260]);
        return;
      }
      if (
        viewportFocusState.keyboardOpen &&
        !viewportFocusState.userScrolled &&
        viewportFocusState.baselineScrollY != null &&
        typeof window.scrollTo === "function"
      ) {
        window.scrollTo({ top: viewportFocusState.baselineScrollY, left: window.scrollX, behavior: "auto" });
      }
      viewportFocusState.keyboardOpen = false;
      viewportFocusState.baselineViewportHeight = null;
      viewportFocusState.baselineScrollY = null;
      viewportFocusState.lastManagedScrollY = null;
    };
    window.addEventListener("resize", handleViewportChange, { passive: true });
    visualViewport?.addEventListener("resize", handleViewportChange, { passive: true });
    visualViewport?.addEventListener("scroll", () => scheduleFieldVisibility(viewportFocusState.focused, [0, 120]), { passive: true });

    const observeDynamicFields = () => {
      if (typeof MutationObserver !== "function") return;
      const observer = new MutationObserver(() => {
        refreshStructure();
        bindFieldInputs();
        bindOtpInputs();
        if (activeStep()) focusActiveStageField();
      });
      observer.observe(root, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ["hidden", "disabled", "aria-hidden", "inert", "class"],
      });
    };
    observeDynamicFields();

    root.addEventListener("peyvast_auth:back", () => back());
    window.addEventListener("popstate", (event) => {
      if (!ownsHistoryState(event.state)) return;
      const stage = String(event.state.stage || "phone");
      if (!steps.some((step) => step.dataset.step === stage)) return;
      show(stage, { pushHistory: false, focus: true });
    });

    if (cfg.preview_only === true) {
      window.setTimeout(() => focusActiveStageField({ force: true }), 0);
      return;
    }
    if (!ownsHistoryState(window.history.state)) {
      window.history.replaceState(historyState(), "", window.location.href);
    }
    show("phone", { pushHistory: false, focus: true });
    initGoogle();
  }

  let backButtonsBound = false;
  const authTargetForBackButton = (button, selector) => {
    if (selector) {
      try {
        const selected = document.querySelector(selector);
        if (selected?.matches?.("[data-peyvast-auth]")) return selected;
        const nested = selected?.querySelector?.("[data-peyvast-auth]");
        if (nested) return nested;
      } catch (error) {}
    }
    const closest = button.closest("[data-peyvast-auth]");
    if (closest) return closest;
    let scope = button.parentElement;
    while (scope && scope !== document.body) {
      const roots = scope.querySelectorAll("[data-peyvast-auth]");
      if (roots.length === 1) return roots[0];
      scope = scope.parentElement;
    }
    const roots = document.querySelectorAll("[data-peyvast-auth]");
    return roots.length === 1 ? roots[0] : null;
  };
  const bindBackButtons = () => {
    if (backButtonsBound) return;
    backButtonsBound = true;
    document.addEventListener("click", (event) => {
      const button = event.target.closest?.(
        '.peyvast-auth-back[data-peyvast-auth-action="back"]',
      );
      if (!button) return;
      const selector = button.dataset.peyvastAuthTarget || "";
      const target = authTargetForBackButton(button, selector);
      if (target)
        target.dispatchEvent(
          new CustomEvent("peyvast_auth:back", { bubbles: true }),
        );
    });
  };
  const init = () => {
    bindBackButtons();
    $$("[data-peyvast-auth]").forEach(boot);
  };
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
  window.PEYVAST_AUTH = window.PEYVAST_AUTH || { init };
  window.PEYVAST_AUTH_BACK =
    window.PEYVAST_AUTH_BACK ||
    function (root) {
      const event = new CustomEvent("peyvast_auth:back", { bubbles: true });
      root?.dispatchEvent(event);
    };
})();
