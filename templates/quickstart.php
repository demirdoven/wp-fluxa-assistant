<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get current step
$current_step = isset($_GET['step']) ? absint($_GET['step']) : 1;
$total_steps = 3; // Total number of steps in the quickstart

// Handle form submission
if (isset($_POST['fluxa_quickstart_nonce']) && wp_verify_nonce($_POST['fluxa_quickstart_nonce'], 'fluxa_quickstart_save')) {
    if ($current_step === 1) {
        // Save API key
        if (isset($_POST['api_key'])) {
            update_option('fluxa_api_key', sanitize_text_field($_POST['api_key']));
        }
    } elseif ($current_step === 2) {
        // Save conversation types
        $conversation_types = array(
            'order_status' => isset($_POST['order_status']) ? 1 : 0,
            'order_tracking' => isset($_POST['order_tracking']) ? 1 : 0,
            'cart_abandoned' => isset($_POST['cart_abandoned']) ? 1 : 0,
        );
        update_option('fluxa_conversation_types', $conversation_types);
    } elseif ($current_step === 3) {
        // Save design settings
        $design_settings = array(
            'chatbot_name' => isset($_POST['chatbot_name']) ? sanitize_text_field($_POST['chatbot_name']) : 'Chat Assistant',
            'alignment' => isset($_POST['alignment']) ? sanitize_text_field($_POST['alignment']) : 'right',
            'gap_from_bottom' => isset($_POST['gap_from_bottom']) ? absint($_POST['gap_from_bottom']) : 20,
        );
        update_option('fluxa_design_settings', $design_settings);
        
        // Mark quickstart as completed
        update_option('fluxa_quickstart_completed', '1');
        update_option('fluxa_show_quickstart', '0');
        
        // Redirect to main dashboard
        wp_redirect(admin_url('admin.php?page=fluxa-assistant&quickstart=completed'));
        exit;
    }
    
    // Go to next step
    wp_redirect(admin_url('admin.php?page=fluxa-quickstart&step=' . ($current_step + 1)));
    exit;
}
?>
<style>
    div#setting-error-tgmpa {
        display: none;
    }
