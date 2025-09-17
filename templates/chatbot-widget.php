<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get design settings
$design_settings = get_option('fluxa_design_settings', array(
    'chatbot_name' => 'Chat Assistant',
    'alignment' => 'right',
    'gap_from_bottom' => 20,
    'gap_from_side' => 20,
    'logo_url' => '',
    'minimized_icon_url' => '',
    'theme' => 'light',
    'primary_color' => '#4F46E5',
    'background_color' => '#FFFFFF',
    'text_color' => '#000000',
));

// Determine widget position class
$position_class = 'fluxa-chat-widget--' . esc_attr($design_settings['alignment']);
$gap_from_side = isset($design_settings['gap_from_side']) ? intval($design_settings['gap_from_side']) : 20;
$gap_from_bottom = isset($design_settings['gap_from_bottom']) ? intval($design_settings['gap_from_bottom']) : 20;
$side_prop = ($design_settings['alignment'] === 'left') ? 'left' : 'right';

// Resolve theme presets
$theme = isset($design_settings['theme']) ? strtolower($design_settings['theme']) : 'light';
if ($theme === 'dark') {
    // Align with admin preview palette
    $resolved_primary = '#4F46E5';
    $resolved_bg = '#111827';
    $resolved_text = '#FFFFFF';
} elseif ($theme === 'custom') {
    $resolved_primary = !empty($design_settings['primary_color']) ? $design_settings['primary_color'] : '#4F46E5';
    $resolved_bg = !empty($design_settings['background_color']) ? $design_settings['background_color'] : '#FFFFFF';
    // Fallback to light text for unknown custom text color
    $resolved_text = !empty($design_settings['text_color']) ? $design_settings['text_color'] : '#111827';
} else { // light
    $resolved_primary = !empty($design_settings['primary_color']) ? $design_settings['primary_color'] : '#4F46E5';
    $resolved_bg = '#FFFFFF';
    $resolved_text = '#111827'; // gray-900
}

// Load dynamic suggested questions from settings and sanitize
$suggestions_enabled = (int) get_option('fluxa_suggestions_enabled', 1) === 1;
$suggested_questions = get_option('fluxa_suggested_questions', array());
if (!is_array($suggested_questions)) {
    $suggested_questions = array();
}
// Normalize: trim, strip tags, drop empties, and limit to a reasonable number
$suggested_questions = array_values(array_filter(array_map(function($q){
    $q = wp_strip_all_tags((string)$q);
    $q = trim($q);
    return $q !== '' ? $q : null;
}, $suggested_questions)));
// Limit to first 8 for UI neatness
$suggested_questions = array_slice($suggested_questions, 0, 8);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4/animate.min.css" />
<style>
  :root {
    --fluxa-primary: <?php echo esc_html($resolved_primary); ?>;
    --fluxa-bg: <?php echo esc_html($resolved_bg); ?>;
    --fluxa-text: <?php echo esc_html($resolved_text); ?>;
  }
</style>


