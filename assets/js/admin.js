jQuery(document).ready(function ($) {
  // ============================================
  // General Admin Functions
  // ============================================

  // Initialize color picker
  // Initialize WP color picker if available
  if ($.fn.wpColorPicker) {
    $(".color-picker").wpColorPicker();
  }

  // Apply unified switch style to all checkboxes not already using custom markup
  (function applyFluxaSwitchNative(){
    // Scope to our plugin area: run broadly on page but skip those inside .fluxa-switch wrappers
    $('input[type="checkbox"]').each(function(){
      var $cb = $(this);
      if ($cb.closest('.fluxa-switch').length) return; // already custom
      if ($cb.closest('.wp-list-table').length) return; // bulk selection tables
      if ($cb.is('#fluxa-check-all')) return; // master select all
      if (($cb.attr('name')||'').indexOf('selected_items') === 0 || $cb.attr('name') === 'selected_items[]') return; // row selectors
      if ($cb.hasClass('fluxa-switch-native')) return; // already styled
      $cb.addClass('fluxa-switch-native');
    });
  })();

  // Toggle notification message field based on checkbox
  $('input[name="desktop_notifications"]')
    .on("change", function () {
      $("#notification-message-container").toggle($(this).is(":checked"));
    })
    .trigger("change");

  // ============================================
  // Logo Upload Functionality (WP Media Library)
  // ============================================
  (function initFluxaLogoUploader() {
    var $select = $("#logo_select");
    var $remove = $("#logo_remove");
    var $logoUrl = $("#logo_url");
    var $removeFlag = $("#remove_logo");
    var frame = null;

    if (!$select.length) return; // only on settings page

    function setPreview(url) {
      $(".logo-preview").remove();
      if (url) {
        $remove.show();

        $removeFlag.after(
          '<div class="logo-preview" style="margin-top:10px;"><img src="' +
            url +
            '" style="max-height:100px; max-width:200px; border-radius:3px; box-shadow:0 1px 2px rgba(0,0,0,0.08);"></div>'
        );

        $select.text(fluxaI18n ? fluxaI18n.changeLogo : "Change Logo");
      } else {
        $(".logo-preview").remove();
        $remove.hide();
        $select.text(fluxaI18n ? fluxaI18n.selectLogo : "Select Logo");
      }
    }

    $select.on("click", function (e) {
      e.preventDefault();
      if (frame) {
        frame.open();
        return;
      }
      frame = wp.media({
        title: fluxaI18n ? fluxaI18n.selectLogoTitle : "Select Logo",
        button: { text: fluxaI18n ? fluxaI18n.useThisImage : "Use this image" },
        library: { type: "image" },
        multiple: false,
      });

      frame.on("select", function () {
        var attachment = frame.state().get("selection").first().toJSON();
        var url =
          attachment.sizes && attachment.sizes.medium
            ? attachment.sizes.medium.url
            : attachment.url;
        $logoUrl.val(url);
        $removeFlag.val("0");
        setPreview(url);
      });

      frame.open();
    });

    $remove.on("click", function (e) {
      e.preventDefault();
      $logoUrl.val("");
      $removeFlag.val("1");
      setPreview("");
    });

    // Allow clicking the image/preview area to open the media frame (delegated, since preview is dynamic)
    $(document).on("click", ".logo-preview", function (e) {
      e.preventDefault();
      $select.trigger("click");
    });

    // initialize state based on existing value
    setPreview($logoUrl.val());
  })();

  // ============================================
  // Minimized Icon Upload (WP Media Library)
  // ============================================
  (function initFluxaMinimizedIconUploader() {
    var $select = $("#minimized_icon_select");
    var $remove = $("#minimized_icon_remove");
    var $url = $("#minimized_icon_url");
    var $removeFlag = $("#remove_minimized_icon");
    var frame = null;

    if (!$select.length) return; // only on settings page

    function setPreview(url) {
      $(".minicon-preview").remove();
      if (url) {
        $remove.show();
        $removeFlag.after(
          '<div class="minicon-preview" style="margin-top:10px;"><img src="' +
          url +
          '" style="max-height:100px; max-width:200px; border-radius:3px; box-shadow:0 1px 2px rgba(0,0,0,0.08);"></div>'
        );
        $select.text("Change Icon");
      } else {
        $(".minicon-preview").remove();
        $remove.hide();
        $select.text("Select Icon");
      }
    }

    $select.on("click", function (e) {
      e.preventDefault();
      if (frame) { frame.open(); return; }
      frame = wp.media({
        title: "Select Icon",
        button: { text: "Use this image" },
        library: { type: "image" },
        multiple: false,
      });

      frame.on("select", function () {
        var attachment = frame.state().get("selection").first().toJSON();
        var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
        $url.val(url);
        $removeFlag.val("0");
        setPreview(url);
      });

      frame.open();
    });

    $remove.on("click", function (e) {
      e.preventDefault();
      $url.val("");
      $removeFlag.val("1");
      setPreview("");
    });

    // initialize state
    setPreview($url.val());

    // Allow clicking the preview to re-open the media frame (delegated)
    $(document).on("click", ".minicon-preview, .minicon-preview img", function (e) {
      e.preventDefault();
      $select.trigger("click");
    });
  })();

  // (Training-related JS removed)

  // ============================================
  // Analytics Page Functionality
  // ============================================
  var $analyticsForm = $("#analytics-filters");
  var $chartContainer = $("#analytics-chart");
  var $statsContainer = $("#analytics-stats");
  var $topQuestionsContainer = $("#top-questions");
  var $unansweredQuestionsContainer = $("#unanswered-questions");
  var chart = null;

  if ($analyticsForm.length) {
    // Initialize date picker for custom range
    $(".date-picker").datepicker({
      dateFormat: "yy-mm-dd",
      changeMonth: true,
      changeYear: true,
    });

    // Toggle custom date range fields
    $('input[name="time_range"]')
      .on("change", function () {
        $(".custom-date-range").toggle($(this).val() === "custom");
      })
      .trigger("change");

    // Load initial data
    loadAnalyticsData();

    // Handle form submission
    $analyticsForm.on("submit", function (e) {
      e.preventDefault();
      loadAnalyticsData();
    });

    // (Removed training-related add-answer handler)
  }

  // Function to load analytics data
  function loadAnalyticsData() {
    var formData = $analyticsForm.serialize();

    // Show loading state
    $chartContainer.html(
      '<div class="loading">' + sensayChatbot.i18n.loading + "</div>"
    );
    $statsContainer.html("");
    $topQuestionsContainer.html("");
    $unansweredQuestionsContainer.html("");

    // Send AJAX request
    $.get(
      sensayChatbot.ajax_url +
        "?" +
        formData +
        "&action=sensay_get_analytics_data&nonce=" +
        sensayChatbot.nonce,
      function (response) {
        if (response && response.success) {
          var data = response.data;

          // Update chart
          updateChart(data);

          // Update stats
          updateStats(data.stats);

          // Update top questions
          updateTopQuestions(data.top_questions);

          // Update unanswered questions
          updateUnansweredQuestions(data.unanswered_questions);
        } else {
          showNotice(
            "error",
            response && response.data && response.data.message
              ? response.data.message
              : sensayChatbot.i18n.error
          );
        }
      }
    ).fail(function () {
      showNotice("error", sensayChatbot.i18n.error);
    });
  }

  // Function to update the chart
  function updateChart(data) {
    var ctx = $chartContainer.find("canvas")[0].getContext("2d");

    // Destroy existing chart if it exists
    if (chart) {
      chart.destroy();
    }

    // Create new chart
    chart = new Chart(ctx, {
      type: "line",
      data: {
        labels: data.labels,
        datasets: [
          {
            label: data.metric_label,
            data: data.values,
            borderColor: "rgba(75, 192, 192, 1)",
            backgroundColor: "rgba(75, 192, 192, 0.2)",
            borderWidth: 2,
            fill: true,
            tension: 0.4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true,
            grid: {
              display: true,
              color: "rgba(0, 0, 0, 0.05)",
            },
          },
          x: {
            grid: {
              display: false,
            },
          },
        },
        plugins: {
          legend: {
            display: true,
            position: "top",
          },
          tooltip: {
            mode: "index",
            intersect: false,
          },
        },
        interaction: {
          mode: "nearest",
          axis: "x",
          intersect: false,
        },
      },
    });
  }

  // Function to update statistics
  function updateStats(stats) {
    var html = `
            <div class="stat-card">
                <h3>${sensayChatbot.i18n.totalSessions}</h3>
                <div class="stat-value">${stats.total_sessions}</div>
                <div class="stat-trend ${
                  stats.sessions_trend >= 0 ? "up" : "down"
                }">
                    ${stats.sessions_trend >= 0 ? "↑" : "↓"} ${Math.abs(
      stats.sessions_trend
    )}%
                </div>
            </div>
            <div class="stat-card">
                <h3>${sensayChatbot.i18n.totalMessages}</h3>
                <div class="stat-value">${stats.total_messages}</div>
                <div class="stat-trend ${
                  stats.messages_trend >= 0 ? "up" : "down"
                }">
                    ${stats.messages_trend >= 0 ? "↑" : "↓"} ${Math.abs(
      stats.messages_trend
    )}%
                </div>
            </div>
            <div class="stat-card">
                <h3>${sensayChatbot.i18n.avgResponseTime}</h3>
                <div class="stat-value">${stats.avg_response_time}</div>
                <div class="stat-trend ${
                  stats.response_time_trend <= 0 ? "up" : "down"
                }">
                    ${stats.response_time_trend <= 0 ? "↓" : "↑"} ${Math.abs(
      stats.response_time_trend
    )}%
                </div>
            </div>
            <div class="stat-card">
                <h3>${sensayChatbot.i18n.satisfactionRate}</h3>
                <div class="stat-value">${stats.satisfaction_rate}</div>
                <div class="stat-trend ${
                  stats.satisfaction_trend >= 0 ? "up" : "down"
                }">
                    ${stats.satisfaction_trend >= 0 ? "↑" : "↓"} ${Math.abs(
      stats.satisfaction_trend
    )}%
                </div>
            </div>
        `;

    $statsContainer.html(html);
  }

  // Function to update top questions
  function updateTopQuestions(questions) {
    if (!questions || questions.length === 0) {
      $topQuestionsContainer.html(
        "<p>" + sensayChatbot.i18n.noDataAvailable + "</p>"
      );
      return;
    }

    var html = '<ul class="question-list">';

    questions.forEach(function (item, index) {
      html += `
                <li class="question-item">
                    <div class="question-text">${index + 1}. ${item.text}</div>
                    <div class="question-count">${item.count} ${
        item.count === 1 ? sensayChatbot.i18n.time : sensayChatbot.i18n.times
      }</div>
                </li>
            `;
    });

    html += "</ul>";
    $topQuestionsContainer.html(html);
  }

  // Function to update unanswered questions
  function updateUnansweredQuestions(questions) {
    if (!questions || questions.length === 0) {
      $unansweredQuestionsContainer.html(
        "<p>" + sensayChatbot.i18n.noUnansweredQuestions + "</p>"
      );
      return;
    }

    var html = '<ul class="question-list">';

    questions.forEach(function (item, index) {
      html += `
                <li class="question-item">
                    <div class="question-text">
                        ${index + 1}. ${item.text}
                        <button type="button" class="button button-small add-answer" data-question="${escapeHtml(
                          item.text
                        )}">
                            ${sensayChatbot.i18n.addAnswer}
                        </button>
                    </div>
                    <div class="question-count">${item.count} ${
        item.count === 1 ? sensayChatbot.i18n.time : sensayChatbot.i18n.times
      }</div>
                </li>
            `;
    });

    html += "</ul>";
    $unansweredQuestionsContainer.html(html);
  }

  // ============================================
  // Helper Functions
  // ============================================

  // Show admin notice
  function showNotice(type, message) {
    var notice = $(
      '<div class="notice notice-' +
        type +
        ' is-dismissible"><p>' +
        message +
        "</p></div>"
    );
    $(".wrap h1").after(notice);

    // Auto-dismiss after 5 seconds
    setTimeout(function () {
      notice.fadeOut(300, function () {
        $(this).remove();
      });
    }, 5000);
  }

  // Escape HTML to prevent XSS
  function escapeHtml(unsafe) {
    if (!unsafe) return "";
    return unsafe
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }
});
