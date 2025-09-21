(function ($) {
  "use strict";

  // Chat Widget Class
  class FluxaChatWidget {
    constructor(options) {
      this.settings = {
        ajaxUrl: "",
        settings: {},
        i18n: {},
        ...options,
      };

      this.elements = {
        container: null,
        widget: null,
        launchButton: null,
        closeButton: null,
        messagesContainer: null,
        input: null,
        form: null,
        typingIndicator: null,
        suggestions: null,
      };

      this.state = {
        isOpen: false,
        isMinimized: false,
        isTyping: false,
        messageHistory: [],
        historyIndex: -1,
        _historyPolling: false,
        // Inactivity tracking
        _lastActivityTs: Date.now(),
        _inactivityTimer: null,
        _alertShown: false, // deprecated: replaced by feedback card
        _feedbackShown: false,
      };
      this._prevMinimized = null;
      this._animating = false;

      // Base measurements
      this.metrics = {
        baseBottom: 20, // will be read from computed style
        launcherSize: 60,
        gapAboveLauncher: 20,
      };

      this.init();
    }

    /**
     * Position the chat widget directly above the launcher, using viewport coords.
     * Keeps the visual relation even if the container was dragged.
     */
    alignWidgetToLauncher() {
      /* disabled: keep original CSS-based positioning */
    }

    /**
     * Allow dragging the launcher to reposition the chat container temporarily.
     * This does NOT persist; on refresh, saved settings take effect again.
     */
    enableLauncherDrag() {
      /* disabled: draggable feature removed */
    }

    pingConversationIfExists() {
      try {
        if (typeof fluxaChatbot === "undefined") return;
        if (Number(fluxaChatbot.ping_on_pageload) !== 1) return;
        const url = fluxaChatbot.rest_track
          ? String(fluxaChatbot.rest_track)
          : "";
        if (!url) return;
        const conv = (function () {
          try {
            return localStorage.getItem("fluxa_conversation_uuid") || "";
          } catch (e) {
            return "";
          }
        })();
        if (!conv) return;
        // Prepare ss_user_id (UUID only)
        let ssUser = (function () {
          try {
            return localStorage.getItem("fluxa_uid_value") || "";
          } catch (e) {
            return "";
          }
        })();
        try {
          const m = String(ssUser || "").match(
            /[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/
          );
          if (m && m[0]) ssUser = m[0];
          else ssUser = String(ssUser || "").split(".")[0];
        } catch (_) {}
        // Best-effort Woo session key
        let wcKey = "";
        try {
          const cookies = document.cookie ? document.cookie.split(";") : [];
          for (let i = 0; i < cookies.length; i++) {
            const c = cookies[i].trim();
            if (!c) continue;
            const eq = c.indexOf("=");
            if (eq === -1) continue;
            const name = c.slice(0, eq);
            if (name.indexOf("wp_woocommerce_session_") === 0) {
              const val = decodeURIComponent(c.slice(eq + 1));
              const parts = val.split("||");
              if (parts[0]) {
                wcKey = parts[0];
                break;
              }
            }
          }
        } catch (_) {}
        if (!wcKey) {
          try {
            wcKey =
              typeof fluxaChatbot !== "undefined" && fluxaChatbot.wc_session_key
                ? String(fluxaChatbot.wc_session_key)
                : "";
          } catch (_) {}
        }
        const payload = {
          conversation_id: String(conv),
          ss_user_id: String(ssUser || ""),
          wc_session_key: wcKey,
        };
        const headers = { "Content-Type": "application/json" };
        if (fluxaChatbot.nonce) {
          headers["X-WP-Nonce"] = fluxaChatbot.nonce;
        }
        fetch(url, {
          method: "POST",
          headers,
          credentials: "same-origin",
          body: JSON.stringify(payload),
        })
          .then(() => {})
          .catch(() => {});
      } catch (_) {}
    }

    /**
     * Sync initial state from DOM (handles server-rendered minimized class)
     */
    syncStateFromDOM() {
      if (!this.elements || !this.elements.widget) return;

      const isMin = this.elements.widget.classList.contains(
        "fluxa-chat-widget--minimized"
      );
      this.state.isMinimized = isMin;
      this.state.isOpen = !isMin;
      this._prevMinimized = isMin;
    }

    /**
     * Initialize the chat widget
     */
    init() {
      this.cacheElements();
      this.syncStateFromDOM();
      this.bindEvents();
      // Drag feature disabled
      this.enableLauncherDrag();
      // Initial history load (no retry)
      this.loadRemoteHistory({ retry: false });
      this.loadMessageHistory();
      this.applySuggestionsHiddenState();
      // Mark JS readiness to allow CSS to reveal suggestions without flash
      try {
        if (document && document.body) {
          document.body.classList.add("fluxa-js-ready");
        }
      } catch (e) {}
      this.render();
      // Best-effort: keep DB in sync on each page view (guarded by admin setting)
      try {
        if (
          typeof fluxaChatbot !== "undefined" &&
          Number(fluxaChatbot.ping_on_pageload) === 1
        ) {
          this.pingConversationIfExists();
        }
      } catch (_) {}
      // Start inactivity timer based on current DOM having any messages
      try {
        if (this.elements && this.elements.messagesContainer) {
          // If there are existing messages rendered server-side, consider now as last activity
          if (
            this.elements.messagesContainer.children &&
            this.elements.messagesContainer.children.length > 0
          ) {
            this._markActivity(Date.now());
          } else {
            // Arm anyway so we can catch inactivity in empty chats
            this._armInactivityAlert();
          }
        }
      } catch (_) {}
    }

    trackConversationIfNeeded(convUuid) {
      try {
        if (!convUuid) return;
        const key = "fluxa_tracked_" + String(convUuid);
        if (localStorage.getItem(key) === "1") return;
        const url =
          typeof fluxaChatbot !== "undefined" && fluxaChatbot.rest_track
            ? fluxaChatbot.rest_track
            : "";
        if (!url) return;
        let ssUser = (function () {
          try {
            return localStorage.getItem("fluxa_uid_value") || "";
          } catch (e) {
            return "";
          }
        })();
        // Extract only the UUID portion if extra data is present
        try {
          const m = String(ssUser || "").match(
            /[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/
          );
          if (m && m[0]) ssUser = m[0];
          else ssUser = String(ssUser || "").split(".")[0];
        } catch (_) {}
        // Best-effort WooCommerce session key (client-side cookie parse)
        let wcKey = "";
        try {
          const cookies = document.cookie ? document.cookie.split(";") : [];
          for (let i = 0; i < cookies.length; i++) {
            const c = cookies[i].trim();
            if (!c) continue;
            const eq = c.indexOf("=");
            if (eq === -1) continue;
            const name = c.slice(0, eq);
            if (name.indexOf("wp_woocommerce_session_") === 0) {
              const val = decodeURIComponent(c.slice(eq + 1));
              const parts = val.split("||");
              if (parts[0]) {
                wcKey = parts[0];
                break;
              }
            }
          }
        } catch (_) {}
        if (!wcKey) {
          try {
            wcKey =
              typeof fluxaChatbot !== "undefined" && fluxaChatbot.wc_session_key
                ? String(fluxaChatbot.wc_session_key)
                : "";
          } catch (_) {}
        }
        const payload = {
          conversation_id: String(convUuid),
          ss_user_id: String(ssUser || ""),
          wc_session_key: wcKey,
        };
        const headers = { "Content-Type": "application/json" };
        if (typeof fluxaChatbot !== "undefined" && fluxaChatbot.nonce) {
          headers["X-WP-Nonce"] = fluxaChatbot.nonce;
        }
        const dbg = (() => {
          try {
            return !!window.FLUXA_DEBUG;
          } catch (_) {
            return false;
          }
        })();
        fetch(url, {
          method: "POST",
          headers,
          credentials: "same-origin",
          body: JSON.stringify(payload),
        })
          .then(async (r) => {
            const status = r.status;
            const raw = await r.text();
            let data;
            try {
              data = JSON.parse(raw);
            } catch (e) {
              data = raw;
            }
            if (dbg) {
              try {
                console.groupCollapsed(
                  "[Fluxa] REST /conversation/track response"
                );
                console.log("request.url", url);
                console.log("request.payload", payload);
                console.log("response.status", status);
                console.log("response.raw", raw);
                console.log("response.body", data);
                console.groupEnd();
              } catch (_) {}
            }
            if (data && data.ok) {
              try {
                localStorage.setItem(key, "1");
              } catch (e) {}
            }
          })
          .catch((err) => {
            if (dbg) {
              try {
                console.warn("Fluxa track conversation failed", err);
              } catch (_) {}
            }
          });
      } catch (_) {}
    }

    /**
     * Play an Animate.css animation on the widget and cleanup after end
     */
    playAnimation(name, opts = {}) {
      const el = this.elements && this.elements.widget;
      if (!el) return;
      const { onEnd, duration } = opts;
      this._animating = true;
      // Remove any previous animate.css classes
      el.classList.remove(
        "fluxa-animating",
        "animate__animated",
        "animate__bounceIn",
        "animate__bounceInUp",
        "animate__backInUp",
        "animate__fadeOutDown",
        "animate__backOutDown"
      );
      el.classList.add("fluxa-animating", "animate__animated", name);
      if (duration) {
        el.style.setProperty("--animate-duration", duration);
      }
      const handler = () => {
        el.classList.remove("fluxa-animating", "animate__animated", name);
        if (duration) el.style.removeProperty("--animate-duration");
        el.removeEventListener("animationend", handler);
        this._animating = false;
        if (typeof onEnd === "function") onEnd();
      };
      el.addEventListener("animationend", handler);
    }

    /**
     * Render widget state to DOM without adjusting position via JS
     */
    render() {
      if (!this.elements || !this.elements.widget) return;

      const wasMin = this._prevMinimized;
      const isMin = !this.state.isOpen || this.state.isMinimized;

      // Opening transition
      if (!isMin) {
        // Ensure minimized is off
        this.elements.widget.classList.remove("fluxa-chat-widget--minimized");
        // Trigger opening animation from saved setting when coming from minimized
        if (wasMin === true) {
          const raw =
            (this.settings &&
              this.settings.settings &&
              this.settings.settings.animation) ||
            "bounceIn";
          // Normalize (remove non-letters and lowercase)
          const key = String(raw)
            .replace(/[^a-z]/gi, "")
            .toLowerCase();
          const map = {
            none: null,
            // Bounce/Back
            bouncein: "animate__bounceIn",
            bounceinup: "animate__bounceInUp",
            bounceinleft: "animate__bounceInLeft",
            bounceinright: "animate__bounceInRight",
            backinup: "animate__backInUp",
            backinleft: "animate__backInLeft",
            backinright: "animate__backInRight",
            // Fade
            fadeinup: "animate__fadeInUp",
            fadeinupbig: "animate__fadeInUpBig",
            fadeinleft: "animate__fadeInLeft",
            fadeinleftbig: "animate__fadeInLeftBig",
            fadeinright: "animate__fadeInRight",
            fadeinrightbig: "animate__fadeInRightBig",
            // Flip
            flipinx: "animate__flipInX",
            flipiny: "animate__flipInY",
            // Light speed
            lightspeedinleft: "animate__lightSpeedInLeft",
            lightspeedinright: "animate__lightSpeedInRight",
            // Special
            jackinthebox: "animate__jackInTheBox",
            rollin: "animate__rollIn",
            // Zoom
            zoomin: "animate__zoomIn",
            zoomindown: "animate__zoomInDown",
            zoominleft: "animate__zoomInLeft",
            zoominright: "animate__zoomInRight",
            zoominup: "animate__zoomInUp",
            // Slide
            slideindown: "animate__slideInDown",
            slideinleft: "animate__slideInLeft",
            slideinright: "animate__slideInRight",
            slideinup: "animate__slideInUp",
          };
          const cls = Object.prototype.hasOwnProperty.call(map, key)
            ? map[key]
            : null;
          if (cls) {
            const dur = /(bounce|back)/.test(key) ? "700ms" : "420ms";
            this.playAnimation(cls, { duration: dur });
          }
          // If cls is null or unrecognized, open instantly with no animation
        }
        // Reflect state on container
        if (this.elements.container) {
          this.elements.container.classList.add("fluxa-chat-container--open");
          this.elements.container.classList.remove(
            "fluxa-chat-container--minimized"
          );
        }
        if (this.elements.launchButton) {
          this.elements.launchButton.classList.remove(
            "fluxa-chat-widget--hidden"
          );
        }
      } else {
        // Closing/minimizing: no animation, collapse immediately
        this.elements.widget.classList.add("fluxa-chat-widget--minimized");
        if (this.elements.container) {
          this.elements.container.classList.add(
            "fluxa-chat-container--minimized"
          );
          this.elements.container.classList.remove(
            "fluxa-chat-container--open"
          );
        }
        if (this.elements.launchButton) {
          this.elements.launchButton.classList.remove(
            "fluxa-chat-widget--hidden"
          );
        }
      }
      this._prevMinimized = isMin;
    }

    /**
     * Cache DOM elements
     */
    cacheElements() {
      this.elements.container = document.querySelector(".fluxa-chat-container");
      this.elements.widget = document.getElementById("fluxa-chat-widget");
      this.elements.launchButton = document.querySelector(
        ".fluxa-chat-widget__launch"
      );
      this.elements.closeButton = document.querySelector(
        ".fluxa-chat-widget__close"
      );
      this.elements.messagesContainer = document.querySelector(
        ".fluxa-chat-widget__messages"
      );
      this.elements.input = document.querySelector(".fluxa-chat-widget__input");
      this.elements.form = document.querySelector(".fluxa-chat-widget__form");
      this.elements.typingIndicator = document.querySelector(
        ".fluxa-chat-widget__typing-indicator"
      );
      this.elements.sendButton = document.querySelector(
        ".fluxa-chat-widget__send"
      );
      // Suggested question buttons (outside the widget, siblings)
      this.elements.suggestions = Array.from(
        document.querySelectorAll(".fluxa-suggestion")
      );
      this.elements.suggestionsContainer = document.querySelector(
        ".fluxa-chat-suggestions"
      );
      this.elements.suggestionsClose = document.querySelector(
        ".fluxa-suggestions__close"
      );

      // Read base bottom from computed style (respects PHP inline style)
      if (this.elements.widget) {
        const cs = window.getComputedStyle(this.elements.widget);
        const bottomPx = parseInt(cs.bottom || "20", 10);
        if (!isNaN(bottomPx)) {
          this.metrics.baseBottom = bottomPx;
        }
      }

      // Read launcher size from DOM to be robust
      if (this.elements.launchButton) {
        const rect = this.elements.launchButton.getBoundingClientRect();
        const size = Math.max(rect.width || 0, rect.height || 0);
        if (size > 0) {
          this.metrics.launcherSize = Math.round(size);
        }
      }
    }

    /**
     * Send message to server (two-phase: decision then final)
     */
    sendMessage(message) {
      const url =
        typeof fluxaChatbot !== "undefined" && fluxaChatbot.rest
          ? fluxaChatbot.rest
          : "";
      if (!url) {
        this.hideTypingIndicator();
        this.addMessage(
          this.settings?.i18n?.error || "Error: REST endpoint missing.",
          "bot"
        );
        try {
          console.error("Fluxa: missing REST url");
        } catch (_) {}
        return;
      }
      const payloadBase = {
        content: String(message || ""),
        skip_chat_history: false,
      };
      const headers = { "Content-Type": "application/json" };
      const dbg = !!(
        typeof fluxaChatbot !== "undefined" && Number(fluxaChatbot.debug) === 1
      );

      // Phase 1: decision (fast interim)
      const decisionPayload = Object.assign({}, payloadBase, {
        phase: "decision",
      });
      fetch(url, {
        method: "POST",
        headers,
        credentials: "same-origin",
        body: JSON.stringify(decisionPayload),
      })
        .then((r) => r.json())
        .then((dec) => {
          try {
            if (dec && dec.ok && dec.mode === "decision") {
              const interim = (dec.interim && String(dec.interim)) || "";
              if (interim) {
                this.addMessage(interim, "bot");
              }
            }
          } catch (_) {}
        })
        .catch(() => {
          /* ignore decision errors */
        })
        .finally(() => {
          // Phase 2: final
          fetch(url, {
            method: "POST",
            headers,
            credentials: "same-origin",
            body: JSON.stringify(payloadBase),
          })
            .then((res) => res.json())
            .then((data) => {
              this.hideTypingIndicator();
              if (data && data.ok) {
                const txt = (data.text && String(data.text)) || "";
                if (txt) {
                  this.addMessage(txt, "bot");
                } else if (data.body && data.body.content) {
                  this.addMessage(String(data.body.content), "bot");
                } else {
                  this.addMessage(
                    this.settings?.i18n?.error || "An error occurred.",
                    "bot"
                  );
                }
                // Try to extract conversation_uuid from completion response body
                try {
                  let convUuid = null;
                  const b = data.body || {};
                  if (b && typeof b === "object") {
                    convUuid =
                      b.conversation_uuid ||
                      (b.message &&
                        (b.message.conversation_uuid ||
                          (b.message.conversation &&
                            b.message.conversation.uuid))) ||
                      (b.conversation && b.conversation.uuid) ||
                      null;
                  }
                  if (convUuid) {
                    try {
                      localStorage.setItem(
                        "fluxa_conversation_uuid",
                        String(convUuid)
                      );
                    } catch (e) {}
                    try {
                      console.info(
                        "[Fluxa] conversation_uuid (from completion):",
                        convUuid
                      );
                    } catch (_) {}
                    this.trackConversationIfNeeded(convUuid);
                  } else {
                    // Fallback: schedule history poll to capture UUID if needed
                    try {
                      const cached =
                        typeof localStorage !== "undefined"
                          ? localStorage.getItem("fluxa_conversation_uuid")
                          : null;
                      if (!cached && !this.state._historyPolling) {
                        this.loadRemoteHistory({
                          retry: true,
                          tries: 6,
                          delayMs: 700,
                        });
                      }
                    } catch (_) {
                      if (!this.state._historyPolling) {
                        this.loadRemoteHistory({
                          retry: true,
                          tries: 6,
                          delayMs: 700,
                        });
                      }
                    }
                  }
                } catch (_) {}
                // Mirror any updated cookie value into storages for consistency across tabs
                try {
                  const m = document.cookie.match(/(?:^|; )fluxa_uid=([^;]+)/);
                  if (m) {
                    const val = decodeURIComponent(m[1]);
                    try {
                      sessionStorage.setItem("fluxa_uid_value", val);
                    } catch (e) {}
                    try {
                      localStorage.setItem("fluxa_uid_value", val);
                    } catch (e) {}
                  }
                } catch (_) {}
                // Persist provisioned UUID if returned
                try {
                  if (data.user_id) {
                    localStorage.setItem(
                      "fluxa_uid_value",
                      String(data.user_id || "")
                    );
                  }
                } catch (_) {}
              } else {
                this.addMessage(
                  this.settings?.i18n?.error || "An error occurred.",
                  "bot"
                );
                if (dbg) {
                  try {
                    console.error("Fluxa chat error", data);
                  } catch (_) {}
                }
              }
            })
            .catch((err) => {
              this.hideTypingIndicator();
              this.addMessage(
                this.settings?.i18n?.error || "An error occurred.",
                "bot"
              );
              if (dbg) {
                try {
                  console.error("Fluxa chat fetch exception", err);
                } catch (_) {}
              }
            });
        });
    }
    addMessage(message, type = "bot") {
      if (!this.elements || !this.elements.messagesContainer) return;
      // Ensure the chat is visible for bot replies only if enabled in settings
      const autoOpen = !!(
        this.settings &&
        this.settings.settings &&
        this.settings.settings.auto_open_on_reply
      );
      const pulseEnabled = !!(
        this.settings &&
        this.settings.settings &&
        this.settings.settings.pulse_on_new
      );
      if (
        type === "bot" &&
        autoOpen &&
        (this.state.isMinimized || !this.state.isOpen)
      ) {
        this.openChat();
      } else if (
        type === "bot" &&
        pulseEnabled &&
        (this.state.isMinimized || !this.state.isOpen)
      ) {
        // If auto-open is disabled, show a subtle pulse/glow on the launcher
        if (this.elements && this.elements.launchButton) {
          this.elements.launchButton.classList.add("has-new");
        }
      }
      const el = document.createElement("div");
      el.className = `fluxa-chat-message fluxa-chat-message--${type}`;
      const now = new Date();
      const time = now.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
      });
      let contentHtml = "";
      const raw = String(message || "");
      const mayContainHtml =
        type === "bot" &&
        /<\s*(table|thead|tbody|tr|th|td|a|strong|em|b)\b/i.test(raw);
      if (mayContainHtml) {
        contentHtml = this.sanitizeHtml(raw);
      } else {
        contentHtml = this.escapeHtml(raw);
      }
      el.innerHTML = `
        <div class="fluxa-chat-message__content">${contentHtml}</div>
        <div class="fluxa-chat-message__time">${time}</div>
      `;
      this.elements.messagesContainer.appendChild(el);
      this.scrollToBottom();
      // Mark last activity and (re)arm inactivity alert
      this._markActivity(Date.now());
    }

    /**
     * Add a message with a specific timestamp without altering auto-open behavior
     */
    addMessageWithTime(message, type = "bot", dateObj) {
      if (!this.elements || !this.elements.messagesContainer) return;
      const el = document.createElement("div");
      el.className = `fluxa-chat-message fluxa-chat-message--${type}`;
      const time =
        dateObj instanceof Date && !isNaN(dateObj.getTime())
          ? dateObj.toLocaleTimeString([], {
              hour: "2-digit",
              minute: "2-digit",
            })
          : new Date().toLocaleTimeString([], {
              hour: "2-digit",
              minute: "2-digit",
            });
      let contentHtml = "";
      const raw = String(message || "");
      const mayContainHtml =
        type === "bot" &&
        /<\s*(table|thead|tbody|tr|th|td|a|strong|em|b)\b/i.test(raw);
      if (mayContainHtml) {
        contentHtml = this.sanitizeHtml(raw);
      } else {
        contentHtml = this.escapeHtml(raw);
      }
      el.innerHTML = `
        <div class="fluxa-chat-message__content">${contentHtml}</div>
        <div class="fluxa-chat-message__time">${time}</div>
      `;
      this.elements.messagesContainer.appendChild(el);
      // Mark last activity using provided timestamp (fallback to now handled in method)
      this._markActivity(
        dateObj instanceof Date ? dateObj.getTime() : Date.now()
      );
    }

    /**
     * Very small sanitizer: allow only a, strong, em, b, table, thead, tbody, tr, th, td.
     * - Removes all attributes except href for anchors (and normalizes it).
     * - Disallows javascript: and data: URLs.
     */
    sanitizeHtml(html) {
      try {
        const allowed = new Set([
          "A",
          "STRONG",
          "EM",
          "B",
          "TABLE",
          "THEAD",
          "TBODY",
          "TR",
          "TH",
          "TD",
        ]);
        const container = document.createElement("div");
        container.innerHTML = String(html || "");

        const isSafeHref = (v) => {
          try {
            const u = new URL(v, window.location.origin);
            return u.protocol === "http:" || u.protocol === "https:";
          } catch (_) {
            return false;
          }
        };

        const walk = (node) => {
          if (!node) return;
          const children = Array.from(node.childNodes || []);
          for (const child of children) {
            if (child.nodeType === 1) {
              // ELEMENT_NODE
              const tag = child.tagName.toUpperCase();
              if (!allowed.has(tag)) {
                // Replace disallowed element with its text content
                const text = document.createTextNode(child.textContent || "");
                child.replaceWith(text);
                continue;
              }
              // Strip all attributes first
              const attrs = Array.from(child.attributes || []);
              for (const a of attrs) {
                child.removeAttribute(a.name);
              }
              // Re-apply only safe attributes
              if (tag === "A") {
                // try to find href in original child (we removed attrs). We can re-parse from outerHTML is complex; fallback: read data-href or inner text URLs
                // Better: find href via a dataset clone before stripping
              }
              // Because we removed attributes, rebuild anchor hrefs by checking a saved map
            }
            // Recurse
            walk(child);
          }
        };

        // Second pass that preserves anchor hrefs safely
        // Build a map of original anchors to hrefs before stripping
        const originalAnchors = Array.from(container.querySelectorAll("a"));
        const hrefs = originalAnchors.map((a) => a.getAttribute("href"));

        walk(container);

        // Re-apply sanitized hrefs to anchors that survived in order, but NEVER inside tables
        const anchorsAfter = Array.from(container.querySelectorAll("a"));
        let i = 0;
        for (const a of anchorsAfter) {
          // If anchor is inside a table, unwrap it (replace with text)
          if (a.closest && a.closest("table")) {
            const txt = document.createTextNode(a.textContent || "");
            a.replaceWith(txt);
            continue;
          }
          const href = hrefs[i++] || "";
          if (href && isSafeHref(href)) {
            a.setAttribute("href", href);
            a.setAttribute("target", "_blank");
            a.setAttribute("rel", "noopener noreferrer");
          }
        }
        return container.innerHTML;
      } catch (_) {
        // Fallback to escaped text if sanitizer fails
        return this.escapeHtml(String(html || ""));
      }
    }

    /**
     * Fetch remote chat history for current visitor and render it
     */
    loadRemoteHistory(opts = {}) {
      const options = Object.assign(
        { retry: false, tries: 5, delayMs: 800 },
        opts
      );
      // Always allow the initial fetch to populate messages. Only use the cached
      // conversation UUID to influence retry polling (handled below), not to skip
      // the initial render fetch.
      if (this.state && this.state._historyLoaded && !options.retry) return; // avoid duplicate non-retry fetch
      const url =
        typeof fluxaChatbot !== "undefined" && fluxaChatbot.rest_history
          ? fluxaChatbot.rest_history
          : "";
      if (!url) return;
      if (options.retry) {
        this.state._historyPolling = true;
      }
      const headers = {};
      if (typeof fluxaChatbot !== "undefined" && fluxaChatbot.nonce) {
        headers["X-WP-Nonce"] = fluxaChatbot.nonce;
      }
      const dbg = (() => {
        try {
          return !!window.FLUXA_DEBUG;
        } catch (_) {
          return false;
        }
      })();
      fetch(url, { headers, credentials: "same-origin" })
        .then(async (r) => {
          const status = r.status;
          const raw = await r.text();
          let data;
          try {
            data = JSON.parse(raw);
          } catch (e) {
            data = raw;
          }
          if (dbg) {
            try {
              console.groupCollapsed("[Fluxa] REST /chat/history response");
              console.log("request.url", url);
              console.log("request.headers", headers);
              console.log("response.status", status);
              console.log("response.raw", raw);
              console.log("response.body", data);
              console.groupEnd();
            } catch (_) {}
          }
          return typeof data === "string" ? { ok: false, raw: data } : data;
        })
        .then((data) => {
          if (!data || !data.ok) return;
          const items = Array.isArray(data.items) ? data.items : [];
          if (!items.length) {
            // If we are allowed to retry and still no conversation_uuid cached, schedule another attempt
            const cached =
              typeof localStorage !== "undefined"
                ? localStorage.getItem("fluxa_conversation_uuid")
                : null;
            if (options.retry && !cached && options.tries > 1) {
              const next = {
                retry: true,
                tries: options.tries - 1,
                delayMs: Math.min(options.delayMs * 1.5, 4000),
              };
              try {
                console.info(
                  "[Fluxa] history empty, retrying in",
                  Math.round(next.delayMs),
                  "ms. Remaining tries:",
                  next.tries
                );
              } catch (_) {}
              setTimeout(() => this.loadRemoteHistory(next), options.delayMs);
            } else {
              this.state._historyLoaded = true;
              this.state._historyPolling = false;
            }
            return;
          }
          // In retry mode, do NOT render messages; only try to capture conversation_uuid and stop
          if (options.retry) {
            try {
              const found = items.find((x) => x && x.conversation_uuid);
              if (found && found.conversation_uuid) {
                try {
                  localStorage.setItem(
                    "fluxa_conversation_uuid",
                    String(found.conversation_uuid)
                  );
                } catch (e) {}
                try {
                  console.info(
                    "[Fluxa] conversation_uuid (from history):",
                    found.conversation_uuid
                  );
                } catch (_) {}
                this.trackConversationIfNeeded(found.conversation_uuid);
              }
            } catch (e) {}
            this.state._historyPolling = false;
            return;
          }
          try {
            // Render from oldest to newest
            items.sort(
              (a, b) => new Date(a.created_at) - new Date(b.created_at)
            );
          } catch (e) {}
          items.forEach((it) => {
            const role =
              String(it.role || "").toLowerCase() === "user" ? "user" : "bot";
            const ts = it.created_at ? new Date(it.created_at) : new Date();
            const content = typeof it.content === "string" ? it.content : "";
            if (content) this.addMessageWithTime(content, role, ts);
          });
          // Save conversation_uuid if present on any item
          try {
            const found = items.find((x) => x && x.conversation_uuid);
            if (found && found.conversation_uuid) {
              try {
                localStorage.setItem(
                  "fluxa_conversation_uuid",
                  String(found.conversation_uuid)
                );
              } catch (e) {}
              try {
                console.info(
                  "[Fluxa] conversation_uuid (from history):",
                  found.conversation_uuid
                );
              } catch (_) {}
              // Upsert mapping into DB via REST
              this.trackConversationIfNeeded(found.conversation_uuid);
            }
          } catch (e) {}
          this.scrollToBottom();
          this.state._historyLoaded = true;
          this.state._historyPolling = false;
        })
        .catch(() => {
          this.state._historyLoaded = true;
          this.state._historyPolling = false;
        });
    }

    showTypingIndicator() {
      if (this.elements && this.elements.typingIndicator) {
        this.elements.typingIndicator.style.display = "block";
        this.scrollToBottom();
      }
    }

    hideTypingIndicator() {
      if (this.elements && this.elements.typingIndicator) {
        this.elements.typingIndicator.style.display = "none";
      }
    }

    /**
     * Internal: (re)arm inactivity prompt for configured delay after last activity
     */
    _armInactivityAlert() {
      try {
        if (this.state && this.state._inactivityTimer) {
          clearTimeout(this.state._inactivityTimer);
        }
      } catch (_) {}
      // Respect admin toggle and configured delay (seconds)
      const enabled = !!(
        this.settings &&
        this.settings.settings &&
        Number(this.settings.settings.feedback_enabled) === 1
      );
      if (!enabled) {
        return;
      }
      let secs = 120;
      try {
        const v =
          this.settings &&
          this.settings.settings &&
          this.settings.settings.feedback_delay_seconds;
        const n = Number(v);
        if (!isNaN(n) && n >= 0) {
          secs = Math.floor(n);
        }
      } catch (_) {}
      const timeoutMs = secs * 1000;
      this.state._inactivityTimer = setTimeout(() => {
        // Only show feedback once per inactivity cycle and avoid duplicates
        if (!this.state) return;
        if (this.state._feedbackShown) return;
        this._showFeedbackPrompt();
      }, timeoutMs);
    }

    /**
     * Internal: record activity and reset inactivity alert cycle
     */
    _markActivity(ts) {
      const now = typeof ts === "number" ? ts : Date.now();
      if (this.state) {
        this.state._lastActivityTs = now;
        this.state._alertShown = false;
      }
      this._armInactivityAlert();
    }

    /**
     * Render an in-chat feedback card similar to 5-point satisfaction scale
     */
    _showFeedbackPrompt() {
      if (!this.elements || !this.elements.messagesContainer) return;
      // Respect admin toggle
      try {
        const enabled = !!(
          this.settings &&
          this.settings.settings &&
          Number(this.settings.settings.feedback_enabled) === 1
        );
        if (!enabled) return;
      } catch (_) {}
      this.state._feedbackShown = true; // prevent multiple inserts
      const wrap = document.createElement("div");
      wrap.className = "fluxa-feedback-card";
      wrap.innerHTML = `
        <div class="fluxa-feedback-card__inner">
          <div class="fluxa-feedback-card__title">${
            this.settings?.i18n?.feedback_title || "Were we helpful?"
          }</div>
          <div class="fluxa-feedback-card__faces" role="group" aria-label="Rate your experience">
            <button type="button" class="fluxa-feedback-face" data-score="1" aria-label="Very dissatisfied">😞</button>
            <button type="button" class="fluxa-feedback-face" data-score="2" aria-label="Dissatisfied">🙁</button>
            <button type="button" class="fluxa-feedback-face" data-score="3" aria-label="Neutral">😐</button>
            <button type="button" class="fluxa-feedback-face" data-score="4" aria-label="Satisfied">🙂</button>
            <button type="button" class="fluxa-feedback-face" data-score="5" aria-label="Very satisfied">😍</button>
          </div>
          <div class="fluxa-feedback-card__actions">
            <button type="button" class="fluxa-feedback-send is-disabled" disabled aria-disabled="true">${
              this.settings?.i18n?.send || "Send"
            }</button>
          </div>
        </div>
      `;
      this.elements.messagesContainer.appendChild(wrap);
      this.scrollToBottom();

      const sendBtn = wrap.querySelector(".fluxa-feedback-send");
      const faces = Array.from(wrap.querySelectorAll(".fluxa-feedback-face"));
      let selected = 0;
      let selectedEmoji = "";
      faces.forEach((btn) => {
        btn.addEventListener("click", (e) => {
          e.preventDefault();
          faces.forEach((b) => b.classList.remove("is-selected"));
          btn.classList.add("is-selected");
          selected = parseInt(btn.getAttribute("data-score") || "0", 10);
          // Activate Send once a face is selected
          if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.classList.remove("is-disabled");
            sendBtn.setAttribute("aria-disabled", "false");
          }
          // Store selected icon for later alert on send
          selectedEmoji = (btn.textContent || "").trim();
        });
      });
      if (sendBtn) {
        sendBtn.addEventListener("click", (e) => {
          e.preventDefault();
          // Basic UX: if nothing selected, treat as neutral
          if (!selected) {
            selected = 3;
            selectedEmoji = "😐";
          }
          // If we have a conversation id, attempt to save feedback to DB
          try {
            var conv = "";
            try {
              conv = localStorage.getItem("fluxa_conversation_uuid") || "";
            } catch (_) {
              conv = "";
            }
            if (!conv) {
              try {
                var ss =
                  localStorage.getItem("fluxa_uid_value") ||
                  sessionStorage.getItem("fluxa_uid_value") ||
                  "";
                if (ss) {
                  var m = String(ss).match(
                    /[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/
                  );
                  conv = m && m[0] ? m[0] : String(ss).split(".")[0];
                }
              } catch (_) {}
            }
            if (conv) {
              try {
                var url =
                  typeof fluxaChatbot !== "undefined" &&
                  fluxaChatbot.rest_feedback
                    ? String(fluxaChatbot.rest_feedback)
                    : "";
                var nonce =
                  fluxaChatbot && fluxaChatbot.nonce ? fluxaChatbot.nonce : "";
                if (url) {
                  var payload = {
                    conversation_id: conv,
                    rating_point: selected,
                    page_url: (function () {
                      try {
                        return window.location.href || "";
                      } catch (_) {
                        return "";
                      }
                    })(),
                    page_referrer: (function () {
                      try {
                        return document.referrer || "";
                      } catch (_) {
                        return "";
                      }
                    })(),
                  };
                  var headers = { "Content-Type": "application/json" };
                  if (nonce) headers["X-WP-Nonce"] = nonce;
                  fetch(url, {
                    method: "POST",
                    headers: headers,
                    credentials: "same-origin",
                    body: JSON.stringify(payload),
                  })
                    .then(function () {
                      /* no-op */
                    })
                    .catch(function () {});
                }
              } catch (_) {}
            }
          } catch (_) {}
          // Replace card with a small thank-you note
          try {
            wrap.innerHTML = `<div class=\"fluxa-feedback-card__thankyou\">${
              this.settings?.i18n?.thanks || "Thanks for your feedback!"
            }</div>`;
          } catch (_) {}
        });
      }
    }

    scrollToBottom() {
      if (this.elements && this.elements.messagesContainer) {
        this.elements.messagesContainer.scrollTop =
          this.elements.messagesContainer.scrollHeight;
      }
    }

    /**
     * Handle window resize events
     */
    handleResize() {
      // Recompute some measurements that can change with viewport
      if (this.elements && this.elements.widget) {
        const cs = window.getComputedStyle(this.elements.widget);
        const bottomPx = parseInt(cs.bottom || "20", 10);
        if (!isNaN(bottomPx)) {
          this.metrics.baseBottom = bottomPx;
        }
      }
      if (this.elements && this.elements.launchButton) {
        const rect = this.elements.launchButton.getBoundingClientRect();
        const size = Math.max(rect.width || 0, rect.height || 0);
        if (size > 0) {
          this.metrics.launcherSize = Math.round(size);
        }
      }
      // Keep the latest message in view when open
      if (this.state && this.state.isOpen && !this.state.isMinimized) {
        this.scrollToBottom();
      }
    }

    focusInput() {
      if (this.elements && this.elements.input) {
        try {
          this.elements.input.focus();
        } catch (e) {}
      }
    }

    handleKeyDown(e) {
      // Basic history navigation if needed in future (kept minimal)
      // Placeholder to avoid errors from bound listener
    }

    addToMessageHistory(message) {
      if (!this.state) return;
      this.state.messageHistory.push(message);
      this.state.historyIndex = -1;
      if (this.state.messageHistory.length > 50) {
        this.state.messageHistory.shift();
      }
      this.saveMessageHistory();
    }

    saveMessageHistory() {
      try {
        localStorage.setItem(
          "fluxa_chat_history",
          JSON.stringify(this.state.messageHistory || [])
        );
      } catch (e) {}
    }

    loadMessageHistory() {
      try {
        const raw = localStorage.getItem("fluxa_chat_history");
        if (raw) {
          this.state.messageHistory = JSON.parse(raw) || [];
        }
      } catch (e) {}
    }

    applySuggestionsHiddenState() {
      try {
        const hidden = sessionStorage.getItem("fluxa_suggestions_hidden");
        if (hidden && this.elements && this.elements.suggestionsContainer) {
          this.elements.suggestionsContainer.classList.add("is-hidden");
        } else if (this.elements && this.elements.suggestionsContainer) {
          this.elements.suggestionsContainer.classList.remove("is-hidden");
        }
      } catch (e) {}
    }

    hideSuggestions() {
      try {
        sessionStorage.setItem("fluxa_suggestions_hidden", "1");
      } catch (e) {}
      if (this.elements && this.elements.suggestionsContainer) {
        this.elements.suggestionsContainer.classList.add("is-hidden");
      }
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
      // Toggle chat widget
      if (this.elements.launchButton) {
        this.elements.launchButton.addEventListener("click", (e) => {
          // Prevent click from firing directly after a drag
          if (this._dragMoved) {
            e.preventDefault();
            e.stopPropagation();
            this._dragMoved = false;
            return;
          }
          this.toggleChat(e);
        });
      }

      // Close chat
      if (this.elements.closeButton) {
        this.elements.closeButton.addEventListener("click", (e) =>
          this.closeChat(e)
        );
      }

      // Handle form submission
      if (this.elements.form) {
        this.elements.form.addEventListener("submit", (e) =>
          this.handleSubmit(e)
        );
      }

      // Handle keyboard navigation
      if (this.elements.input) {
        this.elements.input.addEventListener("keydown", (e) =>
          this.handleKeyDown(e)
        );
      }

      // Handle window resize
      window.addEventListener("resize", () => this.handleResize());

      // Handle clicks on suggested questions: open the chatbox and send as user
      if (this.elements.suggestions && this.elements.suggestions.length) {
        this.elements.suggestions.forEach((btn) => {
          btn.addEventListener("click", (e) => {
            e.preventDefault();
            const text = (btn.textContent || "").trim();
            // Hide the suggestions card immediately
            // if (this.elements && this.elements.suggestionsContainer) {
            //   this.hideSuggestions();
            // }
            this.openChat();
            if (text) {
              // Mirror standard submit pipeline
              this.addMessage(text, "user");
              if (this.elements.input) {
                this.elements.input.value = "";
              }
              this.showTypingIndicator();
              this.addToMessageHistory(text);
              this.sendMessage(text);
            }
          });
        });
      }

      // Close suggestions
      if (
        this.elements.suggestionsClose &&
        this.elements.suggestionsContainer
      ) {
        this.elements.suggestionsClose.addEventListener("click", (e) => {
          e.preventDefault();
          this.hideSuggestions();
        });
      }
    }

    /**
     * Toggle chat widget visibility
     */
    toggleChat(e) {
      if (e) e.preventDefault();

      if (this.state.isMinimized) {
        this.openChat();
      } else {
        this.minimizeChat();
      }
    }

    /**
     * Open the chat widget
     */
    openChat() {
      this.state.isOpen = true;
      this.state.isMinimized = false;
      // Hide external suggestions when chat is opened
      // if (this.elements && this.elements.suggestionsContainer) {
      //   this.hideSuggestions();
      // }
      // Clear new-message pulse on launcher when opening
      if (this.elements && this.elements.launchButton) {
        this.elements.launchButton.classList.remove("has-new");
      }
      this.render();
      // Keep original CSS-based positioning; no JS alignment
      this.focusInput();
      this.scrollToBottom();
    }

    /**
     * Minimize the chat widget
     */
    minimizeChat(e) {
      if (e) e.preventDefault();

      this.state.isMinimized = true;
      this.render();
    }

    /**
     * Close the chat widget
     */
    closeChat(e) {
      if (e) e.preventDefault();

      this.state.isOpen = false;
      this.state.isMinimized = true;
      this.render();
    }

    /**
     * Handle form submission
     */
    handleSubmit(e) {
      e.preventDefault();

      const message = this.elements.input.value.trim();

      if (!message) {
        return;
      } else {
        // Play a quick launch animation on the send button
        if (this.elements.sendButton) {
          this.elements.sendButton.classList.add("is-sending");
          this.elements.sendButton.disabled = true;
          window.setTimeout(() => {
            this.elements.sendButton.classList.remove("is-sending");
            this.elements.sendButton.disabled = false;
          }, 650);
        }

        // Add user message to chat
        this.addMessage(message, "user");

        // Clear input
        this.elements.input.value = "";

        // Show typing indicator
        this.showTypingIndicator();

        // Save to message history
        this.addToMessageHistory(message);

        // Send message to server
        this.sendMessage(message);
      }
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(unsafe) {
      return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }
  }

  // jQuery document ready: initialize widget once DOM is ready
  $(function () {
    if (document.getElementById("fluxa-chat-widget")) {
      new FluxaChatWidget({
        ajaxUrl:
          typeof fluxaChatbot !== "undefined" ? fluxaChatbot.ajaxurl : "",
        settings:
          typeof fluxaChatbot !== "undefined"
            ? fluxaChatbot.settings || {}
            : {},
        i18n:
          typeof fluxaChatbot !== "undefined" ? fluxaChatbot.i18n || {} : {},
      });
    }
  });
})(jQuery);