<div class="fluxa-chat-container fluxa-chat-container--<?php echo esc_attr($design_settings['alignment']); ?> fluxa-theme--<?php echo esc_attr($theme); ?>" >


    <?php if ($suggestions_enabled && !empty($suggested_questions)) : ?>
        <!-- Suggested questions: visible only when widget is minimized -->
        <div class="fluxa-chat-suggestions is-hidden" style="<?php echo $side_prop; ?>: <?php echo $gap_from_side; ?>px;">
            <div class="fluxa-chat-suggestions__header">
                <div class="fluxa-chat-suggestions__title"><?php echo esc_html($design_settings['chatbot_name']); ?></div>
                <button type="button" class="fluxa-suggestions__close" aria-label="<?php echo esc_attr($design_settings['chatbot_name']); ?>">
                <img alt="close"
     width="16" height="16"
     src="data:image/svg+xml;utf8,
     <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'>
       <path fill='%23fff' fill-rule='evenodd' clip-rule='evenodd'
        d='M5.29289 5.29289C5.68342 4.90237 6.31658 4.90237 6.70711 5.29289L12 10.5858L17.2929 5.29289C17.6834 4.90237 18.3166 4.90237 18.7071 5.29289C19.0976 5.68342 19.0976 6.31658 18.7071 6.70711L13.4142 12L18.7071 17.2929C19.0976 17.6834 19.0976 18.3166 18.7071 18.7071C18.3166 19.0976 17.6834 19.0976 17.2929 18.7071L12 13.4142L6.70711 18.7071C6.31658 19.0976 5.68342 19.0976 5.29289 18.7071C4.90237 18.3166 4.90237 17.6834 5.29289 17.2929L10.5858 12L5.29289 6.70711C4.90237 6.31658 4.90237 5.68342 5.29289 5.29289Z'/>
     </svg>">
                </button>
            </div>
            <div class="fluxa-chat-suggestions__body">
                <?php foreach ($suggested_questions as $q) : ?>
                    <button type="button" class="fluxa-suggestion"><?php echo esc_html($q); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div id="fluxa-chat-widget" class="fluxa-chat-widget fluxa-chat-widget--instance fluxa-chat-widget--minimized <?php echo $position_class; ?>" style="bottom: <?php echo $gap_from_bottom; ?>px; <?php echo $side_prop; ?>: <?php echo $gap_from_side; ?>px;">
        <div class="fluxa-chat-widget__header">
            <?php if (!empty($design_settings['logo_url'])) : ?>
                <div class="fluxa-chat-widget__logo">
                    <img src="<?php echo esc_url($design_settings['logo_url']); ?>" alt="<?php echo esc_attr($design_settings['chatbot_name']); ?>">
                </div>
            <?php endif; ?>
            <div class="fluxa-chat-widget__title">
                <?php echo esc_html($design_settings['chatbot_name']); ?>
            </div>
            <button type="button" class="fluxa-chat-widget__close" aria-label="<?php esc_attr_e('Minimize chat', 'fluxa-ecommerce-assistant'); ?>">
            <img alt="chevron"
                width="22" height="22"
                src="data:image/svg+xml;utf8,
                <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'>
                <path fill='%23ffffff' fill-rule='evenodd' clip-rule='evenodd'
                    d='M4.29289 8.29289C4.68342 7.90237 5.31658 7.90237 5.70711 8.29289L12 14.5858L18.2929 8.29289C18.6834 7.90237 19.3166 7.90237 19.7071 8.29289C20.0976 8.68342 20.0976 9.31658 19.7071 9.70711L12.7071 16.7071C12.3166 17.0976 11.6834 17.0976 11.2929 16.7071L4.29289 9.70711C3.90237 9.31658 3.90237 8.68342 4.29289 8.29289Z'/>
                </svg>">
            </button>
        </div>
        
        <div class="fluxa-chat-widget__body">
            <div class="fluxa-chat-widget__messages">
                <div class="fluxa-chat-message fluxa-chat-message--bot">
                    <div class="fluxa-chat-message__content">
                        <?php
                        $greet_opt = get_option('fluxa_greeting_text', '');
                        if (!is_string($greet_opt)) { $greet_opt = ''; }
                        $greet_opt = trim(wp_strip_all_tags($greet_opt));
                        if ($greet_opt === '') {
                            $sitename = get_bloginfo('name');
                            $greet_opt = sprintf(
                                /* translators: %s: Site name */
                                __("Hello! I'm %s customer assistant. How can I help you today?", 'fluxa-ecommerce-assistant'),
                                $sitename
                            );
                        }
                        echo esc_html($greet_opt);
                        ?>
                    </div>
                    <div class="fluxa-chat-message__time">
                        <?php echo current_time(get_option('time_format')); ?>
                    </div>
                </div>
            </div>
            
            <div class="fluxa-chat-widget__typing-indicator">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <form class="fluxa-chat-widget__form">
                <div class="fluxa-chat-widget__input-wrapper">
                    <input 
                        type="text" 
                        class="fluxa-chat-widget__input" 
                        placeholder="<?php esc_attr_e('Type your message...', 'fluxa-ecommerce-assistant'); ?>"
                        autocomplete="off"
                    >
                    <button type="submit" class="fluxa-chat-widget__send">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24">
                            <circle class="bg" cx="12" cy="12" r="10"/>
                            <path class="arrow" d="M12 16 V8 M12 8 L9.5 10.5 M12 8 L14.5 10.5"/>
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="fluxa-chat-widget__launch" style="bottom: <?php echo $gap_from_bottom; ?>px; <?php echo $side_prop; ?>: <?php echo $gap_from_side; ?>px;">
        <div class="fluxa-chat-widget__launch-icon">
            <?php if (!empty($design_settings['minimized_icon_url'])) : ?>
                <img src="<?php echo esc_url($design_settings['minimized_icon_url']); ?>" alt="<?php echo esc_attr($design_settings['chatbot_name']); ?>">
            <?php else : ?>
                <span class="dashicons dashicons-format-chat"></span>
            <?php endif; ?>
        </div>
        <div class="fluxa-chat-widget__launch-text">
            <?php esc_html_e('Chat with us', 'fluxa-ecommerce-assistant'); ?>
        </div>
    </div>

</div>