</style>
<div class="wrap fluxa-quickstart-wrap">
    <div class="fluxa-quickstart-header">
        <h2><?php _e('Fluxa eCommerce Assistant Setup', 'fluxa-ecommerce-assistant'); ?></h2>
        <div class="fluxa-quickstart-steps">
            <?php for ($i = 1; $i <= $total_steps; $i++) : ?>
                <div class="fluxa-step <?php echo $i === $current_step ? 'active' : ($i < $current_step ? 'completed' : ''); ?>">
                    <div class="step-number"><span><?php echo $i; ?></span></div>
                    <div class="step-label">
                        <?php 
                        switch ($i) {
                            case 1:
                                _e('API Key', 'fluxa-ecommerce-assistant');
                                break;
                            case 2:
                                _e('Features', 'fluxa-ecommerce-assistant');
                                break;
                            case 3:
                                _e('Design', 'fluxa-ecommerce-assistant');
                                break;
                        }
                        ?>
                    </div>
                </div>
                <?php if ($i < $total_steps) : ?>
                    <div class="step-separator"></div>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>
    
    <div class="fluxa-quickstart-content">
        <form method="post" action="" class="fluxa-quickstart-form">
            <?php wp_nonce_field('fluxa_quickstart_save', 'fluxa_quickstart_nonce'); ?>
            
            <?php if ($current_step === 1) : ?>
                <div class="fluxa-quickstart-step">
                    <h2><?php _e('Enter Your API Key', 'fluxa-ecommerce-assistant'); ?></h2>
                    <p><?php _e('To get started, please enter your Fluxa API key. You can find this in your Fluxa account dashboard.', 'fluxa-ecommerce-assistant'); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="api_key"><?php _e('API Key', 'fluxa-ecommerce-assistant'); ?></label>
                            </th>
                            <td>
                                <?php $qs_api_val = get_option('fluxa_api_key', '');
                                      if (empty($qs_api_val)) {
                                          $qs_api_val = '8fa5d504c1ebe6f17436c72dd602d3017a4fe390eb5963e38a1999675c9c7ad3';
                                      }
                                ?>
                                <input type="text" id="api_key" name="api_key" class="regular-text" value="<?php echo esc_attr($qs_api_val); ?>" autocomplete="off">
                                <p class="description">
                                    <?php _e('Don\'t have an API key?', 'fluxa-ecommerce-assistant'); ?> 
                                    <a href="https://fluxa.io/account/api-keys" target="_blank">
                                        <?php _e('Get your API key', 'fluxa-ecommerce-assistant'); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
            <?php elseif ($current_step === 2) : ?>
                <div class="fluxa-quickstart-step">
                    <h2><?php _e('Enable Features', 'fluxa-ecommerce-assistant'); ?></h2>
                    <p><?php _e('Select which features you want to enable for your chatbot.', 'fluxa-ecommerce-assistant'); ?></p>
                    
                    <?php 
                    $conversation_types = get_option('fluxa_conversation_types', array(
                        'order_status' => 1,
                        'order_tracking' => 1,
                        'cart_abandoned' => 1
                    ));
                    ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Order Status', 'fluxa-ecommerce-assistant'); ?></th>
                            <td>
                                <label class="fluxa-switch">
                                    <input type="checkbox" name="order_status" value="1" <?php checked(1, $conversation_types['order_status']); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description"><?php _e('Allow customers to check their order status via the chatbot.', 'fluxa-ecommerce-assistant'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Order Tracking', 'fluxa-ecommerce-assistant'); ?></th>
                            <td>
                                <label class="fluxa-switch">
                                    <input type="checkbox" name="order_tracking" value="1" <?php checked(1, $conversation_types['order_tracking']); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description"><?php _e('Enable order tracking functionality in the chatbot.', 'fluxa-ecommerce-assistant'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Cart Abandonment', 'fluxa-ecommerce-assistant'); ?></th>
                            <td>
                                <label class="fluxa-switch">
                                    <input type="checkbox" name="cart_abandoned" value="1" <?php checked(1, $conversation_types['cart_abandoned']); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description"><?php _e('Enable cart abandonment recovery through the chatbot.', 'fluxa-ecommerce-assistant'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
            <?php elseif ($current_step === 3) : ?>
                <div class="fluxa-quickstart-step">
                    <h2><?php _e('Customize Appearance', 'fluxa-ecommerce-assistant'); ?></h2>
                    <p><?php _e('Customize how your chatbot will appear on your website.', 'fluxa-ecommerce-assistant'); ?></p>
                    
                    <?php 
                    $design_settings = get_option('fluxa_design_settings', array(
                        'chatbot_name' => 'Chat Assistant',
                        'alignment' => 'right',
                        'gap_from_bottom' => 20
                    ));
                    ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="chatbot_name"><?php _e('Chatbot Name', 'fluxa-ecommerce-assistant'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="chatbot_name" name="chatbot_name" class="regular-text" value="<?php echo esc_attr($design_settings['chatbot_name']); ?>">
                                <p class="description"><?php _e('The name that will be displayed in the chat widget.', 'fluxa-ecommerce-assistant'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="alignment"><?php _e('Chat Widget Position', 'fluxa-ecommerce-assistant'); ?></label>
                            </th>
                            <td>
                                <select id="alignment" name="alignment" class="regular-text">
                                    <option value="left" <?php selected('left', $design_settings['alignment']); ?>><?php _e('Bottom Left', 'fluxa-ecommerce-assistant'); ?></option>
                                    <option value="right" <?php selected('right', $design_settings['alignment']); ?>><?php _e('Bottom Right', 'fluxa-ecommerce-assistant'); ?></option>
                                </select>
                                <p class="description"><?php _e('Choose which side of the screen the chat widget will appear on.', 'fluxa-ecommerce-assistant'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gap_from_bottom"><?php _e('Distance from Bottom', 'fluxa-ecommerce-assistant'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="gap_from_bottom" name="gap_from_bottom" class="small-text" min="0" max="200" value="<?php echo esc_attr($design_settings['gap_from_bottom']); ?>">
                                <span>px</span>
                                <p class="description"><?php _e('Distance from the bottom of the screen in pixels.', 'fluxa-ecommerce-assistant'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <div class="fluxa-preview">
                        <h3><?php _e('Preview', 'fluxa-ecommerce-assistant'); ?></h3>
                        <div class="fluxa-preview-window">
                            <div class="fluxa-preview-chat" style="<?php echo $design_settings['alignment'] === 'right' ? 'right: 20px;' : 'left: 20px;'; ?> bottom: <?php echo intval($design_settings['gap_from_bottom']) + 60; ?>px;">
                                <div class="fluxa-preview-chat-header">
                                    <?php echo esc_html($design_settings['chatbot_name']); ?>
                                </div>
                                <div class="fluxa-preview-chat-body">
                                    <div class="fluxa-preview-message fluxa-preview-message-bot">
                                        <?php _e('Hello! How can I help you today?', 'fluxa-ecommerce-assistant'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="fluxa-preview-launcher" style="<?php echo $design_settings['alignment'] === 'right' ? 'right: 20px;' : 'left: 20px;'; ?> bottom: <?php echo intval($design_settings['gap_from_bottom']); ?>px;">
                                <span class="dashicons dashicons-format-chat"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="fluxa-quickstart-actions">
                <?php if ($current_step > 1) : ?>
                    <a href="<?php echo admin_url('admin.php?page=fluxa-quickstart&step=' . ($current_step - 1)); ?>" class="button">
                        <?php _e('Previous', 'fluxa-ecommerce-assistant'); ?>
                    </a>
                <?php endif; ?>
                
                <button type="submit" class="button button-primary">
                    <?php echo $current_step === $total_steps ? __('Finish Setup', 'fluxa-ecommerce-assistant') : __('Next', 'fluxa-ecommerce-assistant'); ?>
                </button>
                
                <?php /* Skipping steps is disabled to make setup mandatory */ ?>
            </div>
        </form>
    </div>
</div>
