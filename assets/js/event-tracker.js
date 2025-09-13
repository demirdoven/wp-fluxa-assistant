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

  const tracker = {
    // Track product impressions using Intersection Observer
    setupProductImpressions: function () {
      if (!window.IntersectionObserver) {
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
    },

    // Track sort and filter changes
    setupCatalogTracking: function () {
      // Sort dropdown changes
      document.addEventListener("change", (e) => {
        if (e.target.matches('.orderby, select[name="orderby"]')) {
          this.trackEvent("sort_apply", {
            json_payload: {
              sort_by: e.target.value,
            },
          });
        }
      });

      // Filter form submissions
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

      // Pagination clicks
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
        // Track API errors
        if (eventType !== "api_error") {
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
        });
      } else {
        this.setupProductImpressions();
        this.setupProductClicks();
        this.setupVariantTracking();
        this.setupErrorTracking();
        this.setupCatalogTracking();
      }
    },
  };

  // Start tracking
  tracker.init();

  // Expose tracker for manual use
  window.fluxaTracker = tracker;
})();
