<?php
// Optional namespacing for service layer
namespace Fluxa\API;

if (!defined('ABSPATH')) { exit; }

if (!class_exists('Fluxa\\API\\Sensay_Replica_Service')) {
    class Sensay_Replica_Service {
        /** @var \Sensay_Client */
        private $client;

        public function __construct(\Sensay_Client $client) {
            $this->client = $client;
        }

        /**
         * Provision Sensay owner user. Builds payload internally.
         * @return array|\WP_Error
         */
        public function provision_owner() {
            $site_name_raw = get_bloginfo('name') ?: 'My Store';
            // Allow letters, numbers, space, parentheses, dot, comma, single-quote, dash, slash; remove everything else
            $pattern = '~[^A-Za-z0-9 \(\)\.,\'\-\/]~';
            $site_name = preg_replace($pattern, '', $site_name_raw);
            $owner_name = trim($site_name . ' Owner');

            // Generate a random, harmless email for the external user
            $email = function_exists('generate_random_email')
                ? \generate_random_email()
                : (wp_generate_password(15, false) . '@example.com');

            $payload = array(
                'email' => $email,
                'name'  => $owner_name,
            );
            if (function_exists('fluxa_log')) { \fluxa_log('replica_service: owner payload=' . wp_json_encode($payload)); }

            return $this->client->post('/v1/users', $payload);
        }

        /**
         * Provision Sensay replica (chatbot) for the store.
         * Validates profile image URL and shortDescription length.
         * @param string $owner_id
         * @param string $site_name
         * @param array $design_settings
         * @return array|\WP_Error
         */
        public function provision_replica($owner_id, $site_name, $design_settings = array()) {
            $slug_base = sanitize_title($site_name ?: 'store');
            $slug = trim(($slug_base ? $slug_base . '-support-assistant' : 'store-support-assistant') . '-' . time());
            
            // Profile image: accept only publicly reachable hosts
            $profile_image = '';
            if (is_array($design_settings) && !empty($design_settings['logo_url'])) {
                $maybe_url = esc_url_raw($design_settings['logo_url']);
                if ($this->is_public_url($maybe_url)) {
                    $profile_image = $maybe_url;
                } else {
                    if (function_exists('fluxa_log')) { \fluxa_log('replica_service: logo_url not public, omitting'); }
                }
            }

            $short = 'AI support agent for products and policies.'; // <= 50 chars

            $payload = array(
                'name' => 'Store Support Assistant',
                'purpose' => 'Provide multilingual customer support for this store: orders, shipping, returns, sizing, availability, discounts, stock, and general policies. Advise customers on abandoned carts to help complete purchases.',
                'shortDescription' => $short,
                'greeting' => 'Hi! I can help with orders, shipping, returns, and product fit. What can I do for you today?',
                'type' => 'character',
                'ownerID' => $owner_id,
                'private' => false,
                'whitelistEmails' => array(),
                'slug' => $slug,
                'tags' => array('support','ecommerce','faq','multilingual','orders','returns','products'),
                // profileImage only if valid
            );
            if (!empty($profile_image)) {
                $payload['profileImage'] = $profile_image;
            }
            $payload['suggestedQuestions'] = array();
            $payload['llm'] = array(
                'model' => 'gpt-4o',
                'memoryMode' => 'rag-search',
                'systemMessage' => "- You are the official customer support assistant for this store.\n- Be concise, polite, and solution-oriented. Avoid speculation.\n- Auto-detect and reply in the user’s language (EN/DE/FR/IT/ES/PL/NL/TR). If unclear, ask their preference.\n- Orders/tracking: before sharing status, ask for the order number or customer email.\n- Abandoned cart: offer helpful, ethical suggestions to complete checkout (no pressure).\n- Product data: use retrieved product facts and attributes (via RAG) to answer questions—do not fabricate. Cite exact variants if asked.\n- Policies/pricing may vary by country—ask for country when relevant.\n- If unsure, say so and propose the next step (e.g., link to policy page, ask for missing info).\n- Use bullet points for multi-step instructions.\n- Never expose private keys, admin info, or internal endpoints.",
                'tools' => array(),
            );
            $payload['voicePreviewText'] = 'Hi! I can help with orders, shipping, returns, and product fit.';
            $payload['isAccessibleByCustomerSupport'] = true;
            $payload['isEveryConversationAccessibleBySupport'] = true;
            $payload['isPrivateConversationsEnabled'] = false;

            if (function_exists('fluxa_log')) { \fluxa_log('replica_service: replica payload=' . wp_json_encode($payload)); }

            return $this->client->post('/v1/replicas', $payload);
        }

        private function is_public_url($url) {
            if (empty($url)) { return false; }
            $parts = wp_parse_url($url);
            if (!$parts || empty($parts['host']) || empty($parts['scheme'])) { return false; }
            if (!in_array($parts['scheme'], array('http','https'), true)) { return false; }
            $host = strtolower($parts['host']);
            if ($host === 'localhost' || $host === '127.0.0.1') { return false; }
            if (substr($host, -6) === '.local' || substr($host, -5) === '.test') { return false; }
            return true;
        }
    }
}
