<?php
if (!defined('ABSPATH')) { exit; }
// Determine active tab early so handlers can use it
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'intro';
// Shared notice vars
$message = '';
$message_class = 'notice';

// --- Auto Imports (new) rule management ---
// Storage: option 'fluxa_auto_import_rules' => array of rules
// Rule: id, name, schedule, source, enabled, created, last_run, total_runs
if ('auto-imports' === $current_tab && current_user_can('manage_options')) {
    // Ensure custom schedules are available
    add_filter('cron_schedules', function($schedules){
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __('Once Weekly', 'wp-fluxa-ecommerce-assistant')
            ];
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once Monthly', 'wp-fluxa-ecommerce-assistant')
            ];
        }
        return $schedules;
    });

    $rules = get_option('fluxa_auto_import_rules', []);
    if (!is_array($rules)) { $rules = []; }

    // Add rule
    if (isset($_POST['fluxa_auto_import_add'])) {
        check_admin_referer('fluxa_auto_imports', 'fluxa_auto_imports_nonce');
        $name     = sanitize_text_field($_POST['rule_name'] ?? '');
        $schedule = sanitize_text_field($_POST['rule_schedule'] ?? 'daily');
        $source   = sanitize_text_field($_POST['rule_source'] ?? 'wordpress_content');
        $enabled  = isset($_POST['rule_enabled']) ? 1 : 0;
        if (!in_array($schedule, ['hourly','daily','weekly','monthly'], true)) {
            $schedule = 'daily';
        }
        $id = uniqid('rule_', true);
        $rules[$id] = [
            'id'         => $id,
            'name'       => $name ?: sprintf(__('Rule %s', 'wp-fluxa-ecommerce-assistant'), date('Y-m-d H:i')),
            'schedule'   => $schedule,
            'source'     => $source,
            'enabled'    => $enabled,
            'created'    => time(),
            'last_run'   => 0,
            'total_runs' => 0,
        ];
        update_option('fluxa_auto_import_rules', $rules);

        // Schedule if enabled
        if ($enabled) {
            if (!wp_next_scheduled('fluxa_auto_import_run', ['rule_id' => $id])) {
                wp_schedule_event(time() + 300, $schedule, 'fluxa_auto_import_run', ['rule_id' => $id]);
            }
        }
        $message = __('Auto import rule added.', 'wp-fluxa-ecommerce-assistant');
        $message_class = 'notice-success';
    }

    // Delete rule
    if (isset($_POST['fluxa_auto_import_delete']) && !empty($_POST['rule_id'])) {
        check_admin_referer('fluxa_auto_imports', 'fluxa_auto_imports_nonce');
        $rid = sanitize_text_field($_POST['rule_id']);
        if (isset($rules[$rid])) {
            // Clear scheduled event for this rule
            while ($ts = wp_next_scheduled('fluxa_auto_import_run', ['rule_id' => $rid])) {
                wp_unschedule_event($ts, 'fluxa_auto_import_run', ['rule_id' => $rid]);
            }
            unset($rules[$rid]);
            update_option('fluxa_auto_import_rules', $rules);
            $message = __('Rule deleted.', 'wp-fluxa-ecommerce-assistant');
            $message_class = 'notice-success';
        }
    }

    // Toggle enable/disable
    if (isset($_POST['fluxa_auto_import_toggle']) && !empty($_POST['rule_id'])) {
        check_admin_referer('fluxa_auto_imports', 'fluxa_auto_imports_nonce');
        $rid = sanitize_text_field($_POST['rule_id']);
        if (isset($rules[$rid])) {
            $rules[$rid]['enabled'] = empty($rules[$rid]['enabled']) ? 1 : 0;
            update_option('fluxa_auto_import_rules', $rules);
            // Clear then (re)schedule
            while ($ts = wp_next_scheduled('fluxa_auto_import_run', ['rule_id' => $rid])) {
                wp_unschedule_event($ts, 'fluxa_auto_import_run', ['rule_id' => $rid]);
            }
            if (!empty($rules[$rid]['enabled'])) {
                wp_schedule_event(time() + 300, $rules[$rid]['schedule'], 'fluxa_auto_import_run', ['rule_id' => $rid]);
            }
            $message = !empty($rules[$rid]['enabled']) ? __('Rule enabled.', 'wp-fluxa-ecommerce-assistant') : __('Rule disabled.', 'wp-fluxa-ecommerce-assistant');
            $message_class = 'notice-success';
        }
    }
}

