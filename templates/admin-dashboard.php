<?php
// Compute status using current Fluxa settings
$api_key   = get_option('fluxa_api_key', '');
$owner_id  = get_option('fluxa_ss_owner_user_id', '');
$replica_id= get_option('fluxa_ss_replica_id', '');

// Active only when both owner and replica are provisioned
$is_active = (!empty($owner_id) && !empty($replica_id));
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php
    // Show a small notice to rerun Quickstart at any time
    if (current_user_can('manage_options')) {
        $restart_nonce = wp_create_nonce('fluxa_restart_quickstart');
        $restart_url = add_query_arg(array(
            'page' => 'fluxa-quickstart',
            'fluxa_restart_quickstart' => '1',
            '_wpnonce' => $restart_nonce
        ), admin_url('admin.php'));
        ?>
        <div class="notice notice-info" style="margin-top: 10px;">
            <p>
                <?php _e('Need to reconfigure? You can run the Quickstart setup again.', 'fluxa-ecommerce-assistant'); ?>
                <a href="<?php echo esc_url($restart_url); ?>" class="button button-secondary" style="margin-left:8px;">
                    <?php _e('Run Quickstart again', 'fluxa-ecommerce-assistant'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    ?>

    <div class="sensay-dashboard-fluxa-cards">
        <!-- Chatbot Status -->
        <div class="fluxa-card">
            <h2 class="fluxa-card__title">
                <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                <?php esc_html_e('Chatbot Status', 'fluxa-ecommerce-assistant'); ?>
            </h2>
            <p>
                <strong><?php esc_html_e('Status:', 'fluxa-ecommerce-assistant'); ?></strong>
                <span class="status-indicator <?php echo $is_active ? 'active' : 'inactive'; ?>">
                    <?php echo $is_active ? esc_html__('Active', 'fluxa-ecommerce-assistant') : esc_html__('Not Active', 'fluxa-ecommerce-assistant'); ?>
                </span>
            </p>
            <?php if ($is_active) : ?>
                <p><?php esc_html_e('Your chatbot is provisioned and ready.', 'fluxa-ecommerce-assistant'); ?></p>
                <ul style="display: none; margin:0; padding-left:18px;">
                    <li><?php echo esc_html__('Owner ID:', 'fluxa-ecommerce-assistant') . ' ' . esc_html($owner_id); ?></li>
                    <li><?php echo esc_html__('Replica ID:', 'fluxa-ecommerce-assistant') . ' ' . esc_html($replica_id); ?></li>
                </ul>
            <?php else : ?>
                <div class="notice notice-error" style="margin-top:10px;">
                    <p style="margin:.5em 0;"><?php esc_html_e('Chatbot is not active yet. The following items are required:', 'fluxa-ecommerce-assistant'); ?></p>
                    <ul style="margin:.5em 0 1em; padding-left:18px; list-style:disc;">
                        <li>
                            <?php if (empty($api_key)) {
                                echo esc_html__('API key is missing', 'fluxa-ecommerce-assistant');
                            } else {
                                echo esc_html__('API key is set', 'fluxa-ecommerce-assistant');
                            } ?>
                        </li>
                        <li>
                            <?php echo empty($owner_id)
                                ? esc_html__('Owner user is not provisioned', 'fluxa-ecommerce-assistant')
                                : esc_html__('Owner user provisioned', 'fluxa-ecommerce-assistant') . ': ' . esc_html($owner_id); ?>
                        </li>
                        <li>
                            <?php echo empty($replica_id)
                                ? esc_html__('Chat replica is not provisioned', 'fluxa-ecommerce-assistant')
                                : esc_html__('Chat replica provisioned', 'fluxa-ecommerce-assistant') . ': ' . esc_html($replica_id); ?>
                        </li>
                    </ul>
                    <p style="margin:.5em 0;">
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=fluxa-quickstart')); ?>"><?php esc_html_e('Run Quickstart', 'fluxa-ecommerce-assistant'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fluxa-assistant-settings')); ?>" style="margin-left:6px;">
                            <?php esc_html_e('Open Settings', 'fluxa-ecommerce-assistant'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="fluxa-card">
            <h2 class="fluxa-card__title">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <?php esc_html_e('Quick Actions', 'fluxa-ecommerce-assistant'); ?>
            </h2>
            <div class="button-group">
                <a href="<?php echo esc_url(admin_url('admin.php?page=fluxa-assistant-settings')); ?>" class="button button-primary">
                    <?php esc_html_e('Settings', 'fluxa-ecommerce-assistant'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=fluxa-quickstart')); ?>" class="button">
                    <?php esc_html_e('Quickstart', 'fluxa-ecommerce-assistant'); ?>
                </a>
            </div>
        </div>

        <!-- System Information -->
        <div class="fluxa-card">
            <h2 class="fluxa-card__title">
                <span class="dashicons dashicons-info" aria-hidden="true"></span>
                <?php esc_html_e('System Information', 'fluxa-ecommerce-assistant'); ?>
            </h2>
            <table class="widefat" style="margin-top: 10px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Plugin Version:', 'fluxa-ecommerce-assistant'); ?></th>
                        <td><?php echo esc_html(defined('FLUXA_VERSION') ? FLUXA_VERSION : ''); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('WordPress Version:', 'fluxa-ecommerce-assistant'); ?></th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('PHP Version:', 'fluxa-ecommerce-assistant'); ?></th>
                        <td><?php echo esc_html(phpversion()); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Welcome + Getting Started -->
    <div class="card fluxa-hero">
        <h2>
            <span class="dashicons dashicons-store" aria-hidden="true"></span>
            <?php _e('Welcome to Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'); ?>
        </h2>
        <p class="description">
            <?php _e('Manage your AI-powered eCommerce assistant from this dashboard.', 'fluxa-ecommerce-assistant'); ?>
        </p>
        
        <div class="fluxa-dashboard-widgets">
            <div class="fluxa-widget">
                <h3>
                    <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    <?php _e('Quick Links', 'fluxa-ecommerce-assistant'); ?>
                </h3>
                <ul>
                    <li><a href="<?php echo admin_url('admin.php?page=fluxa-assistant-settings'); ?>"><?php _e('Chatbot Settings', 'fluxa-ecommerce-assistant'); ?></a></li>
                </ul>
            </div>
            
            <div class="fluxa-widget">
                <h3>
                    <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
                    <?php _e('Getting Started', 'fluxa-ecommerce-assistant'); ?>
                </h3>
                <ol>
                    <li><?php _e('Configure your API key in Settings', 'fluxa-ecommerce-assistant'); ?></li>
                    <li><?php _e('Customize the chatbot appearance', 'fluxa-ecommerce-assistant'); ?></li>
                    <li><?php _e('Connect your store data and content sources', 'fluxa-ecommerce-assistant'); ?></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<style>
.sensay-dashboard-fluxa-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin: 20px 0;
}
.fluxa-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 15px rgba(0,0,0,.05);
    padding: 20px;
    border-radius: 8px;
}
.fluxa-card__title { display: flex; align-items: center; gap: 6px; margin-top: 0; }
.fluxa-card__title .dashicons { color: #4F46E5; }
.status-indicator {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.status-indicator.active { background: #10b981; color: #fff; }
.status-indicator.inactive { background: #ef4444; color: #fff; }
.button-group { display: flex; gap: 10px; margin-top: 15px; }
.widefat { width: 100%; border-collapse: collapse; }
.widefat th, .widefat td { padding: 10px; text-align: left; border-bottom: 1px solid #f0f0f0; }
.widefat th { width: 200px; }

.fluxa-dashboard-widgets { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 20px; }
.fluxa-widget { background: #fff; border: 1px solid #e5e7eb; box-shadow: 0 6px 12px rgba(0,0,0,.04); padding: 16px; flex: 1; min-width: 260px; border-radius: 8px; }
.fluxa-widget h3 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
.fluxa-hero { border-left: 4px solid #4F46E5; }
.fluxa-hero h2 { display: flex; align-items: center; gap: 6px; }
.fluxa-hero .dashicons { color: #4F46E5; }
</style>
