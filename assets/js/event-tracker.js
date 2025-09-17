/**
 * Fluxa Event Tracker - Frontend JavaScript
 * Handles client-side event tracking for product impressions, clicks, etc.
 */

(function () {
  "use strict";

  // Check if we have the necessary globals
  if (typeof fluxaEventTracker === "undefined") {
    return;
  }

  const cfg = {
    enabled: !!(fluxaEventTracker && parseInt(fluxaEventTracker.enabled || 0, 10)),
    events: (fluxaEventTracker && typeof fluxaEventTracker.events === 'object') ? fluxaEventTracker.events : {}
  };

  function isOn(eventKey) {
    if (!cfg.enabled) return false;
    if (!cfg.events) return false;
    var val = cfg.events[eventKey];
    // Default to true if key missing and master is enabled
    if (typeof val === 'undefined') return true;
    return !!parseInt(val, 10);
  }

  const tracker = {
    // Track product impressions using Intersection Observer
    setupProductImpressions: function () {
      if (!isOn('product_impression') || !window.IntersectionObserver) {
        return; // Fallback for older browsers
      }

      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              const element = entry.target;
              const productId = element.dataset.productId;
              const price = element.dataset.price;
              const currency = element.dataset.currency;
              const listType = element.dataset.listType || "category";

              if (productId && !element.dataset.impressionTracked) {
                // Mark as tracked BEFORE sending to avoid race conditions when multiple observers are bound
                element.dataset.impressionTracked = "true";
                this.trackEvent("product_impression", {
                  product_id: parseInt(productId),
                  price: parseFloat(price) || 0,
                  currency: currency || "",
                  json_payload: {
                    list: listType,
                  },
                });
                observer.unobserve(element);
              }
            }
          });
        },
        {
          threshold: 0.5,
          rootMargin: "0px 0px -50px 0px",
        }
      );

      // Observe all product cards
      document
        .querySelectorAll(
          ".woocommerce ul.products li.product, .product-item, [data-product-id]"
        )
        .forEach((el) => {
          if (el.dataset.productId) {
            observer.observe(el);
          }
        });
    },

    // Track product clicks
    setupProductClicks: function () {
      if (!isOn('product_click')) return;
      document.addEventListener("click", (e) => {
        const productLink = e.target.closest(
          'a[href*="/product/"], .product-link, [data-product-id] a'
        );
        if (productLink) {
          const productElement = productLink.closest("[data-product-id]");
          if (productElement) {
            const productId = productElement.dataset.productId;
            const listType = productElement.dataset.listType || "category";

            if (productId) {
              this.trackEvent("product_click", {
                product_id: parseInt(productId),
                json_payload: {
                  from_list: listType,
                },
              });
            }
          }
        }
      });
    },

    // Track variant selection on product pages
    setupVariantTracking: function () {
      // WooCommerce variation forms
      if (!isOn('variant_select')) return;
      document.addEventListener("change", (e) => {
        if (
          e.target.matches(
            '.variations select, .variations input[type="radio"]'
          )
        ) {
          const form = e.target.closest(".variations_form");
          if (form) {
            const productId = form.dataset.productId;
            const variationId = form.querySelector(
              'input[name="variation_id"]'
            )?.value;

            if (productId && variationId) {
              // Collect selected attributes
              const attributes = {};
              form
                .querySelectorAll(
                  '.variations select, .variations input[type="radio"]:checked'
                )
                .forEach((input) => {
                  if (input.name.startsWith("attribute_")) {
                    attributes[input.name] = input.value;
                  }
                });

              this.trackEvent("variant_select", {
                product_id: parseInt(productId),
                variation_id: parseInt(variationId),
                json_payload: {
                  attributes: attributes,
                },
              });
            }
          }
        }
      });
    },

    // Track JavaScript errors
    setupErrorTracking: function () {
      if (isOn('js_error')) {
        window.addEventListener("error", (e) => {
        this.trackEvent("js_error", {
          json_payload: {
            message: e.message,
            file: e.filename,
            line: e.lineno,
            col: e.colno,
          },
        });
        });

        // Track unhandled promise rejections
        window.addEventListener("unhandledrejection", (e) => {
          this.trackEvent("js_error", {
            json_payload: {
              message: "Unhandled Promise Rejection: " + e.reason,
              file: "promise",
              line: 0,
              col: 0,
            },
          });
        });
      }
    },

    // Track search submissions from forms (non-AJAX themes)
    setupSearchTracking: function () {
      if (!isOn('search')) return;
      document.addEventListener('submit', (e) => {
        try {
          const form = e.target;
          if (!form || !(form instanceof HTMLFormElement)) return;
          // Common Woo search patterns
          const qInput = form.querySelector('input[name="s"], input[name="query"], input[name="q"]');
          if (!qInput) return;
          const term = (qInput.value || '').toString();
          if (!term) return;
          // Only track if this looks like a search form (has post_type or action leads to search)
          const postType = (form.querySelector('input[name="post_type"]') || {}).value || '';
          this.trackEvent('search', {
            json_payload: {
              provider: 'form_submit',
              term: term,
              post_type: postType || undefined,
            }
          });
        } catch(err) { /* ignore */ }
      });
    },

    // Track AJAX search (e.g., Woodmart woodmart_ajax_search)
    setupAjaxSearchTracking: function () {
      if (!isOn('search')) return;
      // Guard to avoid double patching
      if (window.__fluxaAjaxSearchPatched) return; 
      window.__fluxaAjaxSearchPatched = true;

      // Helper to process a URL and optional body for AJAX search
      const handleAjaxSearch = (urlString, body) => {
        try {
          if (!urlString) return;
          // Skip our own REST endpoint to prevent loops
          if (typeof fluxaEventTracker !== 'undefined' && urlString.indexOf(String(fluxaEventTracker.restUrl)) === 0) {
            return;
          }
          const url = new URL(urlString, document.baseURI);
          const isAdminAjax = /admin-ajax\.php$/i.test(url.pathname);
          if (!isAdminAjax) return;
          const action = url.searchParams.get('action');
          if (action !== 'woodmart_ajax_search') return;
          // Woodmart sends query as 'query' param; also accept 's'
          let term = url.searchParams.get('query') || url.searchParams.get('s') || '';
          let postType = url.searchParams.get('post_type') || '';
          let number = url.searchParams.get('number') || '';
          // Handle POST bodies (string, URLSearchParams, or FormData)
          if ((!term || !postType) && body) {
            try {
              if (typeof body === 'string' && body.indexOf('=') > -1) {
                const params = new URLSearchParams(body);
                term = term || params.get('query') || params.get('s') || '';
                postType = postType || params.get('post_type') || '';
                number = number || params.get('number') || '';
              } else if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
                term = term || body.get('query') || body.get('s') || '';
                postType = postType || body.get('post_type') || '';
                number = number || body.get('number') || '';
              } else if (typeof FormData !== 'undefined' && body instanceof FormData) {
                term = term || body.get('query') || body.get('s') || '';
                postType = postType || body.get('post_type') || '';
                number = number || body.get('number') || '';
              }
            } catch(e) {}
          }
          if (!term) return;
          tracker.trackEvent('search', {
            json_payload: {
              provider: 'woodmart_ajax_search',
              term: term,
              post_type: postType || undefined,
              number: number ? parseInt(number, 10) : undefined,
              endpoint: url.pathname,
            }
          });
        } catch (e) { /* ignore */ }
      };

      // Patch fetch
      if (typeof window.fetch === 'function') {
        const origFetch = window.fetch;
        window.fetch = function(input, init) {
          try {
            const urlString = (typeof input === 'string') ? input : (input && input.url) ? input.url : '';
            const body = init && init.body ? init.body : undefined;
            handleAjaxSearch(urlString, body);
          } catch (e) {}
          return origFetch.apply(this, arguments);
        };
      }

      // Patch XMLHttpRequest
      if (typeof window.XMLHttpRequest === 'function') {
        const OrigXHR = window.XMLHttpRequest;
        const Proto = OrigXHR.prototype;
        const origOpen = Proto.open;
        const origSend = Proto.send;
        let lastUrl = '';
        Proto.open = function(method, url) {
          try { lastUrl = url; } catch (e) { lastUrl = ''; }
          return origOpen.apply(this, arguments);
        };
        Proto.send = function(body) {
          try { handleAjaxSearch(lastUrl, body); } catch (e) {}
          return origSend.apply(this, arguments);
        };
      }
    },

    // Track sort and filter changes
    setupCatalogTracking: function () {
      // Sort dropdown changes
      if (isOn('sort_apply')) {
        document.addEventListener("change", (e) => {
        if (e.target.matches('.orderby, select[name="orderby"]')) {
          this.trackEvent("sort_apply", {
            json_payload: {
              sort_by: e.target.value,
            },
          });
        }
        });
      }

      // Filter form submissions
      if (isOn('filter_apply')) {
        document.addEventListener("submit", (e) => {
        if (
          e.target.matches(
            ".widget_layered_nav form, .woocommerce-widget-layered-nav form"
          )
        ) {
          const filters = {};
          const formData = new FormData(e.target);
          for (let [key, value] of formData.entries()) {
            if (key.startsWith("filter_")) {
              filters[key] = value;
            }
          }

          if (Object.keys(filters).length > 0) {
            this.trackEvent("filter_apply", {
              json_payload: {
                filters: filters,
              },
            });
          }
        }
        });
      }

      // Pagination clicks
      if (isOn('pagination')) {
        document.addEventListener("click", (e) => {
          if (e.target.matches(".woocommerce-pagination a, .page-numbers a")) {
            const url = new URL(e.target.href);
            const page =
              url.searchParams.get("paged") ||
              url.pathname.match(/\/page\/(\d+)/)?.[1];

            if (page) {
              this.trackEvent("pagination", {
                json_payload: {
                  page: parseInt(page),
                },
              });
            }
          }
        });
      }
    },

    // Send event to server
    trackEvent: function (eventType, data = {}) {
      if (!fluxaEventTracker.restUrl || !fluxaEventTracker.nonce) {
        return;
      }

      const payload = {
        event_type: eventType,
        // Provide page URL and referrer so server logs page context instead of REST endpoint
        page_url: location.pathname + location.search,
        page_referrer: document.referrer || "",
        // Provide conversation id when available so server can store it
        conversation_id: (function () {
          try {
            return localStorage.getItem("fluxa_conversation_uuid") || "";
          } catch (e) {
            return "";
          }
        })(),
        ...data,
      };

      fetch(fluxaEventTracker.restUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": fluxaEventTracker.nonce,
        },
        credentials: "same-origin",
        body: JSON.stringify(payload),
      }).catch((error) => {
        // Track API errors (respect admin toggle)
        if (isOn('api_error') && eventType !== "api_error") {
          // Prevent infinite loops
          this.trackEvent("api_error", {
            json_payload: {
              endpoint: fluxaEventTracker.restUrl,
              status: 0,
              message: error.message,
            },
          });
        }
      });
    },

    // Initialize all tracking
    init: function () {
      // Prevent double initialization if the script is enqueued twice
      if (window.__fluxaTrackerInitialized) {
        return;
      }
      window.__fluxaTrackerInitialized = true;

      if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => {
          this.setupProductImpressions();
          this.setupProductClicks();
          this.setupVariantTracking();
          this.setupErrorTracking();
          this.setupCatalogTracking();
          this.setupSearchTracking();
          this.setupAjaxSearchTracking();
        });
      } else {
        this.setupProductImpressions();
        this.setupProductClicks();
        this.setupVariantTracking();
        this.setupErrorTracking();
        this.setupCatalogTracking();
        this.setupSearchTracking();
        this.setupAjaxSearchTracking();
      }
    },
  };

  // Start tracking
  // Ensure AJAX search interception is installed as early as possible
  try { tracker.setupAjaxSearchTracking(); } catch(e) {}
  tracker.init();

  // Expose tracker for manual use
  window.fluxaTracker = tracker;
})();