// Handle LINK ingest (download URL to text and upload)
if ('links' === $current_tab && isset($_POST['sensay_add_link']) && current_user_can('manage_options')) {
    check_admin_referer('sensay_add_link', 'sensay_add_link_nonce');
    $url = esc_url_raw($_POST['resource_url'] ?? '');
    if (!$url || !wp_http_validate_url($url)) {
        $message = __('Please enter a valid URL.', 'wp-fluxa-ecommerce-assistant');
        $message_class = 'notice-error';
    } else {
        $resp = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($resp)) {
            $message = __('Failed to fetch the URL: ', 'wp-fluxa-ecommerce-assistant') . $resp->get_error_message();
            $message_class = 'notice-error';
        } else {
            $body = wp_remote_retrieve_body($resp);
            if (!$body) {
                $message = __('Empty response from the URL.', 'wp-fluxa-ecommerce-assistant');
                $message_class = 'notice-error';
            } else {
                $upload_dir = wp_upload_dir();
                $host = parse_url($url, PHP_URL_HOST) ?: 'link';
                $fname = 'URL_' . sanitize_title($host . '-' . date('Ymd-His')) . '.txt';
                $tmp = trailingslashit($upload_dir['path']) . $fname;
                // Embed source URL at the top for context
                $content = "Source: $url\n\n" . wp_strip_all_tags($body);
                if (file_put_contents($tmp, $content) !== false) {
                    $signed = function_exists('sensay_get_signed_url') ? sensay_get_signed_url($fname) : ['success'=>false,'error'=>'Helper not found'];
                    if (!empty($signed['success'])) {
                        $up = function_exists('sensay_upload_file') ? sensay_upload_file($signed['signedUrl'], $tmp, 'text/plain') : ['success'=>false,'error'=>'Upload helper not found'];
                        if (!empty($up['success'])) {
                            $message = __('Link content added and queued for processing.', 'wp-fluxa-ecommerce-assistant');
                            $message_class = 'notice-success';
                        } else {
                            $message = __('Upload failed: ', 'wp-fluxa-ecommerce-assistant') . esc_html($up['error'] ?? 'Unknown');
                            $message_class = 'notice-error';
                        }
                    } else {
                        $message = __('Failed to get signed URL: ', 'wp-fluxa-ecommerce-assistant') . esc_html($signed['error'] ?? 'Unknown');
                        $message_class = 'notice-error';
                    }
                    @unlink($tmp);
                } else {
                    $message = __('Could not create a temporary file.', 'wp-fluxa-ecommerce-assistant');
                    $message_class = 'notice-error';
                }
            }
        }
    }
}
// Handle TEXT ingest (create txt file and upload)
if ('training-text' === $current_tab && isset($_POST['sensay_add_text']) && current_user_can('manage_options')) {
    check_admin_referer('sensay_add_text', 'sensay_add_text_nonce');
    $title = sanitize_text_field($_POST['text_title'] ?? '');
    $body  = wp_kses_post($_POST['text_body'] ?? '');
    $plain = trim(wp_strip_all_tags($body));
    if ($plain === '') {
        $message = __('Please enter some text to add.', 'wp-fluxa-ecommerce-assistant');
        $message_class = 'notice-error';
    } else {
        $upload_dir = wp_upload_dir();
        $fname = 'Text_' . sanitize_title(($title ?: 'snippet') . '-' . date('Ymd-His')) . '.txt';
        $tmp = trailingslashit($upload_dir['path']) . $fname;
        $content = ($title ? ('# ' . $title . "\n\n") : '') . $plain;
        if (file_put_contents($tmp, $content) !== false) {
            $signed = function_exists('sensay_get_signed_url') ? sensay_get_signed_url($fname) : ['success'=>false,'error'=>'Helper not found'];
            if (!empty($signed['success'])) {
                $up = function_exists('sensay_upload_file') ? sensay_upload_file($signed['signedUrl'], $tmp, 'text/plain') : ['success'=>false,'error'=>'Upload helper not found'];
                if (!empty($up['success'])) {
                    $message = __('Text content added and queued for processing.', 'wp-fluxa-ecommerce-assistant');
                    $message_class = 'notice-success';
                } else {
                    $message = __('Upload failed: ', 'wp-fluxa-ecommerce-assistant') . esc_html($up['error'] ?? 'Unknown');
                    $message_class = 'notice-error';
                }
            } else {
                $message = __('Failed to get signed URL: ', 'wp-fluxa-ecommerce-assistant') . esc_html($signed['error'] ?? 'Unknown');
                $message_class = 'notice-error';
            }
            @unlink($tmp);
        } else {
            $message = __('Could not create a temporary file.', 'wp-fluxa-ecommerce-assistant');
            $message_class = 'notice-error';
        }
    }
}
?>
<?php
// Handle Document Upload in-template (nonce + capability)
// Handle FILE upload
if ('training-files' === $current_tab && isset($_FILES['training_file']) && !empty($_FILES['training_file']['name']) && current_user_can('manage_options')) {
    check_admin_referer('sensay_upload_document', 'sensay_upload_nonce');
    $file      = $_FILES['training_file'];
    $filename  = sanitize_file_name($file['name']);
    $ext       = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed   = ['pdf','docx','txt','mp3','mp4','wav','m4a','mov'];
    if (!in_array($ext, $allowed, true)) {
        $message = __('Invalid file type. Allowed: PDF, DOCX, TXT, MP3, MP4, WAV, M4A, MOV.', 'wp-fluxa-ecommerce-assistant');
        $message_class = 'notice-error';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $message = __('Upload error. Please try again.', 'wp-fluxa-ecommerce-assistant');
        $message_class = 'notice-error';
    } else {
        $signed = function_exists('sensay_get_signed_url') ? sensay_get_signed_url($filename) : ['success'=>false,'error'=>'Helper not found'];
        if (!empty($signed['success'])) {
            $mime = wp_check_filetype($filename);
            $mime_type = !empty($mime['type']) ? $mime['type'] : 'application/octet-stream';
            $up = function_exists('sensay_upload_file') ? sensay_upload_file($signed['signedUrl'], $file['tmp_name'], $mime_type) : ['success'=>false,'error'=>'Upload helper not found'];
            if (!empty($up['success'])) {
                $message = __('File uploaded and queued for processing.', 'wp-fluxa-ecommerce-assistant');
                $message_class = 'notice-success';
            } else {
                $message = __('Upload failed: ', 'wp-fluxa-ecommerce-assistant') . esc_html($up['error'] ?? 'Unknown');
                $message_class = 'notice-error';
            }
        } else {
            $message = __('Failed to get signed URL: ', 'wp-fluxa-ecommerce-assistant') . esc_html($signed['error'] ?? 'Unknown');
            $message_class = 'notice-error';
        }
    }
}

