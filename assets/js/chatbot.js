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
      this.loadMessageHistory();
      this.applySuggestionsHiddenState();
      // Mark JS readiness to allow CSS to reveal suggestions without flash
      try {
        if (document && document.body) {
          document.body.classList.add("fluxa-js-ready");
        }
      } catch (e) {}
      this.render();
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
          const raw = (this.settings && this.settings.settings && this.settings.settings.animation) || 'bounceIn';
          // Normalize (remove non-letters and lowercase)
          const key = String(raw).replace(/[^a-z]/gi, '').toLowerCase();
          const map = {
            none: null,
            // Bounce/Back
            bouncein: 'animate__bounceIn',
            bounceinup: 'animate__bounceInUp',
            bounceinleft: 'animate__bounceInLeft',
            bounceinright: 'animate__bounceInRight',
            backinup: 'animate__backInUp',
            backinleft: 'animate__backInLeft',
            backinright: 'animate__backInRight',
            // Fade
            fadeinup: 'animate__fadeInUp',
            fadeinupbig: 'animate__fadeInUpBig',
            fadeinleft: 'animate__fadeInLeft',
            fadeinleftbig: 'animate__fadeInLeftBig',
            fadeinright: 'animate__fadeInRight',
            fadeinrightbig: 'animate__fadeInRightBig',
            // Flip
            flipinx: 'animate__flipInX',
            flipiny: 'animate__flipInY',
            // Light speed
            lightspeedinleft: 'animate__lightSpeedInLeft',
            lightspeedinright: 'animate__lightSpeedInRight',
            // Special
            jackinthebox: 'animate__jackInTheBox',
            rollin: 'animate__rollIn',
            // Zoom
            zoomin: 'animate__zoomIn',
            zoomindown: 'animate__zoomInDown',
            zoominleft: 'animate__zoomInLeft',
            zoominright: 'animate__zoomInRight',
            zoominup: 'animate__zoomInUp',
            // Slide
            slideindown: 'animate__slideInDown',
            slideinleft: 'animate__slideInLeft',
            slideinright: 'animate__slideInRight',
            slideinup: 'animate__slideInUp'
          };
          const cls = Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
          if (cls) {
            const dur = /(bounce|back)/.test(key) ? '700ms' : '420ms';
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
          this.elements.container.classList.remove("fluxa-chat-container--open");
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
     * Send message to server (demo implementation)
     */
    sendMessage(message) {
      // Demo: simulate a bot response after a short delay
      setTimeout(() => {
        this.hideTypingIndicator();
        const responses = [
          `I'm a demo assistant. You said: "${message}"`,
          `Thanks! I received: "${message}"`,
          `Echo: "${message}"`
        ];
        const reply = responses[Math.floor(Math.random() * responses.length)];
        this.addMessage(reply, 'bot');
      }, 900);
    }

    /**
     * Add a message to the chat
     */
    addMessage(message, type = 'bot') {
      if (!this.elements || !this.elements.messagesContainer) return;
      // Ensure the chat is visible for bot replies only if enabled in settings
      const autoOpen = !!(this.settings && this.settings.settings && this.settings.settings.auto_open_on_reply);
      const pulseEnabled = !!(this.settings && this.settings.settings && this.settings.settings.pulse_on_new);
      if (type === 'bot' && autoOpen && (this.state.isMinimized || !this.state.isOpen)) {
        this.openChat();
      } else if (type === 'bot' && pulseEnabled && (this.state.isMinimized || !this.state.isOpen)) {
        // If auto-open is disabled, show a subtle pulse/glow on the launcher
        if (this.elements && this.elements.launchButton) {
          this.elements.launchButton.classList.add("has-new");
        }
      }
      const el = document.createElement('div');
      el.className = `fluxa-chat-message fluxa-chat-message--${type}`;
      const now = new Date();
      const time = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      el.innerHTML = `
        <div class="fluxa-chat-message__content">${this.escapeHtml(String(message))}</div>
        <div class="fluxa-chat-message__time">${time}</div>
      `;
      this.elements.messagesContainer.appendChild(el);
      this.scrollToBottom();
    }

    showTypingIndicator() {
      if (this.elements && this.elements.typingIndicator) {
        this.elements.typingIndicator.style.display = 'block';
        this.scrollToBottom();
      }
    }

    hideTypingIndicator() {
      if (this.elements && this.elements.typingIndicator) {
        this.elements.typingIndicator.style.display = 'none';
      }
    }

    scrollToBottom() {
      if (this.elements && this.elements.messagesContainer) {
        this.elements.messagesContainer.scrollTop = this.elements.messagesContainer.scrollHeight;
      }
    }

    focusInput() {
      if (this.elements && this.elements.input) {
        try { this.elements.input.focus(); } catch(e) {}
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
        localStorage.setItem('fluxa_chat_history', JSON.stringify(this.state.messageHistory || []));
      } catch(e) {}
    }

    loadMessageHistory() {
      try {
        const raw = localStorage.getItem('fluxa_chat_history');
        if (raw) {
          this.state.messageHistory = JSON.parse(raw) || [];
        }
      } catch(e) {}
    }

    applySuggestionsHiddenState() {
      try {
        const hidden = sessionStorage.getItem('fluxa_suggestions_hidden');
        if (hidden && this.elements && this.elements.suggestionsContainer) {
          this.elements.suggestionsContainer.classList.add('is-hidden');
        } else if (this.elements && this.elements.suggestionsContainer) {
          this.elements.suggestionsContainer.classList.remove('is-hidden');
        }
      } catch(e) {}
    }

    hideSuggestions() {
      try { sessionStorage.setItem('fluxa_suggestions_hidden', '1'); } catch(e) {}
      if (this.elements && this.elements.suggestionsContainer) {
        this.elements.suggestionsContainer.classList.add('is-hidden');
      }
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
      // Toggle chat widget
      if (this.elements.launchButton) {
        this.elements.launchButton.addEventListener("click", (e) =>
          this.toggleChat(e)
        );
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
