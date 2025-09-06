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
     * Cache DOM elements
     */
    cacheElements() {
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
      }

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

    /**
     * Send message to server
     */
    sendMessage(message) {
      // In a real implementation, this would send the message to your server
      // which would then forward it to the AI service and return the response

      // For now, we'll simulate a response after a short delay
      setTimeout(() => {
        this.hideTypingIndicator();

        // Simulate a response
        const responses = [
          "I'm just a demo chatbot. In a real implementation, I would respond to your message: \"" +
            message +
            '"',
          'Thanks for your message! This is a demo response to: "' +
            message +
            '"',
          "I'm the Fluxa eCommerce Assistant. You said: \"" + message + '"',
          "That's interesting! You mentioned: \"" + message + '"',
        ];

        const randomResponse =
          responses[Math.floor(Math.random() * responses.length)];
        this.addMessage(randomResponse, "bot");
      }, 1000);

      // Real implementation would look something like this:
      /*
            $.ajax({
                url: this.settings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fluxa_send_message',
                    message: message,
                    _wpnonce: this.settings.nonce
                },
                success: (response) => {
                    this.hideTypingIndicator();
                    
                    if (response.success && response.data && response.data.message) {
                        this.addMessage(response.data.message, 'bot');
                    } else {
                        this.addMessage(this.settings.i18n.error, 'bot');
                    }
                },
                error: () => {
                    this.hideTypingIndicator();
                    this.addMessage(this.settings.i18n.error, 'bot');
                }
            });
            */
    }

    /**
     * Add a message to the chat
     */
    addMessage(message, type = "bot") {
      if (!message) return;

      const messageElement = document.createElement("div");
      messageElement.className = `fluxa-chat-message fluxa-chat-message--${type}`;

      const now = new Date();
      const timeString = now.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
      });

      messageElement.innerHTML = `
                <div class="fluxa-chat-message__content">${this.escapeHtml(
                  message
                )}</div>
                <div class="fluxa-chat-message__time">${timeString}</div>
            `;

      this.elements.messagesContainer.appendChild(messageElement);
      this.scrollToBottom();
    }

    /**
     * Show typing indicator
     */
    showTypingIndicator() {
      this.state.isTyping = true;
      this.elements.typingIndicator.style.display = "block";
      this.scrollToBottom();
    }

    /**
     * Hide typing indicator
     */
    hideTypingIndicator() {
      this.state.isTyping = false;
      this.elements.typingIndicator.style.display = "none";
    }

    /**
     * Scroll to the bottom of the messages
     */
    scrollToBottom() {
      if (this.elements.messagesContainer) {
        this.elements.messagesContainer.scrollTop =
          this.elements.messagesContainer.scrollHeight;
      }
    }

    /**
     * Focus the input field
     */
    focusInput() {
      if (this.elements.input) {
        this.elements.input.focus();
      }
    }

    /**
     * Handle keyboard navigation
     */
    handleKeyDown(e) {
      // Handle up/down arrow keys for message history
      if (e.key === "ArrowUp" || e.key === "ArrowDown") {
        e.preventDefault();

        if (
          e.key === "ArrowUp" &&
          this.state.historyIndex < this.state.messageHistory.length - 1
        ) {
          this.state.historyIndex++;
        } else if (e.key === "ArrowDown" && this.state.historyIndex >= 0) {
          this.state.historyIndex--;
        }

        if (
          this.state.historyIndex >= 0 &&
          this.state.historyIndex < this.state.messageHistory.length
        ) {
          this.elements.input.value =
            this.state.messageHistory[
              this.state.messageHistory.length - 1 - this.state.historyIndex
            ];
        } else if (this.state.historyIndex === -1) {
          this.elements.input.value = "";
        }
      }
    }

    /**
     * Add message to history
     */
    addToMessageHistory(message) {
      this.state.messageHistory.push(message);
      this.state.historyIndex = -1;

      // Keep only the last 50 messages in history
      if (this.state.messageHistory.length > 50) {
        this.state.messageHistory.shift();
      }

      this.saveMessageHistory();
    }

    /**
     * Save message history to localStorage
     */
    saveMessageHistory() {
      try {
        localStorage.setItem(
          "fluxa_chat_history",
          JSON.stringify(this.state.messageHistory)
        );
      } catch (e) {
        console.error("Failed to save chat history:", e);
      }
    }

    /**
     * Persist and apply suggestions hidden state
     */
    hideSuggestions() {
      try {
        sessionStorage.setItem("fluxa_suggestions_hidden", "1");
      } catch (e) {}
      if (this.elements.suggestionsContainer) {
        this.elements.suggestionsContainer.classList.add("is-hidden");
      }
    }

    applySuggestionsHiddenState() {
      try {
        const hidden = sessionStorage.getItem("fluxa_suggestions_hidden");
        if (hidden && this.elements.suggestionsContainer) {
          // this.elements.suggestionsContainer.classList.add("is-hidden");
        } else {
          this.elements.suggestionsContainer.classList.remove("is-hidden");
        }
      } catch (e) {}
    }

    /**
     * Load message history from localStorage
     */
    loadMessageHistory() {
      try {
        const history = localStorage.getItem("fluxa_chat_history");
        if (history) {
          this.state.messageHistory = JSON.parse(history);
        }
      } catch (e) {
        console.error("Failed to load chat history:", e);
      }
    }

    /**
     * Handle window resize
     */
    handleResize() {
      // Add any responsive behavior here
    }

    /**
     * Render widget state to DOM without adjusting position via JS
     */
    render() {
      if (!this.elements || !this.elements.widget) return;

      if (this.state.isOpen && !this.state.isMinimized) {
        this.elements.widget.classList.remove("fluxa-chat-widget--minimized");
        if (this.elements.launchButton) {
          this.elements.launchButton.classList.remove(
            "fluxa-chat-widget--hidden"
          );
        }
      } else {
        this.elements.widget.classList.add("fluxa-chat-widget--minimized");
        if (this.elements.launchButton) {
          this.elements.launchButton.classList.remove(
            "fluxa-chat-widget--hidden"
          );
        }
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