// Fetch training documents for list view (filter by replica)
$training_documents = [];
if (in_array($current_tab, ['training-files'], true) && function_exists('sensay_get_training_documents')) {
    $training_response = sensay_get_training_documents();
    if (is_array($training_response) && !empty($training_response['success'])) {
        if (!empty($training_response['items'])) $training_documents = $training_response['items'];
        elseif (!empty($training_response['data'])) $training_documents = $training_response['data'];
    }
    $current_replica = get_option('sensay_replica_id');
    if ($current_replica && is_array($training_documents)) {
        $training_documents = array_values(array_filter($training_documents, function($doc) use ($current_replica) {
            $rid = $doc['replica_uuid'] ?? $doc['replicaId'] ?? null;
            return $rid && $rid === $current_replica;
        }));
    }
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('Train Chatbot', 'wp-fluxa-ecommerce-assistant'); ?></h1>

    <?php if ($current_tab === 'intro') : ?>
    <div class="fluxa-card" style="display:block">
        <h2 style="margin-top:0;"><?php esc_html_e('How to train your chatbot', 'wp-fluxa-ecommerce-assistant'); ?></h2>
        <p class="description" style="max-width:820px;">
            <?php esc_html_e('Choose one or more methods below to provide knowledge to your chatbot. You can add structured Q&A, paste short text, upload files (text, audio, video for transcription), import from WordPress content, or add external links to fetch content.', 'wp-fluxa-ecommerce-assistant'); ?>
        </p>

        <div class="fluxa-intro-grid" style="margin-top: 2em; display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:1000px;">
            <div class="intro-item" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;">
                <h3 style="margin:6px 0 8px;"><?php esc_html_e('Q&A', 'wp-fluxa-ecommerce-assistant'); ?></h3>
                <p class="muted" style="margin:0 0 10px;"><?php esc_html_e('Add specific questions and answers to guide the bot’s responses for common queries.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab','qa')); ?>"><?php esc_html_e('Open Q&A', 'wp-fluxa-ecommerce-assistant'); ?></a>
            </div>
            <div class="intro-item" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;">
                <h3 style="margin:6px 0 8px;"><?php esc_html_e('Training Text', 'wp-fluxa-ecommerce-assistant'); ?></h3>
                <p class="muted" style="margin:0 0 10px;"><?php esc_html_e('Paste short text snippets or notes. Great for quick additions, announcements, or policies.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab','training-text')); ?>"><?php esc_html_e('Add Text', 'wp-fluxa-ecommerce-assistant'); ?></a>
            </div>
            <div class="intro-item" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;">
                <h3 style="margin:6px 0 8px;"><?php esc_html_e('Upload Files', 'wp-fluxa-ecommerce-assistant'); ?></h3>
                <p class="muted" style="margin:0 0 10px;"><?php esc_html_e('Upload PDFs, DOCX, TXT, or audio/video for transcription. Ideal for manuals, catalogs, FAQs, or recordings.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab','training-files')); ?>"><?php esc_html_e('Upload Files', 'wp-fluxa-ecommerce-assistant'); ?></a>
            </div>
            <div class="intro-item" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;">
                <h3 style="margin:6px 0 8px;"><?php esc_html_e('Links', 'wp-fluxa-ecommerce-assistant'); ?></h3>
                <p class="muted" style="margin:0 0 10px;"><?php esc_html_e('Add a URL. We fetch and convert the page (or supported media) into knowledge for the bot.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab','links')); ?>"><?php esc_html_e('Add Link', 'wp-fluxa-ecommerce-assistant'); ?></a>
            </div>
            <div class="intro-item" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;">
                <h3 style="margin:6px 0 8px;"><?php esc_html_e('Import Content', 'wp-fluxa-ecommerce-assistant'); ?></h3>
                <p class="muted" style="margin:0 0 10px;"><?php esc_html_e('Select and import Products, Posts, Pages, custom post types, and Menus to text files for training.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab','import-pages')); ?>"><?php esc_html_e('Import Content', 'wp-fluxa-ecommerce-assistant'); ?></a>
            </div>
            <div class="intro-item" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;">
                <h3 style="margin:6px 0 8px;"><?php esc_html_e('Auto Imports', 'wp-fluxa-ecommerce-assistant'); ?></h3>
                <p class="muted" style="margin:0 0 10px;"><?php esc_html_e('Create rules to automatically import content on a schedule.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('tab','auto-imports')); ?>"><?php esc_html_e('Open Auto Imports', 'wp-fluxa-ecommerce-assistant'); ?></a>
            </div>
           
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Tabs (hidden on intro)
    if ($current_tab !== 'intro') {
        $tabs = [
            'qa' => __('Q&A', 'wp-fluxa-ecommerce-assistant'),
            'training-text' => __('Training Text', 'wp-fluxa-ecommerce-assistant'),
            'training-files' => __('Training Documents (Files)', 'wp-fluxa-ecommerce-assistant'),
            'links' => __('Links', 'wp-fluxa-ecommerce-assistant'),
            'import-pages' => __('Import Content', 'wp-fluxa-ecommerce-assistant'),
            'auto-imports' => __('Auto Imports', 'wp-fluxa-ecommerce-assistant'),
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key=>$label) {
            $cls = ($current_tab === $key) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url(add_query_arg('tab', $key)) . '" class="nav-tab' . esc_attr($cls) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    if ($current_tab === 'qa') :
    ?>
    <div class="fluxa-card">
        <h2><?php esc_html_e('Train with Custom Q&A', 'wp-fluxa-ecommerce-assistant'); ?></h2>
        <p><?php esc_html_e('Add custom questions and answers to train your chatbot.', 'wp-fluxa-ecommerce-assistant'); ?></p>

        <form id="training-form" method="post" action="">
            <?php wp_nonce_field('sensay_chatbot_training', 'sensay_training_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="question"><?php esc_html_e('Question', 'wp-fluxa-ecommerce-assistant'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="question" name="question" class="regular-text" required />
                        <p class="description"><?php esc_html_e('Enter a question that users might ask.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="answer"><?php esc_html_e('Answer', 'wp-fluxa-ecommerce-assistant'); ?></label>
                    </th>
                    <td>
                        <?php wp_editor('', 'answer', [
                            'textarea_name' => 'answer',
                            'textarea_rows' => 5,
                            'media_buttons' => false,
                            'teeny' => true,
                            'quicktags' => false,
                        ]); ?>
                        <p class="description"><?php esc_html_e('Enter the answer that the chatbot should provide.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="category"><?php esc_html_e('Category', 'wp-fluxa-ecommerce-assistant'); ?></label>
                    </th>
                    <td>
                        <select id="category" name="category" class="regular-text">
                            <option value="general"><?php esc_html_e('General', 'wp-fluxa-ecommerce-assistant'); ?></option>
                            <option value="products"><?php esc_html_e('Products', 'wp-fluxa-ecommerce-assistant'); ?></option>
                            <option value="orders"><?php esc_html_e('Orders', 'wp-fluxa-ecommerce-assistant'); ?></option>
                            <option value="shipping"><?php esc_html_e('Shipping', 'wp-fluxa-ecommerce-assistant'); ?></option>
                            <option value="returns"><?php esc_html_e('Returns', 'wp-fluxa-ecommerce-assistant'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Select a category for this Q&A pair.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="save_training" class="button button-primary"><?php esc_attr_e('Save Training Data', 'wp-fluxa-ecommerce-assistant'); ?></button>
            </p>
        </form>
    </div>

    <div class="fluxa-card">
        <h2><?php esc_html_e('Training Data', 'wp-fluxa-ecommerce-assistant'); ?></h2>
        <p><?php esc_html_e('Review and manage your training data below.', 'wp-fluxa-ecommerce-assistant'); ?></p>

        <div id="training-data-container">
            <p><?php esc_html_e('Loading training data...', 'wp-fluxa-ecommerce-assistant'); ?></p>
        </div>
    </div>

    <?php elseif ($current_tab === 'auto-imports') : ?>
    <?php if (!empty($message)) : ?>
        <div class="notice <?php echo esc_attr($message_class); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>
    <?php $rules = get_option('fluxa_auto_import_rules', []); if (!is_array($rules)) { $rules = []; } ?>

    <div class="fluxa-card">
        <h2><?php esc_html_e('Auto Imports', 'wp-fluxa-ecommerce-assistant'); ?></h2>
        <p class="description"><?php esc_html_e('Create rules to run automatic imports on a schedule. You can start with WordPress content imports.', 'wp-fluxa-ecommerce-assistant'); ?></p>
        <form method="post">
            <?php wp_nonce_field('fluxa_auto_imports', 'fluxa_auto_imports_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="rule_name"><?php esc_html_e('Rule name', 'wp-fluxa-ecommerce-assistant'); ?></label></th>
                    <td><input type="text" class="regular-text" id="rule_name" name="rule_name" placeholder="<?php echo esc_attr__('e.g., Daily WP Content Import', 'wp-fluxa-ecommerce-assistant'); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rule_source"><?php esc_html_e('Source type', 'wp-fluxa-ecommerce-assistant'); ?></label></th>
                    <td>
                        <select id="rule_source" name="rule_source">
                            <option value="wordpress_content"><?php esc_html_e('WordPress Content (Posts/Pages/Menus)', 'wp-fluxa-ecommerce-assistant'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rule_schedule"><?php esc_html_e('Schedule', 'wp-fluxa-ecommerce-assistant'); ?></label></th>
                    <td>
                        <select id="rule_schedule" name="rule_schedule">
                            <option value="hourly"><?php esc_html_e('Hourly', 'wp-fluxa-ecommerce-assistant'); ?></option>
                            <option value="daily" selected><?php esc_html_e('Daily', 'wp-fluxa-ecommerce-assistant'); ?></option>
                            <option value="weekly"><?php esc_html_e('Weekly', 'wp-fluxa-ecommerce-assistant'); ?></option>
                            <option value="monthly"><?php esc_html_e('Monthly', 'wp-fluxa-ecommerce-assistant'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enable now', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <td>
                        <label><input type="checkbox" name="rule_enabled" value="1"> <?php esc_html_e('Schedule this rule immediately', 'wp-fluxa-ecommerce-assistant'); ?></label>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="fluxa_auto_import_add" class="button button-primary"><?php esc_html_e('Add Rule', 'wp-fluxa-ecommerce-assistant'); ?></button>
            </p>
        </form>
    </div>

    <div class="fluxa-card">
        <h2><?php esc_html_e('Rules', 'wp-fluxa-ecommerce-assistant'); ?></h2>
        <?php if (!empty($rules)) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Source', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Schedule', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Status', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Next Run', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Last Run', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Runs', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Actions', 'wp-fluxa-ecommerce-assistant'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rid => $r) :
                    $next = wp_next_scheduled('fluxa_auto_import_run', ['rule_id' => $rid]);
                ?>
                <tr>
                    <td><?php echo esc_html($r['name']); ?></td>
                    <td><?php echo esc_html($r['source']); ?></td>
                    <td><?php echo esc_html($r['schedule']); ?></td>
                    <td><?php echo !empty($r['enabled']) ? esc_html__('Enabled', 'wp-fluxa-ecommerce-assistant') : esc_html__('Disabled', 'wp-fluxa-ecommerce-assistant'); ?></td>
                    <td><?php echo (!empty($r['enabled']) && $next) ? esc_html( date_i18n('Y-m-d H:i', $next) ) : '—'; ?></td>
                    <td><?php echo !empty($r['last_run']) ? esc_html( date_i18n('Y-m-d H:i', (int)$r['last_run']) ) : '—'; ?></td>
                    <td><?php echo esc_html( (int)($r['total_runs'] ?? 0) ); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('fluxa_auto_imports', 'fluxa_auto_imports_nonce'); ?>
                            <input type="hidden" name="rule_id" value="<?php echo esc_attr($rid); ?>">
                            <button class="button" name="fluxa_auto_import_toggle" value="1" type="submit"><?php echo !empty($r['enabled']) ? esc_html__('Disable', 'wp-fluxa-ecommerce-assistant') : esc_html__('Enable', 'wp-fluxa-ecommerce-assistant'); ?></button>
                        </form>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('fluxa_auto_imports', 'fluxa_auto_imports_nonce'); ?>
                            <input type="hidden" name="rule_id" value="<?php echo esc_attr($rid); ?>">
                            <button class="button button-link-delete" name="fluxa_auto_import_delete" value="1" type="submit" onclick="return confirm('<?php echo esc_js(__('Delete this rule?', 'wp-fluxa-ecommerce-assistant')); ?>');"><?php esc_html_e('Delete', 'wp-fluxa-ecommerce-assistant'); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p><?php esc_html_e('No rules yet. Create your first rule above.', 'wp-fluxa-ecommerce-assistant'); ?></p>
        <?php endif; ?>
    </div>

    <?php elseif ($current_tab === 'training-files') : ?>
    <?php if (!empty($message)) : ?>
        <div class="notice <?php echo esc_attr($message_class); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>

    <div class="fluxa-kb-card">
        <div class="fluxa-kb-header">
            <h2><?php esc_html_e('Training Documents (Files)', 'wp-fluxa-ecommerce-assistant'); ?></h2>
            <p class="description"><?php esc_html_e('Upload files to enrich your Sensay AI chat bot. Audio/video will be transcribed.', 'wp-fluxa-ecommerce-assistant'); ?></p>
        </div>
        <div class="fluxa-kb">
            <div class="kb-panel kb-panel-files" data-panel="files">
                <h3><?php esc_html_e('Drag & drop text, video or audio files', 'wp-fluxa-ecommerce-assistant'); ?></h3>
                <p class="muted"><?php esc_html_e('Audio and video files will be transcribed', 'wp-fluxa-ecommerce-assistant'); ?></p>
                <form method="post" enctype="multipart/form-data" class="sensay-upload-form">
                    <?php wp_nonce_field('sensay_upload_document','sensay_upload_nonce'); ?>
                    <div class="sensay-upload-area" id="drag-drop-area">
                        <button type="button" class="button button-secondary button-hero" id="browse-btn"><?php esc_html_e('Upload files', 'wp-fluxa-ecommerce-assistant'); ?></button>
                        <p class="muted small"><?php esc_html_e('* Maximum file size is 25mb', 'wp-fluxa-ecommerce-assistant'); ?></p>
                        <input type="file" name="training_file" id="training_file" class="file-input" accept=".pdf,.docx,.txt,.mp3,.mp4,.wav,.m4a,.mov">
                        <div class="file-info" id="file-info" style="display:none;">
                            <div class="file-preview">
                                <div class="file-details">
                                    <div class="file-name" id="file-name"></div>
                                    <div class="file-size" id="file-size"></div>
                                </div>
                                <button type="button" class="button-link-delete remove-file" id="remove-file"><?php esc_html_e('Remove', 'wp-fluxa-ecommerce-assistant'); ?></button>
                            </div>
                        </div>
                    </div>
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="upload-button" disabled><?php esc_html_e('Upload', 'wp-fluxa-ecommerce-assistant'); ?></button>
                    </p>
                </form>
            </div>
        </div>
    </div>

    <div class="fluxa-card">
        <h2><?php esc_html_e('Training Documents', 'wp-fluxa-ecommerce-assistant'); ?></h2>
        <?php if (!empty($training_documents)) : ?>
            <table id="training-documents" class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th><?php esc_html_e('Title', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Type', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Status', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Created At', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <th><?php esc_html_e('Actions', 'wp-fluxa-ecommerce-assistant'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($training_documents as $doc) {
                    $rid = $doc['replica_uuid'] ?? $doc['replicaId'] ?? null;
                    if ($rid !== get_option('sensay_replica_id')) { continue; }
                    $file_id  = $doc['id'] ?? $doc['fileId'] ?? '';
                    $title    = $doc['filename'] ?? $doc['name'] ?? 'Untitled';
                    $raw_type = $doc['type'] ?? $doc['fileType'] ?? 'unknown';
                    $type     = $raw_type === 'file_upload' ? 'File' : ($raw_type === 'text' ? 'Text' : $raw_type);
                    $status   = $doc['status'] ?? 'unknown';
                    $status_text = match($status) {
                        'AWAITING_UPLOAD' => 'Processing 1/4',
                        'FILE_UPLOADED'   => 'Processing 2/4',
                        'VECTOR_CREATED'  => 'Processing 3/4',
                        'READY'           => 'Ready',
                        default           => $status
                    };
                    $created = !empty($doc['created_at']) ? esc_html(date('Y-m-d H:i', strtotime($doc['created_at']))) : 'N/A';
                    echo '<tr data-doc-id="'.esc_attr($file_id).'">';
                    echo '<td>'.esc_html($title).'</td>';
                    echo '<td>'.esc_html($type).'</td>';
                    echo '<td><span class="status-badge">'.esc_html($status_text).'</span></td>';
                    echo '<td>'.$created.'</td>';
                    echo '<td>';
                    echo '<button class="button button-small view-document" data-doc-id="'.esc_attr($file_id).'">'.esc_html__('View','wp-fluxa-ecommerce-assistant').'</button> ';
                    echo '<button class="button button-small button-link-delete delete-document" data-doc-id="'.esc_attr($file_id).'" data-title="'.esc_attr($title).'">'.esc_html__('Delete','wp-fluxa-ecommerce-assistant').'</button>';
                    echo '</td></tr>';
                } ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php esc_html_e('No training documents found.', 'wp-fluxa-ecommerce-assistant'); ?></p>
        <?php endif; ?>
    </div>

    <?php elseif ($current_tab === 'links') : ?>
    <?php if (!empty($message)) : ?>
        <div class="notice <?php echo esc_attr($message_class); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>
    <div class="fluxa-kb-card">
        <div class="fluxa-kb-header">
            <h2><?php esc_html_e('Links', 'wp-fluxa-ecommerce-assistant'); ?></h2>
            <p class="description"><?php esc_html_e('Add video, audio, or web links to ingest their content.', 'wp-fluxa-ecommerce-assistant'); ?></p>
        </div>
        <div class="fluxa-kb">
            <div class="kb-panel kb-panel-links" data-panel="links">
                <form method="post" class="kb-inline-form">
                    <?php wp_nonce_field('sensay_add_link','sensay_add_link_nonce'); ?>
                    <input type="url" name="resource_url" class="regular-text kb-url-input" placeholder="<?php echo esc_attr__('Paste a link here...', 'wp-fluxa-ecommerce-assistant'); ?>" required>
                    <button type="submit" name="sensay_add_link" class="button button-secondary"><?php esc_html_e('Add', 'wp-fluxa-ecommerce-assistant'); ?></button>
                </form>
                <div class="kb-icons muted">
                    <span class="dashicons dashicons-video-alt3"></span>
                    <span class="dashicons dashicons-format-audio"></span>
                    <span class="dashicons dashicons-media-document"></span>
                    <span class="dashicons dashicons-admin-site"></span>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($current_tab === 'training-text') : ?>
    <?php if (!empty($message)) : ?>
        <div class="notice <?php echo esc_attr($message_class); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>
    <div class="fluxa-kb-card">
        <div class="fluxa-kb-header">
            <h2><?php esc_html_e('Training Text', 'wp-fluxa-ecommerce-assistant'); ?></h2>
            <p class="description"><?php esc_html_e('Add plain text to train your chatbot.', 'wp-fluxa-ecommerce-assistant'); ?></p>
        </div>
        <div class="fluxa-kb">
            <div class="kb-panel kb-panel-text" data-panel="text">
                <form method="post" class="kb-text-form">
                    <?php wp_nonce_field('sensay_add_text','sensay_add_text_nonce'); ?>
                    <input type="text" name="text_title" class="regular-text" placeholder="<?php echo esc_attr__('Title (optional)', 'wp-fluxa-ecommerce-assistant'); ?>">
                    <textarea name="text_body" rows="6" class="large-text" placeholder="<?php echo esc_attr__('Enter your text content here...', 'wp-fluxa-ecommerce-assistant'); ?>" required></textarea>
                    <button type="submit" name="sensay_add_text" class="button button-primary button-hero"><?php esc_html_e('Add', 'wp-fluxa-ecommerce-assistant'); ?></button>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($current_tab === 'import-pages') : ?>
    <?php
        // Gather public post types (including posts, pages, and CPTs)
        $public_types = get_post_types(['public' => true], 'objects');
        // Exclude media attachments
        unset($public_types['attachment']);
        // Build allowed values for selector (add virtual 'menus')
        $allowed_types = array_merge(array_keys($public_types), ['menus']);
        // Selected types from request, or default to first four by desired order
        if (isset($_POST['content_types']) && is_array($_POST['content_types'])) {
            $sel_types = array_values(array_intersect($allowed_types, array_map('sanitize_key', $_POST['content_types'])));
        } else {
            // Desired base order for defaults
            $base_order = [];
            if (isset($public_types['product'])) { $base_order[] = 'product'; }
            if (isset($public_types['post']))    { $base_order[] = 'post'; }
            if (isset($public_types['page']))    { $base_order[] = 'page'; }
            $base_order[] = 'menus';
            // Append remaining CPTs alphabetically by label
            $rest = $public_types;
            unset($rest['product'], $rest['post'], $rest['page']);
            uasort($rest, function($a, $b){
                $al = isset($a->labels->name) ? $a->labels->name : $a->name;
                $bl = isset($b->labels->name) ? $b->labels->name : $b->name;
                return strcasecmp($al, $bl);
            });
            foreach ($rest as $pt => $obj) { $base_order[] = $pt; }
            // Take the first four that are allowed and exist
            $sel_types = array_values(array_slice(array_values(array_intersect($base_order, $allowed_types)), 0, 4));
        }

        // Build a list of post IDs to display (exclude the virtual 'menus')
        $post_types_for_query = array_values(array_filter($sel_types, function($t){ return $t !== 'menus'; }));
        $args = [
            'post_type'      => !empty($post_types_for_query) ? $post_types_for_query : ['post','page'],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];
        $query_ids = get_posts($args);
        // Menus list (only if selected)
        $menus = in_array('menus', $sel_types, true) ? wp_get_nav_menus() : [];
    ?>
    <div class="fluxa-card">
        <h2><?php esc_html_e('Import WordPress Content', 'wp-fluxa-ecommerce-assistant'); ?></h2>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Elements', 'wp-fluxa-ecommerce-assistant'); ?></th>
                    <td>
                        <select name="content_types[]" multiple size="7" style="min-width:260px;">
                            <?php
                                // Desired order: product (if exists), post, page, menus, then other public types alphabetically
                                $ordered = [];
                                if (isset($public_types['product'])) $ordered['product'] = $public_types['product'];
                                if (isset($public_types['post']))    $ordered['post']    = $public_types['post'];
                                if (isset($public_types['page']))    $ordered['page']    = $public_types['page'];
                                // Add remaining CPTs (exclude product/post/page) sorted by label
                                $rest = $public_types;
                                unset($rest['product'], $rest['post'], $rest['page']);
                                uasort($rest, function($a, $b){
                                    $al = isset($a->labels->name) ? $a->labels->name : $a->name;
                                    $bl = isset($b->labels->name) ? $b->labels->name : $b->name;
                                    return strcasecmp($al, $bl);
                                });
                                // Render product first if available
                                if (isset($ordered['product'])) {
                                    $pt = 'product'; $obj = $ordered['product'];
                                    echo '<option value="'.esc_attr($pt).'" '.selected(in_array($pt,$sel_types,true), true, false).'>'.esc_html($obj->labels->name ?: $pt).' ('.esc_html($pt).')</option>';
                                }
                                // Render post
                                if (isset($ordered['post'])) {
                                    $pt = 'post'; $obj = $ordered['post'];
                                    echo '<option value="'.esc_attr($pt).'" '.selected(in_array($pt,$sel_types,true), true, false).'>'.esc_html($obj->labels->name ?: $pt).' ('.esc_html($pt).')</option>';
                                }
                                // Render page
                                if (isset($ordered['page'])) {
                                    $pt = 'page'; $obj = $ordered['page'];
                                    echo '<option value="'.esc_attr($pt).'" '.selected(in_array($pt,$sel_types,true), true, false).'>'.esc_html($obj->labels->name ?: $pt).' ('.esc_html($pt).')</option>';
                                }
                                // Render Menus as 3rd (or 4th if product exists)
                                echo '<option value="menus" '.selected(in_array('menus', $sel_types, true), true, false).'>'.esc_html__('Menus', 'wp-fluxa-ecommerce-assistant').' (menus)</option>';
                                // Render remaining CPTs
                                foreach ($rest as $pt => $obj) {
                                    echo '<option value="'.esc_attr($pt).'" '.selected(in_array($pt,$sel_types,true), true, false).'>'.esc_html($obj->labels->name ?: $pt).' ('.esc_html($pt).')</option>';
                                }
                            ?>
                        </select>
                        <p class="description"><?php esc_html_e('Hold Command (⌘) or Ctrl to select multiple.', 'wp-fluxa-ecommerce-assistant'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Send','wp-fluxa-ecommerce-assistant'), 'secondary', 'filter_content', false); ?>

            <h3 style="margin-top:14px;"><?php esc_html_e('Imported', 'wp-fluxa-ecommerce-assistant'); ?></h3>
            <div style="max-height:500px;overflow:auto;border:1px solid #ddd;padding:10px;margin:8px 0 12px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="fluxa-check-all"></th>
                            <th><?php esc_html_e('Title', 'wp-fluxa-ecommerce-assistant'); ?></th>
                            <th><?php esc_html_e('Type', 'wp-fluxa-ecommerce-assistant'); ?></th>
                            <th><?php esc_html_e('Last Modified', 'wp-fluxa-ecommerce-assistant'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $has_rows = false;
                        if (!empty($query_ids)) {
                            $has_rows = true;
                            foreach ($query_ids as $pid):
                    ?>
                        <tr>
                            <td><input type="checkbox" name="selected_items[]" value="post:<?php echo esc_attr($pid); ?>"></td>
                            <td><?php echo esc_html(get_the_title($pid) ?: '(no title)'); ?></td>
                            <td><?php echo esc_html(get_post_type($pid)); ?></td>
                            <td><?php echo esc_html(get_the_modified_date('Y-m-d H:i', $pid)); ?></td>
                        </tr>
                    <?php
                            endforeach;
                        }
                        if (!empty($menus)) {
                            $has_rows = true;
                            foreach ($menus as $menu):
                                $items = wp_get_nav_menu_items($menu->term_id, ['post_status'=>'publish']);
                                $cnt = is_array($items) ? count($items) : 0;
                    ?>
                        <tr>
                            <td><input type="checkbox" name="selected_items[]" value="menu:<?php echo esc_attr($menu->term_id); ?>"></td>
                            <td><?php echo esc_html($menu->name); ?> (<?php echo esc_html(sprintf(_n('%d item','%d items',$cnt,'wp-fluxa-ecommerce-assistant'), $cnt)); ?>)</td>
                            <td><?php esc_html_e('menu','wp-fluxa-ecommerce-assistant'); ?></td>
                            <td>&mdash;</td>
                        </tr>
                    <?php
                            endforeach;
                        }
                        if (!$has_rows) {
                            echo '<tr><td colspan="4">' . esc_html__('No content found for the selected post types.', 'wp-fluxa-ecommerce-assistant') . '</td></tr>';
                        }
                    ?>
                    </tbody>
                </table>
            </div>
            <?php wp_nonce_field('fluxa_import_content','fluxa_import_content_nonce'); ?>
            <?php submit_button(__('Export Selected to TXT','wp-fluxa-ecommerce-assistant'),'primary','import_content_submit',false); ?>
        </form>
    </div>
    <?php if (isset($_POST['import_content_submit']) && current_user_can('manage_options')) : ?>
        <?php
        check_admin_referer('fluxa_import_content','fluxa_import_content_nonce');
        $imported = 0; $errors = [];
        $selected_items = isset($_POST['selected_items']) ? (array)$_POST['selected_items'] : [];
        if ($selected_items) {
            $upload_dir = wp_upload_dir();
            foreach ($selected_items as $raw) {
                $raw = sanitize_text_field($raw);
                if (strpos($raw, ':') === false) { continue; }
                list($kind, $id) = explode(':', $raw, 2);
                $id = intval($id);
                if ($kind === 'post') {
                    $post = get_post($id);
                    if (!$post || $post->post_status !== 'publish') { continue; }
                    $title = get_the_title($id) ?: '(untitled)';
                    $body  = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
                    $header = "### WP CONTENT\n" .
                              'type: ' . $post->post_type . "\n" .
                              'title: ' . $title . "\n" .
                              'url: ' . get_permalink($id) . "\n" .
                              'modified_at: ' . get_the_modified_date('Y-m-d H:i', $id) . "\n\n";
                    $content = $header . $body;
                    $slug = sanitize_title($title);
                    $fname = ucfirst($post->post_type) . '_' . $slug . '_' . $id . '.txt';
                    $path = trailingslashit($upload_dir['path']) . $fname;
                    if (false !== file_put_contents($path, $content)) { $imported++; }
                    else { $errors[] = sprintf(__('Failed to write file for "%s"','wp-fluxa-ecommerce-assistant'), $title); }
                } elseif ($kind === 'menu') {
                    $menu = wp_get_nav_menu_object($id);
                    if (!$menu) { continue; }
                    $items = wp_get_nav_menu_items($menu->term_id, ['post_status'=>'publish']);
                    $lines = [
                        '### MENU',
                        'name: ' . $menu->name,
                        'locale: ' . get_locale(),
                        'updated_at: ' . date('Y-m-d H:i'),
                        '',
                        '[Tree]'
                    ];
                    $by_parent = [];
                    if ($items) { foreach ($items as $it) { $by_parent[$it->menu_item_parent][] = $it; } }
                    $walk = function($parent = 0, $lvl = 0) use (&$walk, &$by_parent, &$lines) {
                        if (empty($by_parent[$parent])) return;
                        foreach ($by_parent[$parent] as $it) {
                            $indent = str_repeat('  ', $lvl);
                            $title  = $it->title ?: '(untitled)';
                            $url    = $it->url ?: '';
                            $lines[] = $indent . '- ' . $title . ($url ? ' -> ' . $url : '');
                            if (!empty($by_parent[$it->ID])) $walk($it->ID, $lvl+1);
                        }
                    };
                    $walk(0);
                    $content = implode("\n", $lines);
                    $fname = 'Menu_' . sanitize_title($menu->name) . '_' . $menu->term_id . '.txt';
                    $path = trailingslashit($upload_dir['path']) . $fname;
                    if (false !== file_put_contents($path, $content)) { $imported++; }
                    else { $errors[] = sprintf(__('Failed to write file for menu "%s"','wp-fluxa-ecommerce-assistant'), $menu->name); }
                }
            }
        }
        if ($imported > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('Exported %d item(s) to uploads as .txt files.','wp-fluxa-ecommerce-assistant'), $imported) . '</p></div>';
            echo '<p class="description">' . sprintf(esc_html__('Files saved under: %s','wp-fluxa-ecommerce-assistant'), esc_html($upload_dir['path'])) . '</p>';
        }
        if ($errors) {
            echo '<div class="notice notice-error"><p>'.esc_html__('Some errors occurred:','wp-fluxa-ecommerce-assistant').'</p><ul>';
            foreach ($errors as $e) echo '<li>'.esc_html($e).'</li>';
            echo '</ul></div>';
        }
        ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.training-item {
    background: #fff;
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 4px;
}

.training-item h3 {
    margin-top: 0;
    margin-bottom: 10px;
    padding-bottom: 5px;
    border-bottom: 1px solid #eee;
}

.training-meta {
    font-size: 12px;
    color: #666;
    margin-bottom: 10px;
}

.training-actions {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eee;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Submit training data via AJAX
    $('#training-form').on('submit', function(e) {
        e.preventDefault();
        var formData = {
            action: 'sensay_save_training',
            nonce: $('input[name="sensay_training_nonce"]').val(),
            question: $('#question').val(),
            answer: $('#answer').val(),
            category: $('#category').val()
        };
        $.post(ajaxurl, formData, function(response) {
            if (response && response.success) {
                alert('<?php echo esc_js(__('Training data saved successfully!', 'wp-fluxa-ecommerce-assistant')); ?>');
                loadTrainingData();
                $('#question').val('');
                $('#answer').val('');
                $('#category').val('general');
            } else {
                alert((response && response.data && response.data.message) || '<?php echo esc_js(__('Failed to save. Please try again.', 'wp-fluxa-ecommerce-assistant')); ?>');
            }
        }).fail(function() {
            alert('<?php echo esc_js(__('AJAX error. Please try again.', 'wp-fluxa-ecommerce-assistant')); ?>');
        });
    });

    function loadTrainingData() {
        var data = {
            action: 'sensay_get_training_data',
            nonce: $('input[name="sensay_training_nonce"]').val()
        };
        $.get(ajaxurl, data, function(response) {
            if (response && response.success && Array.isArray(response.data)) {
                if (response.data.length === 0) {
                    $('#training-data-container').html('<p><?php echo esc_js(__('No training data yet.', 'wp-fluxa-ecommerce-assistant')); ?></p>');
                    return;
                }
                var html = '';
                response.data.forEach(function(item) {
                    html += '<div class="training-item">' +
                            '<h3>' + item.question + '</h3>' +
                            '<div class="training-meta"><?php echo esc_js(__('Category:', 'wp-fluxa-ecommerce-assistant')); ?> ' + item.category + ' &middot; ' + item.date + '</div>' +
                            '<div class="training-answer">' + item.answer + '</div>' +
                            '<div class="training-actions">' +
                                '<button class="button button-link-delete delete-training" data-id="' + item.id + '"><?php echo esc_js(__('Delete', 'wp-fluxa-ecommerce-assistant')); ?></button>' +
                            '</div>' +
                        '</div>';
                });
                $('#training-data-container').html(html);
            } else {
                $('#training-data-container').html('<p><?php echo esc_js(__('Failed to load training data.', 'wp-fluxa-ecommerce-assistant')); ?></p>');
            }
        }).fail(function() {
            $('#training-data-container').html('<p><?php echo esc_js(__('AJAX error while loading.', 'wp-fluxa-ecommerce-assistant')); ?></p>');
        });
    }

    $(document).on('click', '.delete-training', function(e) {
        e.preventDefault();
        var id = parseInt($(this).data('id'), 10) || 0;
        if (!id) return;
        if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this item?', 'wp-fluxa-ecommerce-assistant')); ?>')) return;
        var data = {
            action: 'sensay_delete_training',
            nonce: $('input[name="sensay_training_nonce"]').val(),
            id: id
        };
        $.post(ajaxurl, data, function(response) {
            if (response && response.success) {
                loadTrainingData();
            } else {
                alert((response && response.data && response.data.message) || '<?php echo esc_js(__('Failed to delete.', 'wp-fluxa-ecommerce-assistant')); ?>');
            }
        }).fail(function() {
            alert('<?php echo esc_js(__('AJAX error. Please try again.', 'wp-fluxa-ecommerce-assistant')); ?>');
        });
    });

    // Initial load
    loadTrainingData();
    // Products tab: toggle advanced rows
    function toggleProductsAdvanced() {
        var on = $('#products_auto_enabled').is(':checked');
        $('.products-advanced').toggle(on);
    }
    $(document).on('change', '#products_auto_enabled', toggleProductsAdvanced);
    toggleProductsAdvanced();

    // Import Content: select all toggle
    $(document).on('change', '#fluxa-check-all', function(){
        var on = $(this).is(':checked');
        $('input[name="selected_posts[]"]').prop('checked', on);
    });
});
</script>

<div id="document-details-modal" class="sensay-modal" style="display:none;">
  <div class="sensay-modal-content">
    <div class="sensay-modal-header">
      <h2><?php esc_html_e('Document Details','wp-fluxa-ecommerce-assistant'); ?></h2>
      <span class="sensay-modal-close">&times;</span>
    </div>
    <div class="sensay-modal-body">
      <div class="document-detail"><strong><?php esc_html_e('Filename','wp-fluxa-ecommerce-assistant'); ?>:</strong> <span id="doc-filename"></span></div>
      <div class="document-detail"><strong><?php esc_html_e('Type','wp-fluxa-ecommerce-assistant'); ?>:</strong> <span id="doc-type"></span></div>
      <div class="document-detail"><strong><?php esc_html_e('Status','wp-fluxa-ecommerce-assistant'); ?>:</strong> <span id="doc-status"></span></div>
      <div class="document-detail"><strong><?php esc_html_e('Created At','wp-fluxa-ecommerce-assistant'); ?>:</strong> <span id="doc-created"></span></div>
      <div class="document-detail full-width">
        <strong><?php esc_html_e('Content','wp-fluxa-ecommerce-assistant'); ?>:</strong>
        <div id="doc-content" class="document-content"></div>
      </div>
    </div>
  </div>
</div>

<style>
  .fluxa-card{background:#fff;border:1px solid #ccd0d4;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:20px;margin-bottom:20px; max-width: unset;}
  .fluxa-kb-card{background:#fff;border:1px dashed #e5e7eb;border-radius:12px;padding:24px;margin-bottom:24px}
  .fluxa-kb-header h2{margin:0 0 6px}
  .fluxa-kb{background:#f9fafb;border-radius:10px;padding:24px;border:1px solid #eef2f7}
  .fluxa-kb-tabs{display:flex;gap:8px;justify-content:center;margin-bottom:18px}
  .kb-tab{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:6px 14px;cursor:pointer}
  .kb-tab.is-active{background:#111827;color:#fff;border-color:#111827}
  .kb-panel{text-align:center}
  .kb-inline-form{display:flex;gap:10px;justify-content:center;align-items:center;margin:12px auto;max-width:640px}
  .kb-url-input{flex:1;min-width:340px}
  .kb-text-form{display:flex;flex-direction:column;gap:10px;max-width:640px;margin:0 auto}
  .muted{color:#6b7280}
  .small{font-size:12px}
  .sensay-upload-area{border:2px dashed #c3c4c7;border-radius:4px;padding:40px 20px;text-align:center;background:#f9f9f9;margin-bottom:20px;transition:.3s}
  .sensay-upload-area.highlight{border-color:#2271b1;background:#f0f6fc}
  .browse-link{color:#2271b1;text-decoration:underline;cursor:pointer}
  .file-input{position:absolute;width:0.1px;height:0.1px;opacity:0;overflow:hidden;z-index:-1}
  .status-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;background:#eef}
  .sensay-modal{position:fixed;z-index:100000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.7)}
  .sensay-modal-content{background:#fff;margin:5% auto;padding:20px;border:1px solid #888;width:80%;max-width:800px;max-height:80vh;overflow-y:auto;position:relative}
  .sensay-modal-header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #ddd;margin-bottom:15px}
  .sensay-modal-close{cursor:pointer;font-size:24px}
  .document-content{margin-top:10px;padding:15px;background:#fff;border:1px solid #ddd;border-radius:4px;max-height:400px;overflow:auto}
</style>

<script>
jQuery(function($){
  // Drag & drop upload interactions
  const dropArea = $('#drag-drop-area'), fileInput = $('#training_file'),
        fileInfo = $('#file-info'), fileName = $('#file-name'), fileSize = $('#file-size'),
        removeBtn = $('#remove-file'), browse = $('.browse-link'), uploadBtn = $('#upload-button');
  function prevent(e){ e.preventDefault(); e.stopPropagation(); }
  ['dragenter','dragover','dragleave','drop'].forEach(ev=>{
    document.body.addEventListener(ev, prevent, false);
    if (dropArea[0]) dropArea[0].addEventListener(ev, prevent, false);
  });
  ['dragenter','dragover'].forEach(ev=>{
    if (dropArea[0]) dropArea[0].addEventListener(ev, ()=>dropArea.addClass('highlight'), false);
  });
  ['dragleave','drop'].forEach(ev=>{
    if (dropArea[0]) dropArea[0].addEventListener(ev, ()=>dropArea.removeClass('highlight'), false);
  });
  dropArea.on('drop', (e)=>{ const files = e.originalEvent.dataTransfer.files; if (fileInput[0]) fileInput[0].files = files; handleFiles(files); });
  browse.on('click', ()=> fileInput.trigger('click'));
  fileInput.on('change', (e)=> handleFiles(e.target.files));
  removeBtn.on('click', ()=> { fileInput.val(''); fileInfo.hide(); uploadBtn.prop('disabled', true); });
  function handleFiles(files){ if(!files || !files.length) return; const f = files[0]; fileName.text(f.name); fileSize.text((f.size/1024/1024).toFixed(2)+' MB'); fileInfo.show(); uploadBtn.prop('disabled', false); }

  // Document details modal and actions
  const modal = $('#document-details-modal'); const closeBtn = $('.sensay-modal-close');
  $(document).on('click','.view-document', function(){
    const id = $(this).data('doc-id');
    $('#doc-filename,#doc-type,#doc-status,#doc-created').text('Loading...');
    $('#doc-content').text('Loading...');
    modal.show();
    $.get(ajaxurl, {action:'sensay_get_document_details', document_id:id}, function(resp){
      if(resp && resp.success){
        const d = resp.data;
        $('#doc-filename').text(d.filename);
        $('#doc-type').text(d.type);
        $('#doc-status').text(d.status);
        $('#doc-created').text(d.created_at);
        let c = d.raw_text || '';
        c = c
          .replace(/^##\s+(.*$)/gm,'<h3>$1</h3>')
          .replace(/^###\s+(.*$)/gm,'<h4>$1</h4>')
          .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
          .replace(/\*(.*?)\*/g,'<em>$1</em>')
          .replace(/\n\n/g,'</p><p>')
          .replace(/\n/g,'<br>')
          .replace(/^\s*[-*]\s+(.*$)/gm,'<li>$1</li>')
          .replace(/(<li>.*<\/li>)/gs,'<ul>$1</ul>')
          .replace(/<\/ul>\s*<ul>/g,'');
        if (!c.startsWith('<h') && !c.startsWith('<p>') && !c.startsWith('<ul>')) c = '<p>'+c+'</p>';
        $('#doc-content').html(c);
      } else {
        $('#doc-content').text('Error: '+(resp && resp.data ? resp.data : 'Failed to load'));
      }
    });
  });
  closeBtn.on('click', ()=> modal.hide());
  $(window).on('click', (e)=> { if ($(e.target).is(modal)) modal.hide(); });

  $(document).on('click','.delete-document', function(e){
    e.preventDefault();
    const $btn = $(this), id = $btn.data('doc-id'), title = $btn.data('title')||'this document', $row = $btn.closest('tr');
    if (!id) return alert('Invalid document id.');
    if (!confirm('Delete "'+title+'"? <?php echo esc_js(__('This cannot be undone.','wp-fluxa-ecommerce-assistant')); ?>')) return;
    $btn.prop('disabled', true).text('<?php echo esc_js(__('Deleting...','wp-fluxa-ecommerce-assistant')); ?>');
    $.post(ajaxurl, {action:'sensay_delete_document', document_id:id, _ajax_nonce:'<?php echo wp_create_nonce("sensay_training_nonce"); ?>'}, function(resp){
      if (resp && resp.success) {
        $row.fadeOut(200, ()=>{$row.remove();});
      } else {
        alert((resp && resp.error) ? resp.error : '<?php echo esc_js(__('Delete failed.','wp-fluxa-ecommerce-assistant')); ?>');
        $btn.prop('disabled', false).text('<?php echo esc_js(__('Delete','wp-fluxa-ecommerce-assistant')); ?>');
      }
    });
  });
});
</script>
