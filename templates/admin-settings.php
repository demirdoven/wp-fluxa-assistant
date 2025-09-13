<?php
// Show any error/update messages
settings_errors('fluxa_messages');
?>

<div class="wrap fluxa-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('fluxa_save_settings'); ?>
        <?php wp_nonce_field('fluxa_save_settings', 'fluxa_settings_nonce', false); ?>
        
        <div class="fluxa-settings-layout">
          <div class="fluxa-settings-main">
            <div class="fluxa-tabs" role="tablist" aria-label="Fluxa Settings Tabs">
                <button type="button" class="fluxa-tab" role="tab" id="tab-general" aria-controls="panel-general" aria-selected="true"><?php _e('General', 'fluxa-ecommerce-assistant'); ?></button>
                <button type="button" class="fluxa-tab" role="tab" id="tab-conversation" aria-controls="panel-conversation" aria-selected="false"><?php _e('Conversation', 'fluxa-ecommerce-assistant'); ?></button>
                <button type="button" class="fluxa-tab" role="tab" id="tab-design" aria-controls="panel-design" aria-selected="false"><?php _e('Design', 'fluxa-ecommerce-assistant'); ?></button>
            </div>
            <div class="fluxa-tabpanels">

                <div id="panel-general" class="fluxa-tabpanel is-active" role="tabpanel" aria-labelledby="tab-general" tabindex="0">
                    <div class="fluxa-card">
                        <h2><?php _e('General Settings', 'fluxa-ecommerce-assistant'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="api_key"><?php _e('API Key', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <?php $api_val = !empty($settings['api_key']) ? $settings['api_key'] : '8fa5d504c1ebe6f17436c72dd602d3017a4fe390eb5963e38a1999675c9c7ad3'; ?>
                                    <input type="text" name="api_key" id="api_key" value="<?php echo esc_attr($api_val); ?>" class="regular-text" autocomplete="off">
                                    <p class="description"><?php _e('Enter your API key for the chatbot service.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
            <tr>
                <th scope="row">
                    <label for="animation"><?php _e('Animation', 'fluxa-ecommerce-assistant'); ?></label>
                </th>
                <td>
                    <?php $animation = isset($settings['design']['animation']) ? $settings['design']['animation'] : 'bounceIn'; ?>
                    <select name="animation" id="animation">
                        <option value="none" <?php selected($animation, 'none'); ?>><?php _e('None', 'fluxa-ecommerce-assistant'); ?></option>
                        <option value="bounceIn" <?php selected($animation, 'bounceIn'); ?>><?php _e('Bounce', 'fluxa-ecommerce-assistant'); ?></option>
                        <option value="bounceInUp" <?php selected($animation, 'bounceInUp'); ?>><?php _e('Bounce Up', 'fluxa-ecommerce-assistant'); ?></option>
                        <option value="backInUp" <?php selected($animation, 'backInUp'); ?>><?php _e('Back In Up', 'fluxa-ecommerce-assistant'); ?></option>
                        <option value="fadeInUp" <?php selected($animation, 'fadeInUp'); ?>><?php _e('Fade In Up', 'fluxa-ecommerce-assistant'); ?></option>
                        <option value="zoomIn" <?php selected($animation, 'zoomIn'); ?>><?php _e('Zoom In', 'fluxa-ecommerce-assistant'); ?></option>
                    </select>
                    <p class="description"><?php _e('Select the opening animation for the chatbox.', 'fluxa-ecommerce-assistant'); ?></p>
                </td>
            </tr>
                            <!-- Hidden fields to persist saved custom colors for reliable restore -->
                            <input type="hidden" id="fluxa_custom_primary_saved" value="<?php echo esc_attr(!empty($settings['design']['primary_color']) ? $settings['design']['primary_color'] : '#4F46E5'); ?>">
                            <input type="hidden" id="fluxa_custom_background_saved" value="<?php echo esc_attr(!empty($settings['design']['background_color']) ? $settings['design']['background_color'] : '#FFFFFF'); ?>">
                            <input type="hidden" id="fluxa_custom_text_saved" value="<?php echo esc_attr(!empty($settings['design']['text_color']) ? $settings['design']['text_color'] : '#000000'); ?>">
                            <tr>
                                <th scope="row">
                                    <label for="chatbot_name"><?php _e('Chatbot Name', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="chatbot_name" id="chatbot_name" class="regular-text" 
                                        value="<?php echo esc_attr($settings['design']['chatbot_name']); ?>" placeholder="<?php esc_attr_e('e.g. Shop Assistant', 'fluxa-ecommerce-assistant'); ?>">
                                    <p class="description"><?php _e('The assistant name shown in the chat header and messages.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Target users', 'fluxa-ecommerce-assistant'); ?></th>
                                <td>
                                    <?php $global_target = $settings['target_users'] ?? 'all'; ?>
                                    <select name="target_users" id="target_users">
                                        <option value="all" <?php selected($global_target, 'all'); ?>><?php _e('All users', 'fluxa-ecommerce-assistant'); ?></option>
                                        <option value="logged_in" <?php selected($global_target, 'logged_in'); ?>><?php _e('Logged-in users', 'fluxa-ecommerce-assistant'); ?></option>
                                        <option value="guests" <?php selected($global_target, 'guests'); ?>><?php _e('Guest users', 'fluxa-ecommerce-assistant'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Choose who will see the chatbot and related features by default. Specific features may have their own targeting.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ping_on_pageload"><?php _e('Ping conversation on page load', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <?php $ping_on_pageload = isset($settings['ping_on_pageload']) ? (int)$settings['ping_on_pageload'] : 1; ?>
                                    <label style="display:inline-flex; align-items:center; gap:8px;">
                                        <input type="checkbox" name="ping_on_pageload" id="ping_on_pageload" value="1" <?php checked($ping_on_pageload, 1); ?>>
                                        <span><?php _e('Keep conversation last seen and Woo session in sync on every page view (lightweight).', 'fluxa-ecommerce-assistant'); ?></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="minimized_icon_select"><?php _e('Minimized Icon', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <input type="hidden" name="minimized_icon_url" id="minimized_icon_url" value="<?php if (!empty($settings['design']['minimized_icon_url'])) echo esc_attr($settings['design']['minimized_icon_url']); ?>">
                                    <input type="hidden" name="remove_minimized_icon" id="remove_minimized_icon" value="0">
                                    <?php if (!empty($settings['design']['minimized_icon_url'])) : ?>
                                        <div class="minicon-preview" style="margin-top:10px;">
                                            <img src="<?php echo esc_url($settings['design']['minimized_icon_url']); ?>" style="max-height:50px; max-width:200px; border-radius:3px; box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                                        </div>
                                    <?php endif; ?>
                                    <div class="minicon-actions" style="margin-top:10px; display:flex; gap:8px; align-items:center;">
                                        <button type="button" class="button button-secondary" id="minimized_icon_select"><?php echo empty($settings['design']['minimized_icon_url']) ? esc_html__('Select Icon', 'fluxa-ecommerce-assistant') : esc_html__('Change Icon', 'fluxa-ecommerce-assistant'); ?></button>
                                        <button type="button" class="button button-link-delete" id="minimized_icon_remove" style="<?php echo empty($settings['design']['minimized_icon_url']) ? 'display:none;' : ''; ?>"><?php _e('Remove Icon', 'fluxa-ecommerce-assistant'); ?></button>
                                    </div>
                                    <p class="description" style="margin-top:6px;">
                                        <?php _e('Icon used for the minimized launcher. Recommended size: 48x48px (square).', 'fluxa-ecommerce-assistant'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="chatbot_logo"><?php _e('Chatbot Logo', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <input type="hidden" name="logo_url" id="logo_url" value="<?php if (!empty($settings['design']['logo_url'])) echo esc_attr($settings['design']['logo_url']); ?>">
                                    <input type="hidden" name="remove_logo" id="remove_logo" value="0">
                                    <?php if (!empty($settings['design']['logo_url'])) : ?>
                                        <div class="logo-preview" style="margin-top:10px;">
                                            <img src="<?php echo esc_url($settings['design']['logo_url']); ?>" style="max-height:50px; max-width:200px; border-radius:3px; box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                                        </div>
                                    <?php endif; ?>
                                    <div class="logo-actions" style="margin-top:10px; display:flex; gap:8px; align-items:center;">
                                        <button type="button" class="button button-secondary" id="logo_select"><?php echo empty($settings['design']['logo_url']) ? esc_html__('Select Logo', 'fluxa-ecommerce-assistant') : esc_html__('Change Logo', 'fluxa-ecommerce-assistant'); ?></button>
                                        <button type="button" class="button button-link-delete" id="logo_remove" style="<?php echo empty($settings['design']['logo_url']) ? 'display:none;' : ''; ?>"><?php _e('Remove Logo', 'fluxa-ecommerce-assistant'); ?></button>
                                    </div>
                                    <p class="description" style="margin-top:6px;">
                                        <?php _e('Use the media library to select an image. Recommended size: 200x50px.', 'fluxa-ecommerce-assistant'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div><!-- /panel-general -->

                <div id="panel-conversation" class="fluxa-tabpanel" role="tabpanel" aria-labelledby="tab-conversation" tabindex="0">
                    <div class="fluxa-card">
                        <h2><?php _e('Conversation Settings', 'fluxa-ecommerce-assistant'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="greeting"><?php _e('Greeting', 'fluxa-ecommerce-assistant'); ?></label></th>
                                <td>
                                    <input type="text" name="greeting" id="greeting" class="regular-text" value="<?php echo esc_attr($settings['greeting']); ?>" placeholder="<?php esc_attr_e('e.g. Hi! How can I help you today?', 'fluxa-ecommerce-assistant'); ?>">
                                    <p class="description"><?php _e('This message will be shown first by the chatbot (stored in settings).', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                          
                            <!-- Order status -->
                            <tr>
                                <th scope="row"><?php _e('Order Status Inquiries', 'fluxa-ecommerce-assistant'); ?></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox" name="conversation_order_status" value="1" <?php checked(!empty($settings['conversation_types']['order_status'])); ?>>
                                        </label>
                                    </fieldset>
                                    <p class="description" style="margin-top:6px;">
                                      <?php _e('Enable this to allow the assistant to answer customer questions about current order status from your WooCommerce store.', 'fluxa-ecommerce-assistant'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Order tracking -->
                            <tr>
                                <th scope="row"><?php _e('Order Tracking Information', 'fluxa-ecommerce-assistant'); ?></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox" name="conversation_order_tracking" value="1" <?php checked(!empty($settings['conversation_types']['order_tracking'])); ?>>
                                        </label>
                                    </fieldset>
                                    <p class="description" style="margin-top:6px;">
                                      <?php _e('Enable this to let the assistant fetch and display order tracking details using your selected provider.', 'fluxa-ecommerce-assistant'); ?>
                                    </p>
                                    <?php 
                                      $tracking_provider = isset($settings['tracking_provider']) ? $settings['tracking_provider'] : '';
                                      $tracking_custom_meta = isset($settings['tracking_custom_meta']) ? $settings['tracking_custom_meta'] : '';
                                      $order_tracking_enabled = !empty($settings['conversation_types']['order_tracking']);
                                    ?>
                                    <div id="fluxa-tracking-settings" style="margin-top:30px; <?php echo $order_tracking_enabled ? '' : 'display:none;'; ?>">
                                      <label style="font-weight:600; display:block; margin-bottom:6px;">
                                        <?php _e('Select your tracking provider', 'fluxa-ecommerce-assistant'); ?>
                                      </label>
                                      <fieldset>
                                        <label style="display:block; margin-bottom:4px;">
                                          <input type="radio" name="tracking_provider" value="woocommerce_shipment_tracking" <?php checked($tracking_provider, 'woocommerce_shipment_tracking'); ?>>
                                          <?php _e('WooCommerce Shipment Tracking', 'fluxa-ecommerce-assistant'); ?>
                                          <?php echo is_plugin_active('woocommerce-shipment-tracking/woocommerce-shipment-tracking.php') 
                                          || class_exists('WC_Shipment_Tracking') ? ' <span style="background: #4CAF50; color: #fff; padding: 3px 7px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: bold; line-height: 1;">detected</span>' : ' <span style="background: #F44336; color: #fff; padding: 3px 7px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: bold; line-height: 1;">not detected</span>'; ?>
                                        </label>
                                        <label style="display:block; margin-bottom:4px;">
                                          <input type="radio" name="tracking_provider" value="aftership" <?php checked($tracking_provider, 'aftership'); ?>>
                                          <?php _e('AfterShip', 'fluxa-ecommerce-assistant'); ?>
                                          <?php echo is_plugin_active('aftership-woocommerce-tracking/aftership-woocommerce-tracking.php') 
                                          || class_exists('AfterShip') ? ' <span style="background: #4CAF50; color: #fff; padding: 3px 7px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: bold; line-height: 1;">detected</span>' : ' <span style="background: #F44336; color: #fff; padding: 3px 7px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: bold; line-height: 1;">not detected</span>'; ?>
                                        </label>
                                        <label style="display:block; margin-bottom:4px;">
                                          <input type="radio" name="tracking_provider" value="ast" <?php checked($tracking_provider, 'ast'); ?>>
                                          <?php _e('Advanced Shipment Tracking (AST)', 'fluxa-ecommerce-assistant'); ?>
                                          <?php echo is_plugin_active('woo-advanced-shipment-tracking/woocommerce-advanced-shipment-tracking.php') 
                                          || class_exists('WC_Advanced_Shipment_Tracking') ? ' <span style="background: #4CAF50; color: #fff; padding: 3px 7px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: bold; line-height: 1;">detected</span>' : ' <span style="background: #F44336; color: #fff; padding: 3px 7px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: bold; line-height: 1;">not detected</span>'; ?>
                                        </label>
                                        <label style="display:block; margin-bottom:4px;">
                                          <input type="radio" name="tracking_provider" value="custom" <?php checked($tracking_provider, 'custom'); ?>>
                                          <?php _e('Custom', 'fluxa-ecommerce-assistant'); ?>
                                        </label>
                                      </fieldset>
                                      <div id="fluxa-tracking-custom" style="margin-top:8px; <?php echo ($tracking_provider === 'custom') ? '' : 'display:none;'; ?>">
                                        <label for="tracking_custom_meta" style="display:block; margin-bottom:4px; "><?php _e('Order meta key for tracking data', 'fluxa-ecommerce-assistant'); ?></label>
                                        <input type="text" name="tracking_custom_meta" id="tracking_custom_meta" class="regular-text" value="<?php echo esc_attr($tracking_custom_meta); ?>" placeholder="_tracking_number">
                                        <p class="description"><?php _e('Enter the order meta field key where tracking number or data is stored.', 'fluxa-ecommerce-assistant'); ?></p>
                                      </div>
                                    </div>
                                </td>
                            </tr>
                            <!-- Cart abandonment -->
                            <?php 
                              $ca = $settings['cart_abandonment'] ?? array();
                              $ca_enabled = !empty($settings['conversation_types']['cart_abandoned']) || !empty($ca['enabled']);
                              $ca = wp_parse_args($ca, array(
                                'general' => array('min_total'=>0,'min_count'=>0,'target_users'=>'all'),
                                'trigger' => array('idle_minutes'=>0,'exit_intent'=>0,'stock_discount'=>0),
                                'message' => array('system_prompt'=>'','customer_template'=>'','strategies'=>array('mention_shipping_threshold'=>0,'suggest_alternatives'=>0,'suggest_bundle'=>0)),
                                'coupon'  => array('mode'=>'disabled','cap_percent'=>0,'valid_hours'=>0,'cond_min_total'=>0,'cond_new_customer'=>0),
                                'channel' => array('onsite'=>1,'email'=>0,'push'=>0,'whatsapp'=>0),
                                'frequency'=> array('max_per_day'=>1,'max_per_week'=>2,'suppress_hours'=>12,'stop_after_order'=>1,'stop_after_empty'=>1),
                                'segmentation'=> array('new_customers'=>1,'returning'=>1,'high_value'=>0,'high_value_threshold'=>0),
                                'ab_test' => 'none',
                                'privacy' => array('consent_required'=>0,'anonymize_guests'=>1),
                              ));
                            ?>
                            <tr>
                                <th scope="row"><?php _e('Cart Abandonment Advice', 'fluxa-ecommerce-assistant'); ?></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox" name="conversation_cart_abandoned" value="1" <?php checked($ca_enabled); ?>>
                                        </label>
                                    </fieldset>
                                    <p class="description" style="margin-top:6px;">
                                      <?php _e('When enabled, the assistant detects potential cart abandonment and gently prompts customers with helpful, persuasive messages to recover sales across your selected channels.', 'fluxa-ecommerce-assistant'); ?>
                                    </p>
                                    <div id="fluxa-ca-settings" style="margin-top:12px; <?php echo $ca_enabled ? '' : 'display:none;'; ?>">
                                      <div id="fluxa-ca-vtabs" class="fluxa-vtabs">
                                        <nav class="fluxa-vtabs__nav" role="tablist" aria-label="<?php esc_attr_e('Cart Abandonment Sections', 'fluxa-ecommerce-assistant'); ?>">
                                          <button type="button" id="vt-ca-general" class="fluxa-vtab is-active" role="tab" aria-controls="vp-ca-general" aria-selected="true"><?php _e('General', 'fluxa-ecommerce-assistant'); ?></button>
                                          <button type="button" id="vt-ca-trigger" class="fluxa-vtab" role="tab" aria-controls="vp-ca-trigger" aria-selected="false"><?php _e('Triggers', 'fluxa-ecommerce-assistant'); ?></button>
                                          <button type="button" id="vt-ca-message" class="fluxa-vtab" role="tab" aria-controls="vp-ca-message" aria-selected="false"><?php _e('Message', 'fluxa-ecommerce-assistant'); ?></button>
                                          <button type="button" id="vt-ca-coupon" class="fluxa-vtab" role="tab" aria-controls="vp-ca-coupon" aria-selected="false"><?php _e('Coupon', 'fluxa-ecommerce-assistant'); ?></button>
                                          <button type="button" id="vt-ca-channel" class="fluxa-vtab" role="tab" aria-controls="vp-ca-channel" aria-selected="false"><?php _e('Channels', 'fluxa-ecommerce-assistant'); ?></button>
                                          <button type="button" id="vt-ca-frequency" class="fluxa-vtab" role="tab" aria-controls="vp-ca-frequency" aria-selected="false"><?php _e('Frequency', 'fluxa-ecommerce-assistant'); ?></button>
                                          <button type="button" id="vt-ca-segmentation" class="fluxa-vtab" role="tab" aria-controls="vp-ca-segmentation" aria-selected="false"><?php _e('Segmentation', 'fluxa-ecommerce-assistant'); ?></button>
                                          <button type="button" id="vt-ca-privacy" class="fluxa-vtab" role="tab" aria-controls="vp-ca-privacy" aria-selected="false"><?php _e('Privacy', 'fluxa-ecommerce-assistant'); ?></button>
                                        </nav>
                                        <div class="fluxa-vtabs__panels">
                                          <section id="vp-ca-general" class="fluxa-vpanel is-active" role="tabpanel" aria-labelledby="vt-ca-general" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('Minimum cart total', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" step="0.01" min="0" class="small-text" name="ca_min_total" value="<?php echo esc_attr($ca['general']['min_total']); ?>"> <span><?php _e('currency units', 'fluxa-ecommerce-assistant'); ?></span></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Minimum product count', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" min="0" class="small-text" name="ca_min_count" value="<?php echo esc_attr($ca['general']['min_count']); ?>"></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Target users', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td>
                                                  <select name="ca_target_users">
                                                    <option value="all" <?php selected($ca['general']['target_users'],'all'); ?>><?php _e('All', 'fluxa-ecommerce-assistant'); ?></option>
                                                    <option value="logged_in" <?php selected($ca['general']['target_users'],'logged_in'); ?>><?php _e('Logged-in', 'fluxa-ecommerce-assistant'); ?></option>
                                                    <option value="guests" <?php selected($ca['general']['target_users'],'guests'); ?>><?php _e('Guests', 'fluxa-ecommerce-assistant'); ?></option>
                                                  </select>
                                                </td>
                                              </tr>
                                            </table>
                                          </section>

                                          <section id="vp-ca-trigger" class="fluxa-vpanel" role="tabpanel" aria-labelledby="vt-ca-trigger" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('Idle time before trigger (minutes)', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" min="0" class="small-text" name="ca_idle_minutes" value="<?php echo esc_attr($ca['trigger']['idle_minutes']); ?>"></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Exit intent detection', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_exit_intent" value="1" <?php checked(!empty($ca['trigger']['exit_intent'])); ?>></label></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Stock/discount signal', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_stock_discount" value="1" <?php checked(!empty($ca['trigger']['stock_discount'])); ?>></label></td>
                                              </tr>
                                            </table>
                                          </section>

                                          <section id="vp-ca-message" class="fluxa-vpanel" role="tabpanel" aria-labelledby="vt-ca-message" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('System prompt (hidden, internal)', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><textarea name="ca_system_prompt" class="large-text" rows="3" placeholder="<?php echo esc_attr__("User has abandoned cart. Generate a short, polite, persuasive suggestion.", 'fluxa-ecommerce-assistant'); ?>"><?php echo esc_textarea($ca['message']['system_prompt']); ?></textarea></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Customer-facing message template', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td>
                                                  <textarea name="ca_customer_template" class="large-text" rows="3" placeholder="<?php echo esc_attr__('Hi {{first_name}}, you left {{top_items}} in your cart. Complete your order and enjoy {{coupon_code}}!', 'fluxa-ecommerce-assistant'); ?>"><?php echo esc_textarea($ca['message']['customer_template']); ?></textarea>
                                                  <p class="description"><?php _e('Dynamic variables: {{first_name}}, {{cart_total}}, {{top_items}}, {{alternatives}}, {{coupon_code}}, {{shipping_threshold}}', 'fluxa-ecommerce-assistant'); ?></p>
                                                </td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Persuasion strategies', 'fluxa-ecommerce-assistant'); ?></th>
                                              <td>
                                                <div class="fluxa-checklist">
                                                  <label style="display: flex ; align-items: center; gap: 5px;">
                                                    <input type="checkbox" name="ca_strat_shipping_threshold" value="1" <?php checked(!empty($ca['message']['strategies']['mention_shipping_threshold'])); ?>> 
                                                    <span style="width: calc(100% - 50px);"><?php _e('Mention remaining amount for free shipping', 'fluxa-ecommerce-assistant'); ?></span>
                                                  </label>
                                                  <label style="display: flex ; align-items: center; gap: 5px;">
                                                    <input type="checkbox" name="ca_strat_alternatives" value="1" <?php checked(!empty($ca['message']['strategies']['suggest_alternatives'])); ?>> 
                                                    <span style="width: calc(100% - 50px); margin-top: -5px;"><?php _e('Suggest alternative products', 'fluxa-ecommerce-assistant'); ?></span>
                                                  </label>
                                                  <label style="display: flex ; align-items: center; gap: 5px;">
                                                    <input type="checkbox" name="ca_strat_bundle" value="1" <?php checked(!empty($ca['message']['strategies']['suggest_bundle'])); ?>> 
                                                    <span style="width: calc(100% - 50px); margin-top: -5px;"><?php _e('Suggest bundle / multi-buy discount', 'fluxa-ecommerce-assistant'); ?></span>
                                                  </label>
                                                </div>
                                              </td>
                                              </tr>
                                            </table>
                                          </section>

                                          <section id="vp-ca-coupon" class="fluxa-vpanel" role="tabpanel" aria-labelledby="vt-ca-coupon" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('Coupon mode', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td>
                                                  <label><input type="radio" name="ca_coupon_mode" value="disabled" <?php checked(($ca['coupon']['mode'] ?? 'disabled'),'disabled'); ?>> <?php _e('Disabled','fluxa-ecommerce-assistant'); ?></label>
                                                  <label style="margin-left:10px;"><input type="radio" name="ca_coupon_mode" value="fixed" <?php checked(($ca['coupon']['mode'] ?? 'disabled'),'fixed'); ?>> <?php _e('Fixed code','fluxa-ecommerce-assistant'); ?></label>
                                                  <label style="margin-left:10px;"><input type="radio" name="ca_coupon_mode" value="auto" <?php checked(($ca['coupon']['mode'] ?? 'disabled'),'auto'); ?>> <?php _e('Auto-generated single-use','fluxa-ecommerce-assistant'); ?></label>
                                                </td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Discount cap (%)', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" step="0.1" min="0" max="100" class="small-text" name="ca_discount_cap" value="<?php echo esc_attr($ca['coupon']['cap_percent']); ?>"></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Coupon validity (hours)', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" min="0" class="small-text" name="ca_coupon_valid_hours" value="<?php echo esc_attr($ca['coupon']['valid_hours']); ?>"></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Conditions', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td>
                                                  <label style="display:block;"><?php _e('Min cart total', 'fluxa-ecommerce-assistant'); ?> <input type="number" step="0.01" min="0" class="small-text" name="ca_cond_min_total" value="<?php echo esc_attr($ca['coupon']['cond_min_total']); ?>"></label>
                                                  <label style="display:block; margin-top: 20px;"><input type="checkbox" name="ca_cond_new_customer" value="1" <?php checked(!empty($ca['coupon']['cond_new_customer'])); ?>> 
                                                  <span style="margin-top: -5px;"><?php _e('New customer only', 'fluxa-ecommerce-assistant'); ?></span>
                                                </label>
                                                </td>
                                              </tr>
                                            </table>
                                          </section>

                                          <section id="vp-ca-channel" class="fluxa-vpanel" role="tabpanel" aria-labelledby="vt-ca-channel" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('On-site Chat', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_channel_onsite" value="1" <?php checked(!empty($ca['channel']['onsite'])); ?>> </label></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Email', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_channel_email" value="1" <?php checked(!empty($ca['channel']['email'])); ?>></label></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Push notification', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_channel_push" value="1" <?php checked(!empty($ca['channel']['push'])); ?>></label></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('WhatsApp/Telegram', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_channel_whatsapp" value="1" <?php checked(!empty($ca['channel']['whatsapp'])); ?>></label></td>
                                              </tr>
                                            </table>
                                          </section>

                                          <section id="vp-ca-frequency" class="fluxa-vpanel" role="tabpanel" aria-labelledby="vt-ca-frequency" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('Max prompts per user (per day)', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" min="0" class="small-text" name="ca_max_per_day" value="<?php echo esc_attr($ca['frequency']['max_per_day']); ?>"></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Max prompts per user (per week)', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" min="0" class="small-text" name="ca_max_per_week" value="<?php echo esc_attr($ca['frequency']['max_per_week']); ?>"></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Suppress repeat for same cart (hours)', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><input type="number" min="0" class="small-text" name="ca_suppress_hours" value="<?php echo esc_attr($ca['frequency']['suppress_hours']); ?>"></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e("Don't show again if", 'fluxa-ecommerce-assistant'); ?></th>
                                                <td>
                                                  <label style="display:block; margin-bottom: 20px;"><input type="checkbox" name="ca_stop_after_order" value="1" <?php checked(!empty($ca['frequency']['stop_after_order'])); ?>> <?php _e('Order placed', 'fluxa-ecommerce-assistant'); ?></label>
                                                  <label style="display:block;"><input type="checkbox" name="ca_stop_after_empty" value="1" <?php checked(!empty($ca['frequency']['stop_after_empty'])); ?>> <?php _e('Cart emptied', 'fluxa-ecommerce-assistant'); ?></label>
                                                </td>
                                              </tr>
                                            </table>
                                          </section>

                                          <section id="vp-ca-segmentation" class="fluxa-vpanel" role="tabpanel" aria-labelledby="vt-ca-segmentation" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('Target segments', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td>
                                                  <label style="display:block;"><input type="checkbox" name="ca_seg_new" value="1" <?php checked(!empty($ca['segmentation']['new_customers'])); ?>> <?php _e('New customers', 'fluxa-ecommerce-assistant'); ?></label>
                                                  <label style="display:block;"><input type="checkbox" name="ca_seg_returning" value="1" <?php checked(!empty($ca['segmentation']['returning'])); ?>> <?php _e('Returning customers', 'fluxa-ecommerce-assistant'); ?></label>
                                                  <label style="display:block;"><input type="checkbox" name="ca_seg_high_value" value="1" <?php checked(!empty($ca['segmentation']['high_value'])); ?>> <?php _e('High-value carts (> X)', 'fluxa-ecommerce-assistant'); ?></label>
                                                  <div style="margin-top:6px;">
                                                    <label><?php _e('High-value threshold', 'fluxa-ecommerce-assistant'); ?> <input type="number" step="0.01" min="0" class="small-text" name="ca_high_value_threshold" value="<?php echo esc_attr($ca['segmentation']['high_value_threshold']); ?>"></label>
                                                  </div>
                                                </td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('A/B Testing', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td>
                                                  <select name="ca_ab_test">
                                                    <option value="none" <?php // selected(($ca['ab_test'] ?? 'none'),'none'); ?>><?php _e('None', 'fluxa-ecommerce-assistant'); ?></option>
                                                    <option value="template_a_b" <?php // selected(($ca['ab_test'] ?? 'none'),'template_a_b'); ?>><?php _e('Message template A vs B', 'fluxa-ecommerce-assistant'); ?></option>
                                                    <option value="coupon_vs_no" selected <?php // selected(($ca['ab_test'] ?? 'none'),'coupon_vs_no'); ?>><?php _e('With vs without coupon', 'fluxa-ecommerce-assistant'); ?></option>
                                                  </select>
                                                </td>
                                              </tr>
                                            </table>
                                          </section>

                                          <section id="vp-ca-privacy" class="fluxa-vpanel" role="tabpanel" aria-labelledby="vt-ca-privacy" tabindex="0">
                                            <table class="form-table">
                                              <tr>
                                                <th scope="row"><?php _e('GDPR/CCPA compliance', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_consent_required" value="1" <?php checked(!empty($ca['privacy']['consent_required'])); ?>> <?php _e('Require explicit consent', 'fluxa-ecommerce-assistant'); ?></label></td>
                                              </tr>
                                              <tr>
                                                <th scope="row"><?php _e('Anonymization', 'fluxa-ecommerce-assistant'); ?></th>
                                                <td><label><input type="checkbox" name="ca_anonymize_guests" value="1" <?php checked(!empty($ca['privacy']['anonymize_guests'])); ?>> <?php _e('Hide personal data for guest users', 'fluxa-ecommerce-assistant'); ?></label></td>
                                              </tr>
                                            </table>
                                          </section>
                                        </div>
                                      </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Suggested Questions', 'fluxa-ecommerce-assistant'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="suggestions_enabled" value="1" <?php checked(!empty($settings['suggestions_enabled'])); ?>>
                                    </label>
                                    <p class="description"><?php _e('When enabled, a list of quick-suggested questions appears next to the minimized chat widget.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"></th>
                                <td>
                                    <div id="fluxa-suggested-questions" style="<?php echo !empty($settings['suggestions_enabled']) ? '' : 'display:none;'; ?>">
                                        <?php
                                        $suggested = !empty($settings['suggested_questions']) && is_array($settings['suggested_questions'])
                                            ? $settings['suggested_questions']
                                            : array('');
                                        foreach ($suggested as $q) :
                                        ?>
                                        <div class="sq-item" style="margin-bottom:8px; display:flex; gap:8px; align-items:center;">
                                            <input type="text" name="suggested_questions[]" value="<?php echo esc_attr($q); ?>" class="regular-text" style="flex:1;" placeholder="<?php esc_attr_e('e.g. What is your return policy?', 'fluxa-ecommerce-assistant'); ?>">
                                            <button type="button" class="button button-link-delete sq-remove" aria-label="<?php esc_attr_e('Remove', 'fluxa-ecommerce-assistant'); ?>">&times;</button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p style="<?php echo !empty($settings['suggestions_enabled']) ? '' : 'display:none;'; ?>"><button type="button" class="button button-secondary" id="sq-add-new"><?php _e('Add new', 'fluxa-ecommerce-assistant'); ?></button></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div><!-- /panel-conversation -->
        
                <div id="panel-design" class="fluxa-tabpanel" role="tabpanel" aria-labelledby="tab-design" tabindex="0"
                 data-custom-primary="<?php echo esc_attr(!empty($settings['design']['primary_color']) ? $settings['design']['primary_color'] : '#4F46E5'); ?>"
                 data-custom-background="<?php echo esc_attr(!empty($settings['design']['background_color']) ? $settings['design']['background_color'] : '#FFFFFF'); ?>"
                 data-custom-text="<?php echo esc_attr(!empty($settings['design']['text_color']) ? $settings['design']['text_color'] : '#000000'); ?>">
                    <div class="fluxa-card">
                        <h2><?php _e('Design Settings', 'fluxa-ecommerce-assistant'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Theme', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <?php $theme = $settings['design']['theme'] ?? 'light'; ?>
                                    <div class="fluxa-segmented" role="tablist" aria-label="<?php esc_attr_e('Theme', 'fluxa-ecommerce-assistant'); ?>">
                                        <label class="fluxa-segment" role="tab" aria-selected="<?php echo $theme==='light' ? 'true' : 'false'; ?>">
                                            <input type="radio" name="theme" value="light" <?php checked($theme, 'light'); ?>>
                                            <span><?php _e('Light', 'fluxa-ecommerce-assistant'); ?></span>
                                        </label>
                                        <label class="fluxa-segment" role="tab" aria-selected="<?php echo $theme==='dark' ? 'true' : 'false'; ?>">
                                            <input type="radio" name="theme" value="dark" <?php checked($theme, 'dark'); ?>>
                                            <span><?php _e('Dark', 'fluxa-ecommerce-assistant'); ?></span>
                                        </label>
                                        <label class="fluxa-segment" role="tab" aria-selected="<?php echo $theme==='custom' ? 'true' : 'false'; ?>">
                                            <input type="radio" name="theme" value="custom" <?php checked($theme, 'custom'); ?>>
                                            <span><?php _e('Custom', 'fluxa-ecommerce-assistant'); ?></span>
                                        </label>
                                    </div>
                                    <p class="description"><?php _e('Choose Light or Dark presets, or set custom colors below.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="animation"><?php _e('Animation', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <?php $animation = isset($settings['design']['animation']) ? $settings['design']['animation'] : 'bounceIn'; ?>
                                    <select name="animation" id="animation">
                                        <option value="none" <?php selected($animation, 'none'); ?>><?php _e('None', 'fluxa-ecommerce-assistant'); ?></option>
                                        <optgroup label="Bounce / Back">
                                          <option value="bounceIn" <?php selected($animation, 'bounceIn'); ?>><?php _e('Bounce In', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="bounceInUp" <?php selected($animation, 'bounceInUp'); ?>><?php _e('Bounce In Up', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="bounceInLeft" <?php selected($animation, 'bounceInLeft'); ?>><?php _e('Bounce In Left', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="bounceInRight" <?php selected($animation, 'bounceInRight'); ?>><?php _e('Bounce In Right', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="backInUp" <?php selected($animation, 'backInUp'); ?>><?php _e('Back In Up', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="backInLeft" <?php selected($animation, 'backInLeft'); ?>><?php _e('Back In Left', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="backInRight" <?php selected($animation, 'backInRight'); ?>><?php _e('Back In Right', 'fluxa-ecommerce-assistant'); ?></option>
                                        </optgroup>
                                        <optgroup label="Fade">
                                          <option value="fadeInUp" <?php selected($animation, 'fadeInUp'); ?>><?php _e('Fade In Up', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="fadeInUpBig" <?php selected($animation, 'fadeInUpBig'); ?>><?php _e('Fade In Up Big', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="fadeInLeft" <?php selected($animation, 'fadeInLeft'); ?>><?php _e('Fade In Left', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="fadeInLeftBig" <?php selected($animation, 'fadeInLeftBig'); ?>><?php _e('Fade In Left Big', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="fadeInRight" <?php selected($animation, 'fadeInRight'); ?>><?php _e('Fade In Right', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="fadeInRightBig" <?php selected($animation, 'fadeInRightBig'); ?>><?php _e('Fade In Right Big', 'fluxa-ecommerce-assistant'); ?></option>
                                        </optgroup>
                                        <optgroup label="Flip">
                                          <option value="flipInX" <?php selected($animation, 'flipInX'); ?>><?php _e('Flip In X', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="flipInY" <?php selected($animation, 'flipInY'); ?>><?php _e('Flip In Y', 'fluxa-ecommerce-assistant'); ?></option>
                                        </optgroup>
                                        <optgroup label="Light Speed">
                                          <option value="lightSpeedInLeft" <?php selected($animation, 'lightSpeedInLeft'); ?>><?php _e('Light Speed In Left', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="lightSpeedInRight" <?php selected($animation, 'lightSpeedInRight'); ?>><?php _e('Light Speed In Right', 'fluxa-ecommerce-assistant'); ?></option>
                                        </optgroup>
                                        <optgroup label="Special">
                                          <option value="jackInTheBox" <?php selected($animation, 'jackInTheBox'); ?>><?php _e('Jack In The Box', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="rollIn" <?php selected($animation, 'rollIn'); ?>><?php _e('Roll In', 'fluxa-ecommerce-assistant'); ?></option>
                                        </optgroup>
                                        <optgroup label="Zoom">
                                          <option value="zoomIn" <?php selected($animation, 'zoomIn'); ?>><?php _e('Zoom In', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="zoomInDown" <?php selected($animation, 'zoomInDown'); ?>><?php _e('Zoom In Down', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="zoomInLeft" <?php selected($animation, 'zoomInLeft'); ?>><?php _e('Zoom In Left', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="zoomInRight" <?php selected($animation, 'zoomInRight'); ?>><?php _e('Zoom In Right', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="zoomInUp" <?php selected($animation, 'zoomInUp'); ?>><?php _e('Zoom In Up', 'fluxa-ecommerce-assistant'); ?></option>
                                        </optgroup>
                                        <optgroup label="Slide">
                                          <option value="slideInDown" <?php selected($animation, 'slideInDown'); ?>><?php _e('Slide In Down', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="slideInLeft" <?php selected($animation, 'slideInLeft'); ?>><?php _e('Slide In Left', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="slideInRight" <?php selected($animation, 'slideInRight'); ?>><?php _e('Slide In Right', 'fluxa-ecommerce-assistant'); ?></option>
                                          <option value="slideInUp" <?php selected($animation, 'slideInUp'); ?>><?php _e('Slide In Up', 'fluxa-ecommerce-assistant'); ?></option>
                                        </optgroup>
                                    </select>
                                    <p class="description"><?php _e('Select the opening animation for the chatbox.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="auto_open_on_reply"><?php _e('Open on bot reply', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <?php $auto_open = !empty($settings['design']['auto_open_on_reply']); ?>
                                    <label style="display:flex; align-items:center; gap:10px;">
                                        <input type="checkbox" id="auto_open_on_reply" name="auto_open_on_reply" value="1" <?php checked($auto_open, true); ?> class="fluxa-switch-native">
                                        <span><?php _e('Automatically open the chatbox when the assistant sends a reply.', 'fluxa-ecommerce-assistant'); ?></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pulse_on_new"><?php _e('Pulse launcher on new message', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <?php $pulse_on_new = !empty($settings['design']['pulse_on_new']); ?>
                                    <label style="display:flex; align-items:center; gap:10px;">
                                        <input type="checkbox" id="pulse_on_new" name="pulse_on_new" value="1" <?php checked($pulse_on_new, true); ?> class="fluxa-switch-native">
                                        <span><?php _e('Show a subtle pulse/glow on the launcher when a new reply arrives and the chat is minimized.', 'fluxa-ecommerce-assistant'); ?></span>
                                    </label>
                                    <p class="description"><?php _e('Respects reduced motion preferences automatically.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="alignment"><?php _e('Alignment', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <select name="alignment" id="alignment">
                                        <option value="left" <?php selected($settings['design']['alignment'], 'left'); ?>>
                                            <?php _e('Left Bottom', 'fluxa-ecommerce-assistant'); ?>
                                        </option>
                                        <option value="right" <?php selected($settings['design']['alignment'], 'right'); ?>>
                                            <?php _e('Right Bottom', 'fluxa-ecommerce-assistant'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="gap_from_bottom"><?php _e('Gap from Bottom', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="gap_from_bottom" id="gap_from_bottom" 
                           value="<?php echo esc_attr($settings['design']['gap_from_bottom']); ?>" 
                           min="0" class="small-text">
                                    <span>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="gap_from_side"><?php _e('Gap from Side', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="gap_from_side" id="gap_from_side"
                           value="<?php echo esc_attr(isset($settings['design']['gap_from_side']) ? $settings['design']['gap_from_side'] : 20); ?>"
                           min="0" class="small-text">
                                    <span>px</span>
                                    <p class="description"><?php _e('Distance from the left or right edge depending on Alignment.', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                                <?php
                                // Determine default-color attributes for WP Color Picker so it initializes with saved values
                                $dc_primary = '#4F46E5';
                                $dc_bg = '#FFFFFF';
                                $dc_text = '#000000';
                                if ($theme === 'dark') {
                                    $dc_primary = '#4F46E5';
                                    $dc_bg = '#111827';
                                    $dc_text = '#FFFFFF';
                                } elseif ($theme === 'custom') {
                                    $dc_primary = !empty($settings['design']['primary_color']) ? $settings['design']['primary_color'] : '#4F46E5';
                                    $dc_bg = !empty($settings['design']['background_color']) ? $settings['design']['background_color'] : '#FFFFFF';
                                    $dc_text = !empty($settings['design']['text_color']) ? $settings['design']['text_color'] : '#000000';
                                }
                                ?>
                            <tr>
                                <th scope="row">
                                    <label for="primary_color"><?php _e('Primary color', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <div class="fluxa-color-field <?php echo ($theme !== 'custom') ? 'is-locked' : ''; ?>">
                                        <input type="text" name="primary_color" id="primary_color" class="color-picker" data-default-color="<?php echo esc_attr($dc_primary); ?>"
                               value="<?php echo esc_attr(!empty($settings['design']['primary_color']) ? $settings['design']['primary_color'] : '#4F46E5'); ?>">
                                    </div>
                                    <p class="description"><?php _e('Main color for the chatbox UI (launcher, header, accents).', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="background_color"><?php _e('Background color', 'fluxa-ecommerce-assistant'); ?></label>
                                </th>
                                <td>
                                    <div class="fluxa-color-field <?php echo ($theme !== 'custom') ? 'is-locked' : ''; ?>">
                                        <input type="text" name="background_color" id="background_color" class="color-picker" data-default-color="<?php echo esc_attr($dc_bg); ?>"
                               value="<?php echo esc_attr(!empty($settings['design']['background_color']) ? $settings['design']['background_color'] : '#FFFFFF'); ?>">
                                    </div>
                                    <p class="description"><?php _e('Background color for the chatbox UI (launcher, header, accents).', 'fluxa-ecommerce-assistant'); ?></p>
                                </td>
                            </tr>
                            
                        </table>
                    </div>
                </div><!-- /panel-design -->
            </div><!-- /.fluxa-tabpanels -->
          </div><!-- /.fluxa-settings-main -->

          <aside class="fluxa-settings-side" aria-label="Live Preview">
            <div class="fluxa-card">
            <h2><?php _e('Live Preview', 'fluxa-ecommerce-assistant'); ?></h2>
            <p class="description" style="margin-top:-6px;">
                <?php _e('This is an approximate preview of the chat widget based on the current settings. It updates as you change values.', 'fluxa-ecommerce-assistant'); ?>
            </p>
            <div class="fluxa-preview-viewport" style="position:relative; min-height:600px; background:#f7f7f7; border:1px solid #e3e3e3; border-radius:8px; overflow:hidden;">
            <style>
              /* Scope preview to viewport so it does not pin to browser window */
              .fluxa-preview-viewport .fluxa-chat-container { position: absolute !important; }
              .fluxa-preview-viewport .fluxa-chat-widget { z-index: 10; }
              .fluxa-preview-viewport .fluxa-chat-suggestions { z-index: 9; }
              .fluxa-preview-viewport .fluxa-chat-widget__launch { z-index: 11; }

              /* Preview-specific overrides to mirror container flow and spacing */
              .fluxa-preview-viewport .fluxa-chat-container {
                display: flex;
                flex-direction: column;
                gap: 10px;
                align-items: flex-end;
              }
              .fluxa-preview-viewport .fluxa-chat-container.fluxa-chat-container--left { align-items: flex-start; }
              .fluxa-preview-viewport .fluxa-chat-widget { order: 1; }
              .fluxa-preview-viewport .fluxa-chat-widget__launch { order: 2; margin: 0 !important; }
              /* Ensure chat widget does not carry extra margins in preview */
              .fluxa-preview-viewport .fluxa-chat-widget--instance { margin-bottom: 0 !important; }
              /* Launcher should be in-flow at the bottom of the container in preview */
              .fluxa-preview-viewport .fluxa-chat-widget__launch { position: relative !important; inset: auto !important; }
              /* Do not show suggestions in admin preview */
              .fluxa-preview-viewport .fluxa-chat-suggestions { display: none !important; }
              /* Keep widget a bit smaller inside preview */
              .fluxa-preview-viewport #fluxa-preview-widget { width: 260px !important; height: 450px !important; max-height: 450px !important; }
              .fluxa-preview-viewport .fluxa-chat-widget__launch { width: 60px; height: 60px; }
              .fluxa-preview-viewport .fluxa-chat-widget__launch img { width: 60px; height: 60px; }
            </style>

            <?php
              $design = $settings['design'];
              $align = $design['alignment'] ?? 'right';
              $side_prop = ($align === 'left') ? 'left' : 'right';
              $gap_bottom = isset($design['gap_from_bottom']) ? (int)$design['gap_from_bottom'] : 20;
              $gap_side = isset($design['gap_from_side']) ? (int)$design['gap_from_side'] : 20;
              // Container-based preview: position is applied to the container only
              // Children are relative and flow in order: widget, suggestions (optional), launcher
              $container_bottom = (int)$gap_bottom;
              $pos_class = 'fluxa-chat-widget--' . esc_attr($align);
              $theme = $design['theme'] ?? 'light';
              // Resolve preview colors (mirror enforced palettes)
              if ($theme === 'dark') {
                $resolved_primary = '#4F46E5';
                $resolved_bg = '#111827';
                $resolved_text = '#FFFFFF';
              } elseif ($theme === 'custom') {
                $resolved_primary = !empty($design['primary_color']) ? $design['primary_color'] : '#4F46E5';
                $resolved_bg = !empty($design['background_color']) ? $design['background_color'] : '#FFFFFF';
                $resolved_text = !empty($design['text_color']) ? $design['text_color'] : '#000000';
              } else { // light
                $resolved_primary = '#4F46E5';
                $resolved_bg = '#FFFFFF';
                $resolved_text = '#000000';
              }
            ?>
            <style>
              .fluxa-preview-viewport { --fluxa-primary: <?php echo esc_html($resolved_primary); ?>; --fluxa-bg: <?php echo esc_html($resolved_bg); ?>; --fluxa-text: <?php echo esc_html($resolved_text); ?>; }
            </style>

            <div class="fluxa-chat-container fluxa-chat-container--<?php echo esc_attr($align); ?> fluxa-theme--<?php echo esc_attr(strtolower($theme)); ?>" style="bottom: <?php echo (int)$container_bottom; ?>px; <?php echo esc_attr($side_prop); ?>: <?php echo (int)$gap_side; ?>px;">

            <?php 
              // Do not render suggestions in admin preview
              $render_suggestions = false;
              $sugg_enabled = !empty($settings['suggestions_enabled']);
              $sugg = get_option('fluxa_suggested_questions', array());
              if ($render_suggestions && $sugg_enabled && !empty($sugg)) : ?>
              <div class="fluxa-chat-suggestions" id="fluxa-preview-suggestions">
                  <div class="fluxa-chat-suggestions__header">
                      <div class="fluxa-chat-suggestions__title"><?php esc_html_e('Chat with our support', 'fluxa-ecommerce-assistant'); ?></div>
                      <button type="button" class="fluxa-suggestions__close" aria-label="Close">×</button>
                  </div>
                  <div class="fluxa-chat-suggestions__body" id="fluxa-preview-sugg-body">
                      <?php foreach ($sugg as $q) : ?>
                          <button type="button" class="fluxa-suggestion"><?php echo esc_html($q); ?></button>
                      <?php endforeach; ?>
                  </div>
              </div>
            <?php endif; ?>

            <div id="fluxa-preview-widget" class="fluxa-chat-widget <?php echo esc_attr($pos_class); ?>">
                <div class="fluxa-chat-widget__header">
                    <?php if (!empty($design['logo_url'])) : ?>
                        <div class="fluxa-chat-widget__logo">
                            <img id="fluxa-preview-logo" src="<?php echo esc_url($design['logo_url']); ?>" alt="<?php echo esc_attr($design['chatbot_name']); ?>">
                        </div>
                    <?php else: ?>
                        <div class="fluxa-chat-widget__logo" style="display:none;"></div>
                    <?php endif; ?>
                    <div class="fluxa-chat-widget__title" id="fluxa-preview-title"><?php echo esc_html($design['chatbot_name']); ?></div>
                    <button type="button" class="fluxa-chat-widget__close" aria-label="Close">
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
                            <?php
                              $greet_preview = isset($settings['greeting']) ? wp_strip_all_tags((string)$settings['greeting']) : '';
                              $greet_preview = trim($greet_preview);
                              if ($greet_preview === '') {
                                $greet_preview = sprintf(
                                  /* translators: %s: Site name */
                                  __("Hello! I'm %s customer assistant. How can I help you today?", 'fluxa-ecommerce-assistant'),
                                  get_bloginfo('name')
                                );
                              }
                            ?>
                            <div class="fluxa-chat-message__content" id="fluxa-preview-greeting"><?php echo esc_html($greet_preview); ?></div>
                            <div class="fluxa-chat-message__time">now</div>
                        </div>
                    </div>
                </div>
                <div class="fluxa-chat-widget__form" role="group">
                    <div class="fluxa-chat-widget__input-wrapper">
                        <input type="text" class="fluxa-chat-widget__input" placeholder="<?php echo esc_attr__('Type your message...', 'fluxa-ecommerce-assistant'); ?>" disabled>
                        <button type="button" class="fluxa-chat-widget__send" disabled>
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24">
                                <circle class="bg" cx="12" cy="12" r="10"></circle>
                                <path class="arrow" d="M12 16 V8 M12 8 L9.5 10.5 M12 8 L14.5 10.5"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="fluxa-chat-widget__launch" id="fluxa-preview-launch">
                <div class="fluxa-chat-widget__launch-icon">
                    <?php if (!empty($design['minimized_icon_url'])) : ?>
                        <img id="fluxa-preview-minicon" src="<?php echo esc_url($design['minimized_icon_url']); ?>" alt="<?php echo esc_attr($design['chatbot_name']); ?>">
                    <?php else : ?>
                        <span class="dashicons dashicons-format-chat"></span>
                    <?php endif; ?>
                </div>
            </div>
            </div>
            </div>
          </aside>
        </div><!-- /.fluxa-settings-layout -->

        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" 
                   value="<?php esc_attr_e('Save Changes', 'fluxa-ecommerce-assistant'); ?>">
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Tabs logic
    (function(){
      var storageKey = 'fluxa-settings-active-tab';
      var currentTab = null;
      function activateTab(tabId){
        $('.fluxa-tab').attr('aria-selected','false');
        $('.fluxa-tabpanel').removeClass('is-active');
        $('#tab-' + tabId).attr('aria-selected','true');
        $('#panel-' + tabId).addClass('is-active');
        try { localStorage.setItem(storageKey, tabId); } catch(e){}
        // Notify others about tab change with previous tab id
        try { jQuery(document).trigger('fluxaTabChanged', [tabId, currentTab]); } catch(e){}
        currentTab = tabId;
      }
      $('.fluxa-tab').on('click keydown', function(e){
        if (e.type === 'click' || (e.type === 'keydown' && (e.key === 'Enter' || e.key === ' '))) {
          e.preventDefault();
          var id = $(this).attr('id').replace('tab-','');
          activateTab(id);
        }
      });
      var initial = 'general';
      try { var saved = localStorage.getItem(storageKey); if (saved) initial = saved; } catch(e){}
      if (!$('#tab-' + initial).length) { initial = 'general'; }
      // If URL contains hash matching a tab, prefer it
      if (location.hash && $('#tab-' + location.hash.substring(1)).length) {
        initial = location.hash.substring(1);
      }
      activateTab(initial);
    })();

    // Theme and color pickers behavior: lock colors for Light/Dark, enable for Custom
    (function(){
      var $themeRadios = $('input[name="theme"]');
      var $primary = $('#primary_color');
      var $bg      = $('#background_color');
      var $text    = $('#text_color');
      var $savedP  = $('#fluxa_custom_primary_saved');
      var $savedB  = $('#fluxa_custom_background_saved');
      var $savedT  = $('#fluxa_custom_text_saved');
      var $panel   = $('#panel-design');
      var prevTheme = null;
      var customCache = { primary: null, background: null, text: null };

      var palettes = {
        light: { primary: '#4F46E5', background: '#FFFFFF', text: '#000000' },
        dark:  { primary: '#4F46E5', background: '#111827', text: '#FFFFFF' }
      };

      function setEnabled(enabled) {
        var disabled = !enabled;
        // Do NOT toggle the disabled prop; it can cause WP Color Picker to reset values.
        $primary.attr('aria-disabled', disabled);
        $bg.attr('aria-disabled', disabled);
        $text.attr('aria-disabled', disabled);
        var $pickers = $primary.add($bg).add($text).closest('.wp-picker-container');
        var $fields  = $primary.add($bg).add($text).closest('.fluxa-color-field');
        $pickers.toggleClass('is-locked', disabled);
        $fields.toggleClass('is-locked', disabled);
        $pickers.find('.wp-color-result').attr('aria-disabled', disabled).prop('tabindex', disabled ? -1 : 0);
      }

      function applyPalette(p) {
        if (!p) return;
        $primary.val(p.primary).trigger('change');
        $bg.val(p.background).trigger('change');
        $text.val(p.text).trigger('change');
        // If color pickers are WP pickers, update their UI
        setPickerColor($primary, p.primary);
        setPickerColor($bg, p.background);
        setPickerColor($text, p.text);
      }

      // Set only the UI swatch colors without changing the underlying input values
      function setPickerUIColor(p){
        if (!p) return;
        // IMPORTANT: Do NOT touch the color picker inputs or swatches here.
        // Only update the preview CSS variables so Custom inputs remain intact.
        var viewport = $('.fluxa-preview-viewport').get(0);
        if (viewport) {
          viewport.style.setProperty('--fluxa-primary', p.primary);
          viewport.style.setProperty('--fluxa-bg', p.background);
          viewport.style.setProperty('--fluxa-text', p.text);
        }
      }

      function syncPickersToInputs() {
        if (!$.fn.wpColorPicker) return;
        var p = $primary.val() || '#4F46E5';
        var b = $bg.val() || '#FFFFFF';
        var t = $text.val() || '#000000';
        setPickerColor($primary, p);
        setPickerColor($bg, b);
        setPickerColor($text, t);
      }

      // Visually override the swatch background without touching the input value
      function overrideSwatch($input, color) {
        var $container = $input.closest('.wp-picker-container');
        var $btn = $container.find('.wp-color-result');
        var $display = $btn.find('.color-display'); // modern WP uses inner element
        if ($display.length) {
          $display.attr('data-fluxa-override', '1').css('background-color', color);
        } else {
          $btn.attr('data-fluxa-override', '1').css('background-color', color);
        }
      }
      function clearSwatch($input) {
        var $container = $input.closest('.wp-picker-container');
        var $btn = $container.find('.wp-color-result');
        var $display = $btn.find('.color-display');
        if ($display.length) {
          if ($display.attr('data-fluxa-override') === '1') {
            $display.removeAttr('data-fluxa-override').css('background-color', '');
          }
        } else if ($btn.attr('data-fluxa-override') === '1') {
          $btn.removeAttr('data-fluxa-override').css('background-color', '');
        }
      }

      // Safely initialize a color picker if not already initialized
      function ensurePicker($input) {
        if (!$.fn.wpColorPicker || !$input || !$input.length) return false;
        // If the container is not present, initialize the picker
        if ($input.closest('.wp-picker-container').length === 0) {
          try { $input.wpColorPicker(); } catch(e) { return false; }
        }
        return true;
      }

      // Safely set color on a picker, initializing if necessary
      function setPickerColor($input, color) {
        if (!$input || !$input.length || !$.fn.wpColorPicker) return;
        // If not initialized, initialize then defer setting color
        var initialized = $input.closest('.wp-picker-container').length > 0;
        if (!initialized) {
          try { $input.wpColorPicker(); } catch(e) { return; }
          setTimeout(function(){
            try { $input.wpColorPicker('color', color); } catch(e) {}
          }, 0);
          return;
        }
        try { $input.wpColorPicker('color', color); } catch(e) {}
      }

      // Restore Custom values into inputs and picker UI reliably
      function restoreCustomPickers() {
        var pSaved = ($savedP.val() || '').trim() || ($panel.attr('data-custom-primary') || '').trim() || '#4F46E5';
        var bSaved = ($savedB.val() || '').trim() || ($panel.attr('data-custom-background') || '').trim() || '#FFFFFF';
        // text may not exist in UI anymore; keep for preview consistency
        var tSaved = ($savedT.val() || '').trim() || ($panel.attr('data-custom-text') || '').trim() || '#000000';
        // Ensure pickers are initialized before manipulating their UI
        ensurePicker($primary);
        ensurePicker($bg);
        // Update input values
        $primary.val(pSaved).trigger('change');
        $bg.val(bSaved).trigger('change');
        // Update data-default-color so WP picker doesn't revert to old default
        $primary.attr('data-default-color', pSaved);
        $bg.attr('data-default-color', bSaved);
        // Force the WP color picker UI to these colors
        setPickerColor($primary, pSaved);
        setPickerColor($bg, bSaved);
        if ($.fn.wpColorPicker) {
          try { $primary.wpColorPicker('color', pSaved); } catch(e) {}
          try { $bg.wpColorPicker('color', bSaved); } catch(e) {}
        }
        // Ensure swatches and internal text inputs match restored values
        clearSwatch($primary); clearSwatch($bg);
        overrideSwatch($primary, pSaved);
        overrideSwatch($bg, bSaved);
        var setInner = function(){
          var $pWrap = $primary.closest('.wp-picker-container');
          var $bWrap = $bg.closest('.wp-picker-container');
          $pWrap.find('.wp-picker-input-wrap input').val(pSaved);
          $bWrap.find('.wp-picker-input-wrap input').val(bSaved);
        };
        setInner();
        setTimeout(setInner, 50);
        // Update preview variables
        updatePreviewColors();
      }

      function updateThemeControls() {
        var theme = ($themeRadios.filter(':checked').val() || 'light').toLowerCase();
        // If we're leaving Custom, cache its values before they get overwritten
        if (prevTheme === 'custom' && theme !== 'custom') {
          customCache.primary   = $primary.val() || customCache.primary || '#4F46E5';
          customCache.background= $bg.val()      || customCache.background || '#FFFFFF';
          customCache.text      = $text.val()    || customCache.text || '#000000';
        }
        if (theme === 'custom') {
          setEnabled(true);
          // Always restore from hidden saved fields first to ensure correctness
          var $panel = $('#panel-design');
          var pSaved = ($savedP.val() || '').trim();
          var bSaved = ($savedB.val() || '').trim();
          var tSaved = ($savedT.val() || '').trim();
          if (!pSaved || !bSaved || !tSaved) {
            // Fallback to data attributes if hidden fields are missing
            pSaved = pSaved || ($panel.attr('data-custom-primary') || '').trim();
            bSaved = bSaved || ($panel.attr('data-custom-background') || '').trim();
            tSaved = tSaved || ($panel.attr('data-custom-text') || '').trim();
          }
          if (pSaved || bSaved || tSaved) {
            var p = pSaved || ($primary.val() || '#4F46E5');
            var b = bSaved || ($bg.val() || '#FFFFFF');
            var t = tSaved || ($text.val() || '#000000');
            $primary.val(p).trigger('change');
            $bg.val(b).trigger('change');
            $text.val(t).trigger('change');
            // Update defaults so picker UI doesn't snap back
            $primary.attr('data-default-color', p);
            $bg.attr('data-default-color', b);
            $text.attr('data-default-color', t);
            setPickerColor($primary, p);
            setPickerColor($bg, b);
            setPickerColor($text, t);
          } else {
            // Ensure UI reflects saved custom values
            syncPickersToInputs();
            clearSwatch($primary); clearSwatch($bg); clearSwatch($text);
          }
        } else if (theme === 'dark') {
          setEnabled(false);
          // Do not overwrite inputs; just show UI swatch and update preview
          setPickerUIColor(palettes.dark);
          // Visually override swatches to show palette
          overrideSwatch($primary, palettes.dark.primary);
          overrideSwatch($bg, palettes.dark.background);
          overrideSwatch($text, palettes.dark.text);
        } else { // light
          setEnabled(false);
          setPickerUIColor(palettes.light);
          overrideSwatch($primary, palettes.light.primary);
          overrideSwatch($bg, palettes.light.background);
          overrideSwatch($text, palettes.light.text);
        }
        prevTheme = theme;
        // Always refresh preview colors after switching theme
        updatePreviewColors();
      }

      $themeRadios.on('change', function(){
        updateThemeControls();
        if (($(this).filter(':checked').val() || '').toLowerCase() === 'custom') {
          // After switching to Custom, immediately restore saved custom values
          restoreCustomPickers();
        }
      });
      // Initialize on load
      updateThemeControls();
      // Also, after initial controls set, ensure custom theme UI is synced if active
      if (($themeRadios.filter(':checked').val() || '').toLowerCase() === 'custom') {
        syncPickersToInputs();
        // Initialize cache with current custom values
        customCache.primary    = $primary.val() || '#4F46E5';
        customCache.background = $bg.val()      || '#FFFFFF';
        customCache.text       = $text.val()    || '#000000';
        prevTheme = 'custom';
      }
      // WP may initialize color pickers after our inline script; sync again on window load
      $(window).on('load', function(){
        if (($themeRadios.filter(':checked').val() || '').toLowerCase() === 'custom') {
          syncPickersToInputs();
          setTimeout(syncPickersToInputs, 200); // final assurance after late inits
          // Ensure swatch backgrounds reflect current values in Custom
          var p = $primary.val() || '#4F46E5';
          var b = $bg.val() || '#FFFFFF';
          var t = $text.val() || '#000000';
          overrideSwatch($primary, p);
          overrideSwatch($bg, b);
          overrideSwatch($text, t);
        }
      });
      // When returning to Design tab, ensure custom colors re-sync to avoid default WP color showing
      $(document).on('fluxaTabChanged', function(e, tabId, prevTab){
        // If leaving Design while in Custom, cache current values tightly
        if (prevTab === 'design') {
          var themePrev = ($themeRadios.filter(':checked').val() || 'light').toLowerCase();
          if (themePrev === 'custom') {
            $primary.data('fluxa_cached', $primary.val());
            $bg.data('fluxa_cached', $bg.val());
            $text.data('fluxa_cached', $text.val());
          }
        }
        // When arriving to Design tab and theme is Custom, restore saved values into pickers
        if (tabId === 'design') {
          var themeNow = ($themeRadios.filter(':checked').val() || 'light').toLowerCase();
          if (themeNow === 'custom') {
            restoreCustomPickers();
          }
        }
        if (tabId === 'design') {
          var theme = ($themeRadios.filter(':checked').val() || 'light').toLowerCase();
          if (theme === 'custom') {
            clearSwatch($primary); clearSwatch($bg); clearSwatch($text);
            // Force-sync: set input values to themselves, update data-default, wpColorPicker UI, and internal text inputs
            var p = $primary.data('fluxa_cached') || $primary.val() || '#4F46E5';
            var b = $bg.data('fluxa_cached') || $bg.val() || '#FFFFFF';
            var t = $text.data('fluxa_cached') || $text.val() || '#000000';
            $primary.attr('data-default-color', p).val(p).trigger('change');
            $bg.attr('data-default-color', b).val(b).trigger('change');
            $text.attr('data-default-color', t).val(t).trigger('change');
            if ($.fn.wpColorPicker) {
              $primary.wpColorPicker('color', p);
              $bg.wpColorPicker('color', b);
              $text.wpColorPicker('color', t);
            }
            // Also ensure swatches show exactly these colors
            overrideSwatch($primary, p);
            overrideSwatch($bg, b);
            overrideSwatch($text, t);
            // Also reflect in internal input fields of the picker
            var setInner = function(){
              $primary.closest('.wp-picker-container').find('.wp-picker-input-wrap input').val(p);
              $bg.closest('.wp-picker-container').find('.wp-picker-input-wrap input').val(b);
              $text.closest('.wp-picker-container').find('.wp-picker-input-wrap input').val(t);
            };
            setInner();
            setTimeout(setInner, 50);
            setTimeout(function(){
              setPickerColor($primary, p);
              setPickerColor($bg, b);
              setPickerColor($text, t);
            }, 100);
          }
        }
      });
      // While in Custom, keep cache up-to-date with user edits
      $(document).on('input change', '#primary_color, #background_color, #text_color, .wp-picker-input-wrap input', function(){
        var theme = ($themeRadios.filter(':checked').val() || 'light').toLowerCase();
        if (theme !== 'custom') return;
        customCache.primary    = $primary.val() || customCache.primary || '#4F46E5';
        customCache.background = $bg.val()      || customCache.background || '#FFFFFF';
        customCache.text       = $text.val()    || customCache.text || '#000000';
        // Persist to panel data attributes so we can restore reliably when coming back
        if ($panel.length) {
          $panel.attr('data-custom-primary', customCache.primary);
          $panel.attr('data-custom-background', customCache.background);
          $panel.attr('data-custom-text', customCache.text);
        }
        // Also update hidden saved fields for this session so toggling themes/tabs restores latest edits
        if ($savedP.length) $savedP.val(customCache.primary);
        if ($savedB.length) $savedB.val(customCache.background);
        if ($savedT.length) $savedT.val(customCache.text);
      });
    })();
    // Suggested Questions repeater
    $('#sq-add-new').on('click', function(e){
        e.preventDefault();
        var $row = $('<div class="sq-item" style="margin-bottom:8px; display:flex; gap:8px; align-items:center;">\
            <input type="text" name="suggested_questions[]" value="" class="regular-text" style="flex:1;" placeholder="<?php echo esc_js(__('e.g. What are your shipping times?', 'fluxa-ecommerce-assistant')); ?>">\
            <button type="button" class="button button-link-delete sq-remove" aria-label="<?php echo esc_js(__('Remove', 'fluxa-ecommerce-assistant')); ?>">&times;</button>\
        </div>');
        $('#fluxa-suggested-questions').append($row);
    });
    $(document).on('click', '.sq-remove', function(e){
        e.preventDefault();
        $(this).closest('.sq-item').remove();
    });

    // Toggle suggested questions visibility
    $(document).on('change', 'input[name="suggestions_enabled"]', function(){
        var enabled = $(this).is(':checked');
        $('#fluxa-suggested-questions').toggle(enabled);
        $('#sq-add-new').closest('p').toggle(enabled);
        $('#sq-desc').toggle(enabled);
        // Preview: toggle suggestions panel
        $('#fluxa-preview-suggestions').toggleClass('is-hidden', !enabled);
    });

    // Live Preview sync
    function getSideProp() {
      return ($('#alignment').val() === 'left') ? 'left' : 'right';
    }
    function updatePreviewPosition() {
      var side = getSideProp();
      var rawSide = $('#gap_from_side').val();
      var rawBottom = $('#gap_from_bottom').val();
      var gapSide = parseInt(rawSide === '' ? 20 : rawSide, 10);
      var gapBottom = parseInt(rawBottom === '' ? 20 : rawBottom, 10);
      var $container = $('.fluxa-preview-viewport .fluxa-chat-container');
      var $widget = $('#fluxa-preview-widget');
      var $launch = $('#fluxa-preview-launch');
      var $sugg  = $('#fluxa-preview-suggestions');
      // Apply positioning ONLY to container
      if ($container.length) {
        var css = { left: '', right: '', bottom: gapBottom + 'px' };
        css[side] = gapSide + 'px';
        $container.attr('style', function(i, s){
          // Preserve other inline styles (like width/height on preview widget), but override position props
          s = s || '';
          // Remove previous left/right/bottom from container style text
          s = s.replace(/(left|right|bottom)\s*:\s*[^;]*;?/g, '').trim();
          var parts = [];
          if (s) parts.push(s);
          parts.push('bottom: ' + css.bottom);
          parts.push(side + ': ' + css[side]);
          return parts.join('; ');
        });
      }
      // Clear any child inline positioning to avoid conflicts
      $widget.css({ left: '', right: '', bottom: '' });
      // Live-update container alignment modifier class
      if ($container.length) {
        $container
          .removeClass('fluxa-chat-container--left fluxa-chat-container--right')
          .addClass('fluxa-chat-container--' + (side === 'left' ? 'left' : 'right'));
      }
      $launch.css({ left: '', right: '', bottom: '' });
      $sugg.css({ left: '', right: '', bottom: '' });
    }
    function updatePreviewColors() {
      var theme = ($('input[name="theme"]:checked').val() || 'light').toLowerCase();
      var primary = $('#primary_color').val() || '#4F46E5';
      var bg = $('#background_color').val() || '#FFFFFF';
      var text = $('#text_color').val() || '#000000';
      if (theme === 'light') { primary = '#4F46E5'; bg = '#FFFFFF'; text = '#000000'; }
      if (theme === 'dark')  { primary = '#4F46E5'; bg = '#111827'; text = '#FFFFFF'; }
      var viewport = $('.fluxa-preview-viewport').get(0);
      if (!viewport) return;
      viewport.style.setProperty('--fluxa-primary', primary);
      viewport.style.setProperty('--fluxa-bg', bg);
      viewport.style.setProperty('--fluxa-text', text);
    }
    function updatePreviewTitle() {
      $('#fluxa-preview-title').text($('#chatbot_name').val() || 'Chat Assistant');
    }
    function updatePreviewGreeting() {
      var raw = ($('#greeting').val() || '').toString().trim();
      if (!raw) {
        // Fallback to sitename-based default (mirrors PHP fallback)
        // var site = (document.title || '').split(' – ')[0] || (document.title || '').split(' - ')[0] || $('title').text() || 'Our store';
        var site = "<?php echo get_bloginfo('name'); ?>";
        raw = "Hello! I'm " + site + " customer assistant. How can I help you today?";
      }
      $('#fluxa-preview-greeting').text(raw);
    }
    function updatePreviewLogos() {
      var logo = $('#logo_url').val();
      var $logoWrap = $('#fluxa-preview-widget .fluxa-chat-widget__logo');
      var $img = $('#fluxa-preview-logo');
      if (logo) {
        if ($img.length === 0) {
          $logoWrap.show().html('<img id="fluxa-preview-logo" src="' + logo + '" alt="">');
        } else {
          $img.attr('src', logo);
          $logoWrap.show();
        }
      } else {
        $logoWrap.hide();
      }
      var minicon = $('#minimized_icon_url').val();
      var $minImg = $('#fluxa-preview-minicon');
      if (minicon) {
        if ($minImg.length) { $minImg.attr('src', minicon); }
        else {
          $('#fluxa-preview-launch .fluxa-chat-widget__launch-icon').html('<img id="fluxa-preview-minicon" src="' + minicon + '" alt="">');
        }
      } else {
        $('#fluxa-preview-launch .fluxa-chat-widget__launch-icon').html('<span class="dashicons dashicons-format-chat"></span>');
      }
    }
    function rebuildSuggestionsFromForm() {
      var enabled = $('input[name="suggestions_enabled"]').is(':checked');
      var $body = $('#fluxa-preview-sugg-body').empty();
      if (!enabled) return;
      var any = false;
      $('input[name="suggested_questions[]"]').each(function(){
        var v = ($(this).val() || '').trim();
        if (v) { $body.append('<button type="button" class="fluxa-suggestion"></button>'); $body.children().last().text(v); any = true; }
      });
      if (!any) {
        $body.append('<button type="button" class="fluxa-suggestion"><?php echo esc_js(__('What is your return policy?', 'fluxa-ecommerce-assistant')); ?></button>');
      }
    }
    function updateAll() {
      updatePreviewPosition();
      updatePreviewColors();
      updatePreviewTitle();
      updatePreviewLogos();
      updatePreviewGreeting();
      rebuildSuggestionsFromForm();
      // Initialize tracking UI state
      toggleTrackingSettings();
      toggleCartAbandonment();
    }
    // Bind events
    $('#alignment, #gap_from_side, #gap_from_bottom').on('input change', updatePreviewPosition);
    $('input[name="theme"]').on('change', updatePreviewColors);
    $('#primary_color, #background_color, #text_color').on('input change', updatePreviewColors);
    $('#greeting').on('input change', updatePreviewGreeting);
    // Tracking settings: show/hide when Order Tracking is enabled
    function toggleTrackingSettings(){
      var enabled = $('input[name="conversation_order_tracking"]').is(':checked');
      $('#fluxa-tracking-settings').toggle(!!enabled);
      // Also toggle custom field by current provider selection
      toggleCustomMeta();
    }
    function toggleCustomMeta(){
      var prov = ($('input[name="tracking_provider"]:checked').val() || '').toLowerCase();
      $('#fluxa-tracking-custom').toggle(prov === 'custom');
    }
    $(document).on('change', 'input[name="conversation_order_tracking"]', toggleTrackingSettings);
    $(document).on('change', 'input[name="tracking_provider"]', toggleCustomMeta);
    // Cart Abandonment visibility
    function toggleCartAbandonment(){
      var enabled = $('input[name="conversation_cart_abandoned"]').is(':checked');
      var $wrap = $('#fluxa-ca-settings');
      $wrap.toggle(!!enabled);
      if (enabled) {
        // Ensure a single active panel
        if ($wrap.find('.fluxa-subtabpanel.is-active').length === 0) {
          $wrap.find('.fluxa-subtab').attr('aria-selected','false');
          $wrap.find('#tab-ca-general').attr('aria-selected','true');
          $wrap.find('.fluxa-subtabpanel').removeClass('is-active');
          $wrap.find('#panel-ca-general').addClass('is-active');
        }
      }
    }
    $(document).on('change', 'input[name="conversation_cart_abandoned"]', toggleCartAbandonment);
    // Cart Abandonment accordion behavior
    (function(){
      var $wrap = $('#fluxa-ca-vtabs');
      if (!$wrap.length) return;
      function activate(id){
        $wrap.find('.fluxa-vtab').removeClass('is-active').attr('aria-selected','false');
        $wrap.find('#vt-' + id).addClass('is-active').attr('aria-selected','true');
        $wrap.find('.fluxa-vpanel').removeClass('is-active');
        $wrap.find('#vp-' + id).addClass('is-active');
      }
      $(document).on('click', '#fluxa-ca-vtabs .fluxa-vtab', function(e){
        e.preventDefault();
        var id = $(this).attr('id').replace('vt-','');
        if (!id) return;
        activate(id);
      });
      activate('ca-general');
    })();
    // Logo/Icon immediate preview updates
    $('#logo_url, #minimized_icon_url').on('input change', updatePreviewLogos);
    // Also listen to WP Color Picker internal text inputs to reflect changes in preview
    $(document).on('input change', '.wp-picker-input-wrap input', function(){
      // Mirror value back to the bound hidden/visible input if possible, then update preview
      var $wrap = $(this).closest('.wp-picker-container');
      var $bound = $wrap.find('.wp-color-picker');
      if ($bound.length) {
        $bound.val($(this).val());
      }
      updatePreviewColors();
    });
    // Fallback: after picking color via palette, mouseup triggers a refresh
    $(document).on('mouseup keyup', '.wp-picker-holder, .wp-color-result', function(){ setTimeout(updatePreviewColors, 0); });
    // Listen to iris (WP color picker) change events and the bound input itself
    $(document).on('irischange', '.wp-color-picker', function(){
      setTimeout(function(){
        updatePreviewColors();
        // Persist current Custom values so returning to Custom restores exactly what user picked
        var themeNow = ($('input[name="theme"]:checked').val() || 'light').toLowerCase();
        if (themeNow === 'custom') {
          var p = $('#primary_color').val() || '#4F46E5';
          var b = $('#background_color').val() || '#FFFFFF';
          $('#fluxa_custom_primary_saved').val(p);
          $('#fluxa_custom_background_saved').val(b);
          var $panel = $('#panel-design');
          if ($panel.length) {
            $panel.attr('data-custom-primary', p);
            $panel.attr('data-custom-background', b);
          }
        }
      }, 0);
    });
    $(document).on('input change', '.wp-color-picker', function(){ setTimeout(updatePreviewColors, 0); });
    $('#chatbot_name').on('input', updatePreviewTitle);
    $('#logo_url, #minimized_icon_url').on('change', updatePreviewLogos);
    $(document).on('click', '#minimized_icon_remove, #logo_remove', function(){ setTimeout(updatePreviewLogos, 0); });
    $(document).on('click', '#minimized_icon_select, #logo_select', function(){ setTimeout(function(){ $('#logo_url, #minimized_icon_url').trigger('change'); }, 500); });
    $(document).on('input', 'input[name="suggested_questions[]"]', rebuildSuggestionsFromForm);
    $('input[name="suggestions_enabled"]').on('change', rebuildSuggestionsFromForm);
    // Initial
    updateAll();
    // Lightweight polling in case WP Color Picker or media frame don't emit events while changing values
    (function(){
      var lastPrimary = ($('#primary_color').val() || '');
      var lastBg = ($('#background_color').val() || '');
      var lastLogo = ($('#logo_url').val() || '');
      var lastMin = ($('#minimized_icon_url').val() || '');
      setInterval(function(){
        var curPrimary = ($('#primary_color').val() || '');
        var curBg = ($('#background_color').val() || '');
        var curLogo = ($('#logo_url').val() || '');
        var curMin = ($('#minimized_icon_url').val() || '');
        if (curPrimary !== lastPrimary || curBg !== lastBg) {
          lastPrimary = curPrimary; lastBg = curBg;
          updatePreviewColors();
          // Persist to hidden fields and panel so Custom restores after switching away
          if (($('input[name="theme"]:checked').val() || 'light').toLowerCase() === 'custom') {
            $('#fluxa_custom_primary_saved').val(curPrimary || '#4F46E5');
            $('#fluxa_custom_background_saved').val(curBg || '#FFFFFF');
            var $panel = $('#panel-design');
            if ($panel.length) {
              $panel.attr('data-custom-primary', curPrimary || '#4F46E5');
              $panel.attr('data-custom-background', curBg || '#FFFFFF');
            }
          }
        }
        if (curLogo !== lastLogo || curMin !== lastMin) {
          lastLogo = curLogo; lastMin = curMin;
          updatePreviewLogos();
        }
      }, 250);
    })();
    var sync = function(){
        var $p=$('#primary_color'), $b=$('#background_color'), $t=$('#text_color');
        var safeSet = function($el, color){
          if (!$el || !$el.length || !$.fn.wpColorPicker) return;
          var initialized = $el.closest('.wp-picker-container').length > 0;
          if (!initialized) {
            try { $el.wpColorPicker(); } catch(e) { return; }
            setTimeout(function(){
              try { $el.wpColorPicker('color', color); } catch(e) {}
            }, 0);
            return;
          }
          try { $el.wpColorPicker('color', color); } catch(e) {}
        };
        safeSet($p, $p.val() || '#4F46E5');
        safeSet($b, $b.val() || '#FFFFFF');
        if ($t.length) safeSet($t, $t.val() || '#000000');
      };
      sync();
      setTimeout(sync, 150);
      setTimeout(sync, 400);
    // End color picker sync helpers
});
</script>
                            
