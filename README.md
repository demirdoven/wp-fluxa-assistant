# WP Fluxa eCommerce Assistant

AI-powered eCommerce assistant for WordPress + WooCommerce. Ships a modern chatbot widget, conversation tracking, admin chat history, feedback collection, and first‑party event tracking integrated with Sensay.

## Highlights
- Conversational AI via Sensay Replica
- Storefront chatbot widget with unread badge and title counter
- Conversation upsert into `wp_fluxa_conv`
- Admin Chat History with pagination and sorting
- Feedback collection and event logging
- WooCommerce cart/coupon/thank-you tracking hooks
- Clean REST API and resilient frontend JS

---

## Installation
1. Upload the plugin folder `wp-fluxa-ecommerce-assistant/` into `wp-content/plugins/`.
2. Activate the plugin in WordPress Admin → Plugins.
3. Go to Fluxa → Settings and set your API key.
4. Complete the Quickstart wizard to provision a Sensay Replica.

## Configuration
- Set API key at `Fluxa → Settings`.
- Design options stored in `fluxa_design_settings`.
- Tracking toggle: `fluxa_tracking_enabled` (1/0).
- Guest→User merge options: `fluxa_merge_guest_on_login`, `fluxa_merge_window_days`.

---

## Feature List (Simple)
- Chatbot widget on storefront with unread badges
- AI chat powered by Sensay Replica
- Conversation tracking into database
- Visitor identity tracking (guest → user merge)
- Chat history fetch and display
- Feedback/rating on conversations
- Admin: Chat History with pagination/sorting
- Admin: Single-conversation view
- Admin: Quickstart onboarding wizard
- Event tracking for storefront actions
- WooCommerce integration (cart, coupon, thank you)
- Knowledge Base training tools
- REST API endpoints for chat, history, tracking, admin data
- Robust DB schema for conversations, messages, events, feedback
- Admin notices for API key/licence

## Feature List (Detailed)
- Frontend UX
  - Launcher with unread badge + page title counter (`assets/css/chatbot.css`, `templates/chatbot-widget.php`).
  - Chat JS handles streaming and resilient `conversation_uuid` parsing (`assets/js/chatbot.js`).
  - Localized settings via `wp_localize_script('fluxa-chatbot', 'fluxaChatbot', ...)`.
- Conversation Tracking
  - `fluxa/v1/conversation/track` → `rest_track_conversation()` upserts into `{$wpdb->prefix}fluxa_conv`.
  - Stores `first_seen`, `last_seen`, UA, IP, `wc_session_key`, `ss_user_id`.
- Chat + History
  - `fluxa/v1/chat` forwards to Sensay.
  - `fluxa/v1/chat/history` fetches previous messages for current visitor.
- Admin
  - Chat History (`templates/admin-chat-history.php`): visible headers, loader, API-driven pagination/sorting.
  - Single conversation view: cursor/lazy messages.
  - Quickstart wizard + admin notices for key setup.
- Events + Feedback
  - `Fluxa_Event_Tracker::log_event()` logs to `wp_fluxa_conv_events`.
  - Feedback via `fluxa/v1/feedback` into `wp_fluxa_feedback` + event.
- WooCommerce
  - Hooks for add/remove cart, quantity update, coupon apply, thank-you page.
  - Product metadata enrichment for events.
- Developer Experience
  - Clear structure: `includes/`, `templates/`, `assets/`.
  - Defensive `dbDelta()` schema creation, sanitized inputs, REST nonces.

---

## REST API
Base: `/wp-json/fluxa/v1`

- `POST /chat`
  - Body: `{ content: string, skip_chat_history?: boolean }`
  - Returns Sensay response (interim/final handling on client).

- `GET /chat/history`
  - Returns an array of message items for the current visitor.

- `POST /conversation/track`
  - Body: `{ conversation_id: string, ss_user_id?: string, wc_session_key?: string }`
  - Upserts row in `wp_fluxa_conv`.

- `POST /feedback`
  - Body: `{ conversation_id: string, rating_point: number, page_url?: string, page_referrer?: string }`
  - Stores rating and logs feedback event.

- Admin endpoints (examples)
  - `GET /conversations` (paging/sorting)
  - `GET /conversation/last-seen?uuids=...`

Auth: Public endpoints rely on `X-WP-Nonce` (localized `fluxaChatbot.nonce`) for CSRF protection.

---

## Database Schema (Overview)
- `wp_fluxa_conv`
  - `id`, `conversation_id` (UNIQUE), `wc_session_key`, `ss_user_id`, `wp_user_id`, `last_ip VARBINARY(16)`, `last_ua`, `first_seen`, `last_seen`, `seen_count`.
- `wp_fluxa_messages`
  - `id`, `conversation_id`, `role`, `content`, `is_interim`, `ss_user_id`, `source`, `created_at`, `meta`.
- `wp_fluxa_conv_events`
  - `id`, `ss_user_id`, `wc_session_key`, `user_id`, `event_type`, `event_time`, `url`, `referer`, `ip`, `user_agent`, product/order/cart fields, `json_payload`.
- `wp_fluxa_feedback`
  - `id`, `conversation_id`, `rating_point`, `created_at`.

> Tables are created with `dbDelta()` in `create_tables()` during activation. Some routes defensively call `create_tables()` if needed.

---

## Development Notes
- Main file: `wp-fluxa-ecommerce-assistant.php`
- Admin menu: `includes/admin/class-admin-menu.php`
- Utilities: `includes/utils/`
- API clients: `includes/api/`
- Frontend assets: `assets/js/chatbot.js`, `assets/css/chatbot.css`

### Building/Debugging
- Enable `WP_DEBUG` to surface console logging and richer diagnostics.
- Use browser DevTools to verify `fluxaChatbot` localization and REST calls.
- Visit `/wp-json/fluxa/v1` to list routes.

### Licence
TBD
