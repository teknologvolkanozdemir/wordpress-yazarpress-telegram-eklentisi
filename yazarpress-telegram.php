<?php
/**
 * Plugin Name: YazarPress Telegram
 * Description: Create WordPress posts, pages, and categories through a Telegram bot.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-3.0-or-later
 * Text Domain: yazarpress-telegram
 */

if (!defined('ABSPATH')) {
    exit;
}

final class YazarPress_Telegram
{
    const OPTION = 'ypt_telegram_settings';
    const REST_NAMESPACE = 'yazarpress-telegram/v1';
    const REST_ROUTE = '/webhook';
    const STATE_TTL = 1800;

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('rest_api_init', array(__CLASS__, 'register_webhook_route'));
        add_action('updated_option', array(__CLASS__, 'settings_updated'), 10, 3);
        add_action('added_option', array(__CLASS__, 'settings_added'), 10, 2);
    }

    public static function add_settings_page()
    {
        add_options_page(
            'YazarPress Telegram',
            'YazarPress Telegram',
            'manage_options',
            'yazarpress-telegram',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function register_settings()
    {
        register_setting('ypt_telegram_group', self::OPTION, array(
            'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
        ));

        add_settings_section(
            'ypt_telegram_connection',
            'Telegram bağlantısı',
            function () {
                echo '<p>Bot token ve botun kullanacağı Telegram sohbet kimliği zorunludur.</p>';
            },
            'yazarpress-telegram'
        );

        add_settings_field(
            'ypt_telegram_token',
            'Bot token',
            array(__CLASS__, 'render_token_field'),
            'yazarpress-telegram',
            'ypt_telegram_connection'
        );

        add_settings_field(
            'ypt_telegram_chat_id',
            'Chat ID',
            array(__CLASS__, 'render_chat_id_field'),
            'yazarpress-telegram',
            'ypt_telegram_connection'
        );
    }

    public static function render_token_field()
    {
        $settings = self::get_settings();
        printf(
            '<input type="password" class="regular-text" autocomplete="new-password" name="%1$s[bot_token]" value="%2$s" required>',
            esc_attr(self::OPTION),
            esc_attr(isset($settings['bot_token']) ? $settings['bot_token'] : '')
        );
    }

    public static function render_chat_id_field()
    {
        $settings = self::get_settings();
        printf(
            '<input type="text" class="regular-text" name="%1$s[chat_id]" value="%2$s" required>',
            esc_attr(self::OPTION),
            esc_attr(isset($settings['chat_id']) ? $settings['chat_id'] : '')
        );
    }

    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>YazarPress Telegram</h1>
            <?php settings_errors(self::OPTION); ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('ypt_telegram_group');
                do_settings_sections('yazarpress-telegram');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function sanitize_settings($input)
    {
        $token = isset($input['bot_token']) ? trim(sanitize_text_field($input['bot_token'])) : '';
        $chat_id = isset($input['chat_id']) ? trim(sanitize_text_field($input['chat_id'])) : '';

        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token)) {
            add_settings_error(self::OPTION, 'invalid_bot_token', 'Geçerli bir Telegram bot tokenı girin.');
            return self::get_settings();
        }

        if (!preg_match('/^-?\d+$/', $chat_id)) {
            add_settings_error(self::OPTION, 'invalid_chat_id', 'Geçerli bir Telegram chat ID girin.');
            return self::get_settings();
        }

        $previous = self::get_settings();

        return array(
            'bot_token' => $token,
            'chat_id' => $chat_id,
            'webhook_secret' => !empty($previous['webhook_secret'])
                ? $previous['webhook_secret']
                : wp_generate_password(32, false, false),
        );
    }

    public static function settings_updated($option, $old_value, $value)
    {
        if (self::OPTION === $option) {
            self::set_webhook($value);
        }
    }

    public static function settings_added($option, $value)
    {
        if (self::OPTION === $option) {
            self::set_webhook($value);
        }
    }

    private static function set_webhook($settings)
    {
        if (empty($settings['bot_token']) || empty($settings['chat_id']) || empty($settings['webhook_secret'])) {
            return;
        }

        $url = rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
        if (0 !== strpos($url, 'https://')) {
            return;
        }

        wp_remote_post('https://api.telegram.org/bot' . $settings['bot_token'] . '/setWebhook', array(
            'timeout' => 15,
            'body' => array(
                'url' => $url,
                'secret_token' => $settings['webhook_secret'],
            ),
        ));
    }

    public static function register_webhook_route()
    {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle_webhook($request)
    {
        $settings = self::get_settings();
        $provided_secret = $request->get_header('X-Telegram-Bot-Api-Secret-Token');
        if (empty($settings['webhook_secret']) || !is_string($provided_secret)
            || !hash_equals($settings['webhook_secret'], $provided_secret)) {
            return new WP_Error('ypt_invalid_webhook', 'Geçersiz webhook doğrulaması.', array('status' => 403));
        }

        $update = $request->get_json_params();
        if (!is_array($update) || empty($update['message']) || !is_array($update['message'])) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $message = $update['message'];
        if (empty($message['chat']['id']) || (string) $message['chat']['id'] !== (string) $settings['chat_id']
            || !isset($message['text']) || !is_string($message['text'])) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        self::handle_message((string) $message['chat']['id'], $message['text']);
        return new WP_REST_Response(array('ok' => true), 200);
    }

    private static function handle_message($chat_id, $text)
    {
        $text = trim($text);
        $state_key = 'ypt_tg_' . md5($chat_id);
        $state = get_transient($state_key);

        if (preg_match('/^\/([a-z_]+)(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text, $match)) {
            $command = strtolower($match[1]);
            if ('start' === $command) {
                delete_transient($state_key);
                self::send_message($chat_id, "Merhaba! Yazı oluşturmak için /yazi, sayfa için /sayfa, kategori için /kategori komutunu kullanın.");
                return;
            }
            if ('yazi' === $command) {
                self::set_state($state_key, array('type' => 'post', 'step' => 'title'));
                self::send_message($chat_id, 'Yazı başlığını gönderin. İşlemi durdurmak için /iptal yazın.');
                return;
            }
            if ('sayfa' === $command) {
                self::set_state($state_key, array('type' => 'page', 'step' => 'title'));
                self::send_message($chat_id, 'Sayfa başlığını gönderin. İşlemi durdurmak için /iptal yazın.');
                return;
            }
            if ('kategori' === $command) {
                self::set_state($state_key, array('type' => 'category', 'step' => 'name'));
                self::send_message($chat_id, 'Oluşturulacak kategori adını gönderin. İşlemi durdurmak için /iptal yazın.');
                return;
            }
            if ('iptal' === $command) {
                delete_transient($state_key);
                self::send_message($chat_id, 'İşlem iptal edildi.');
                return;
            }
        }

        if (empty($state) || empty($state['step'])) {
            self::send_message($chat_id, 'Yeni bir işlem başlatmak için /start komutunu gönderin.');
            return;
        }

        if ('name' === $state['step'] && 'category' === $state['type']) {
            self::create_category($chat_id, $state_key, $text);
            return;
        }

        if ('title' === $state['step']) {
            $title = sanitize_text_field($text);
            if ('' === $title) {
                self::send_message($chat_id, 'Başlık boş olamaz. Lütfen başlığı tekrar gönderin.');
                return;
            }
            $state['title'] = $title;
            $state['step'] = 'content';
            self::set_state($state_key, $state);
            self::send_message($chat_id, 'İçeriği gönderin.');
            return;
        }

        if ('content' === $state['step']) {
            if ('' === $text) {
                self::send_message($chat_id, 'İçerik boş olamaz. Lütfen içeriği tekrar gönderin.');
                return;
            }
            $state['content'] = $text;
            if ('post' === $state['type']) {
                $state['step'] = 'category';
                self::set_state($state_key, $state);
                self::send_message($chat_id, self::category_prompt());
                return;
            }
            self::ask_confirmation($chat_id, $state_key, $state);
            return;
        }

        if ('category' === $state['step'] && 'post' === $state['type']) {
            $term = get_term_by('name', sanitize_text_field($text), 'category');
            if (!$term || is_wp_error($term)) {
                self::send_message($chat_id, 'Bu adla bir kategori bulunamadı. Mevcut kategorilerden birini gönderin.' . "\n" . self::category_prompt());
                return;
            }
            $state['category_id'] = (int) $term->term_id;
            self::ask_confirmation($chat_id, $state_key, $state);
            return;
        }

        if ('confirm' === $state['step']) {
            $choice = strtolower($text);
            if ('s' === $choice) {
                delete_transient($state_key);
                self::send_message($chat_id, 'İşlem iptal edildi; içerik oluşturulmadı.');
                return;
            }
            if ('e' !== $choice && 't' !== $choice) {
                self::send_message($chat_id, 'Lütfen yayınlamak için e, taslakta bırakmak için t veya iptal etmek için s gönderin.');
                return;
            }
            self::save_content($chat_id, $state_key, $state, 'e' === $choice ? 'publish' : 'draft');
            return;
        }

        self::send_message($chat_id, 'Anlaşılamadı. Devam etmek için yanıtınızı gönderin veya /iptal yazın.');
    }

    private static function ask_confirmation($chat_id, $state_key, $state)
    {
        $state['step'] = 'confirm';
        self::set_state($state_key, $state);
        self::send_message($chat_id, 'Bu içeriği yayınlamak istiyor musunuz?' . "\n"
            . 'Başlık: ' . $state['title'] . "\n"
            . 'e: yayınla, t: taslakta bırak, s: iptal et');
    }

    private static function create_category($chat_id, $state_key, $name)
    {
        $name = sanitize_text_field($name);
        if ('' === $name) {
            self::send_message($chat_id, 'Kategori adı boş olamaz. Lütfen bir ad gönderin.');
            return;
        }

        $result = wp_insert_term($name, 'category');
        delete_transient($state_key);
        if (is_wp_error($result)) {
            $message = 'Kategori oluşturulamadı.';
            if ('term_exists' === $result->get_error_code()) {
                $message = 'Bu kategori zaten mevcut.';
            }
            self::send_message($chat_id, $message);
            return;
        }
        self::send_message($chat_id, 'Kategori oluşturuldu: ' . $name);
    }

    private static function save_content($chat_id, $state_key, $state, $status)
    {
        $administrators = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
        $post_data = array(
            'post_title' => $state['title'],
            'post_content' => wp_kses_post($state['content']),
            'post_status' => $status,
            'post_type' => $state['type'],
            'post_author' => !empty($administrators) ? (int) $administrators[0] : 0,
        );

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            self::send_message($chat_id, 'İçerik oluşturulamadı. Lütfen daha sonra tekrar deneyin.');
            return;
        }

        if ('post' === $state['type'] && !wp_set_post_categories($post_id, array((int) $state['category_id']))) {
            wp_delete_post($post_id, true);
            self::send_message($chat_id, 'Kategori yazıya atanamadı; yazı oluşturulmadı.');
            return;
        }

        delete_transient($state_key);
        $result = 'publish' === $status ? 'yayınlandı' : 'taslak olarak kaydedildi';
        self::send_message($chat_id, 'İçerik ' . $result . '.');
    }

    private static function category_prompt()
    {
        $terms = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'number' => 30,
            'fields' => 'names',
        ));
        $names = !is_wp_error($terms) && !empty($terms) ? implode(', ', $terms) : 'Henüz kategori yok';
        return 'Mevcut kategori adını gönderin: ' . $names;
    }

    private static function set_state($key, $state)
    {
        set_transient($key, $state, self::STATE_TTL);
    }

    private static function send_message($chat_id, $text)
    {
        $settings = self::get_settings();
        if (empty($settings['bot_token'])) {
            return;
        }
        wp_remote_post('https://api.telegram.org/bot' . $settings['bot_token'] . '/sendMessage', array(
            'timeout' => 15,
            'body' => array(
                'chat_id' => $chat_id,
                'text' => $text,
            ),
        ));
    }

    private static function get_settings()
    {
        $settings = get_option(self::OPTION, array());
        return is_array($settings) ? $settings : array();
    }
}

YazarPress_Telegram::init();
