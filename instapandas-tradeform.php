<?php
/**
 * Plugin Name: InstaPandas TradeForm
 * Description: 可自由添加/编辑字段的留言表单，提交后立即返回成功，邮件通知在后台异步发送。后台提交记录支持国家/浏览器/来源识别与未读角标提醒。
 * Version: 2.5.4
 * Author: Alec.Feng
 * Text Domain: instapandas-tradeform
 *
 * 更新日志：
 * 2.5.4 - 更新诊断工具改为在设置页原地显示结果（AJAX），不再跳出新页面
 * 2.5.3 - 新增"插件自动更新诊断"工具（在 reCAPTCHA 设置页），一键实时检查服务器是否能
 *         连上 GitHub API、返回了什么，方便排查更新提示不出现的具体原因
 * 2.5.2 - reCAPTCHA v2 改为 explicit render（手动渲染 + 记录 widget id），
 *         不再使用通用的 .g-recaptcha class，解决与站内其它脚本（主题/其它插件）
 *         抢着渲染同一个元素导致 "reCAPTCHA has already been rendered" 报错、
 *         勾选框提交后无法重置的问题
 * 2.5.1 - 修复 reCAPTCHA v2 提交后偶发无法重置勾选框、需刷新页面才能再次提交的问题；
 *         新增基于 GitHub Releases 的自动更新检查，插件更新后在 WordPress 后台
 *         "插件"页面即可直接点击更新，无需再手动上传覆盖
 * 2.5.0 - 新增 Google reCAPTCHA 验证：支持 v2（勾选框）和 v3（无感知评分）两种版本，
 *         在"reCAPTCHA设置"页填入 Site Key / Secret Key 后自动在表单里启用，
 *         提交时后台会向 Google 校验，未通过则拒绝提交；不填写密钥则不启用，不影响原有功能
 * 2.4.0 - 新增黑名单系统：支持按邮箱（整个地址或 @domain.com 匹配整个域名）、
 *         关键词（匹配任意字段内容）、IP（支持 1.2.3.* 通配符）拦截垃圾提交；
 *         命中黑名单的提交不入"新留言"、不发邮件通知，但会记录到后台"已拦截"
 *         列表方便复查是否误拦截；访客端仍显示提交成功，不会打草惊蛇；
 *         提交详情页新增"拉黑此IP / 拉黑此邮箱"一键拉黑按钮
 * 2.3.0 - 国家识别改为提交时同步查询（不用再手动点刷新）；提交记录列表页每15秒自动刷新
 *         （新提交会自动出现，未读数/角标同步更新，无需手动刷新页面）
 * 2.2.0 - 简码改为 [instapandas_tradeform]；国家识别改为返回中文（ip-api lang=zh-CN）；
 *         详情页新增"刷新"按钮可手动重新识别国家；插件头信息改为 InstaPandas TradeForm / Alec.Feng
 * 2.1.0 - 新增提交记录列表筛选（全部/未读/已读）、详情页、IP/国家/浏览器/设备/来源URL记录、
 *         后台菜单未读角标 + 轮询更新
 * 2.0.0 - 首个版本：自定义字段表单 + 异步邮件通知
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Inquiry_Form_V2 {

    const TABLE_SUFFIX    = 'cif_submissions_v2';
    const FIELDS_OPTION   = 'cif_fields_config';
    const SETTINGS_OPTION = 'cif_settings';
    const BLACKLIST_OPTION = 'cif_blacklist_settings';
    const RECAPTCHA_OPTION = 'cif_recaptcha_settings';
    const CRON_HOOK        = 'cif_send_notification_event_v2';
    const NONCE_ACTION     = 'cif_submit_form_v2';
    const DB_VERSION_OPTION = 'cif_db_version';
    const DB_VERSION        = '2.5.0';
    const ADMIN_AJAX_NONCE  = 'cif_admin_ajax';

    // 用于自动更新检查的 GitHub 仓库（owner/repo），仓库需为 Public，
    // 每次发布新版本时打一个 tag（如 v2.5.1）并上传打包好的插件 zip 作为 Release 附件
    const GITHUB_REPO = 'Scorpius-hub/instapandas_tradeform';

    private $supported_types = array(
        'text'     => '单行文本',
        'email'    => '邮箱',
        'tel'      => '电话',
        'number'   => '数字',
        'textarea' => '多行文本',
        'select'   => '下拉选择',
        'radio'    => '单选按钮',
        'checkbox' => '多选框',
        'date'     => '日期',
    );

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );

        add_shortcode( 'instapandas_tradeform', array( $this, 'render_form' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        add_action( 'wp_ajax_cif_submit', array( $this, 'handle_submit' ) );
        add_action( 'wp_ajax_nopriv_cif_submit', array( $this, 'handle_submit' ) );

        add_action( self::CRON_HOOK, array( $this, 'send_notification_email' ), 10, 1 );

        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_post_cif_save_fields', array( $this, 'handle_save_fields' ) );
        add_action( 'admin_post_cif_blacklist_quick_add', array( $this, 'handle_blacklist_quick_add' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // 未读数角标轮询（全局挂在所有后台页面上，实现"进入后台任意页面都能看到最新未读数"）
        add_action( 'wp_ajax_cif_get_unread_count', array( $this, 'ajax_get_unread_count' ) );
        add_action( 'wp_ajax_cif_toggle_status', array( $this, 'ajax_toggle_status' ) );
        add_action( 'wp_ajax_cif_mark_all_read', array( $this, 'ajax_mark_all_read' ) );
        add_action( 'wp_ajax_cif_get_submissions_html', array( $this, 'ajax_get_submissions_html' ) );

        // GitHub Releases 自动更新检查
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugins_api_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_update_source_dir' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( $this, 'purge_update_cache' ), 10, 2 );
        add_action( 'admin_post_cif_debug_update_check', array( $this, 'handle_debug_update_check' ) );
        add_action( 'wp_ajax_cif_debug_update_check', array( $this, 'ajax_debug_update_check' ) );
    }

    /* ---------------------- GitHub Releases 自动更新 ---------------------- */

    /**
     * 当前插件文件的 Version 头信息，直接读取文件头注释，避免和常量重复维护出现不一致
     */
    private function get_current_version() {
        if ( ! function_exists( 'get_file_data' ) ) {
            require_once ABSPATH . WPINC . '/functions.php';
        }
        $data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
        return isset( $data['Version'] ) ? $data['Version'] : '0.0.0';
    }

    /**
     * 拉取 GitHub 最新 Release 信息，12 小时缓存一次，避免频繁请求 GitHub API 被限流
     * 返回 false 表示暂时取不到（网络问题 / 还没发布过 Release），version/package/notes 为具体信息
     */
    private function get_github_release_info() {
        $cache_key = 'cif_github_release_info';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached ? $cached : false;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            array(
                'timeout' => 8,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    // GitHub API 要求必须带 User-Agent，否则会被拒绝
                    'User-Agent' => 'InstaPandas-TradeForm-Updater',
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // 取失败先短暂缓存空结果，避免每次加载插件页面都重新请求
            set_transient( $cache_key, '', 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['tag_name'] ) ) {
            set_transient( $cache_key, '', 5 * MINUTE_IN_SECONDS );
            return false;
        }

        // tag 一般是 v2.5.1 这种格式，去掉开头的 v 得到纯版本号
        $version = ltrim( $body['tag_name'], 'vV' );

        // 优先用 Release 里手动上传的 zip 附件（文件夹名已经是正确的插件目录名）；
        // 如果没有附件，退回用 GitHub 自动生成的源码包（文件夹名会带仓库名+commit，需要靠
        // fix_update_source_dir() 再纠正一次）
        $package = '';
        if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
        }
        if ( '' === $package && ! empty( $body['zipball_url'] ) ) {
            $package = $body['zipball_url'];
        }

        $info = array(
            'version' => $version,
            'package' => $package,
            'notes'   => isset( $body['body'] ) ? $body['body'] : '',
            'url'     => isset( $body['html_url'] ) ? $body['html_url'] : ( 'https://github.com/' . self::GITHUB_REPO ),
        );

        set_transient( $cache_key, $info, 12 * HOUR_IN_SECONDS );
        return $info;
    }

    /**
     * 挂在 pre_set_site_transient_update_plugins，把 GitHub 上更新的版本信息塞进 WP 的更新检查结果里，
     * 这样后台"插件"页面就会正常显示"有可用更新"，点更新按钮走 WP 原生升级流程
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }

        $remote = $this->get_github_release_info();
        if ( ! $remote || empty( $remote['package'] ) ) {
            return $transient;
        }

        $current_version = $this->get_current_version();
        if ( ! version_compare( $remote['version'], $current_version, '>' ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( __FILE__ );

        $item              = new stdClass();
        $item->slug        = dirname( $plugin_file );
        $item->plugin      = $plugin_file;
        $item->new_version = $remote['version'];
        $item->url         = $remote['url'];
        $item->package     = $remote['package'];
        $item->tested      = get_bloginfo( 'version' );

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        $transient->response[ $plugin_file ] = $item;

        return $transient;
    }

    /**
     * 插件列表页点"查看详情"弹窗时显示的信息（版本号、更新说明），非必需但体验更完整
     */
    public function plugins_api_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
            return $result;
        }

        $remote = $this->get_github_release_info();
        if ( ! $remote ) {
            return $result;
        }

        $info                 = new stdClass();
        $info->name           = 'InstaPandas TradeForm';
        $info->slug           = $args->slug;
        $info->version        = $remote['version'];
        $info->author         = 'Alec.Feng';
        $info->homepage       = $remote['url'];
        $info->sections       = array(
            'description' => '可自由添加/编辑字段的留言表单插件。',
            'changelog'   => wpautop( wp_kses_post( $remote['notes'] ) ),
        );
        $info->download_link  = $remote['package'];

        return $info;
    }

    /**
     * 如果更新包用的是 GitHub 自动生成的源码压缩包（zipball），解压后文件夹名会是
     * "仓库名-commit哈希" 这种格式，跟原来已安装的插件目录名对不上，WordPress 会把它
     * 当成一个新插件装进去，导致设置丢失、重复插件。这里统一把解压出来的文件夹改名成
     * 跟当前插件目录一致，保证是"原地更新"而不是"新装一个"。
     * 如果 Release 里传的是手动打包好的、文件夹名本来就正确的 zip，这一步不会有影响。
     */
    public function fix_update_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        global $wp_filesystem;

        if ( empty( $hook_extra['plugin'] ) || plugin_basename( __FILE__ ) !== $hook_extra['plugin'] ) {
            return $source;
        }

        $desired_slug = dirname( plugin_basename( __FILE__ ) );
        $current_name = basename( untrailingslashit( $source ) );

        if ( $current_name === $desired_slug || ! $wp_filesystem ) {
            return $source;
        }

        $new_source = trailingslashit( $remote_source ) . $desired_slug . '/';

        if ( $wp_filesystem->move( $source, $new_source ) ) {
            return $new_source;
        }

        return $source;
    }

    /**
     * 更新完成后清掉缓存的 Release 信息，下次进插件页面会重新拉取最新数据，
     * 避免刚更新完还提示"有新版本"（用的是更新前缓存的旧判断结果）
     */
    public function purge_update_cache( $upgrader, $hook_extra ) {
        delete_transient( 'cif_github_release_info' );
    }

    /**
     * 后台一键诊断：跳过缓存，实时真实请求一次 GitHub API，把结果原样展示出来，
     * 方便判断"到底是服务器连不上 GitHub" / "仓库没发布 Release" / "版本号没比当前的高"
     * / "Release 里没传 zip 附件" 中的哪一种情况，不用去查服务器日志。
     */
    public function handle_debug_update_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足' );
        }
        check_admin_referer( 'cif_debug_update_check' );

        echo '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;max-width:800px;margin:40px auto;line-height:1.8;">';
        echo $this->build_update_diagnostic_html();
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=custom-inquiry-form-recaptcha' ) ) . '">&larr; 返回设置页</a></p>';
        echo '</div>';
        exit;
    }

    /**
     * 供设置页里的按钮通过 AJAX 调用，结果直接原地显示在设置页下方，不用跳出新页面
     */
    public function ajax_debug_update_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        check_ajax_referer( self::ADMIN_AJAX_NONCE, 'nonce' );

        wp_send_json_success( array( 'html' => $this->build_update_diagnostic_html() ) );
    }

    /**
     * 实际执行诊断请求、拼出结果 HTML 片段（不含外层容器），两个入口共用这一份逻辑
     */
    private function build_update_diagnostic_html() {
        delete_transient( 'cif_github_release_info' );

        $current_version = $this->get_current_version();

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'InstaPandas-TradeForm-Updater',
                ),
            )
        );

        $html  = '<h3 style="margin-top:0;">检查结果</h3>';
        $html .= '<p><strong>当前插件版本：</strong>' . esc_html( $current_version ) . '</p>';
        $html .= '<p><strong>检查地址：</strong>https://api.github.com/repos/' . esc_html( self::GITHUB_REPO ) . '/releases/latest</p>';

        if ( is_wp_error( $response ) ) {
            $html .= '<p style="color:#b31212;"><strong>请求失败——服务器连不上 GitHub：</strong></p>';
            $html .= '<pre style="background:#fdeaea;padding:12px;border-radius:4px;white-space:pre-wrap;">' . esc_html( $response->get_error_message() ) . '</pre>';
            $html .= '<p>这种情况通常是服务器所在机房无法访问 GitHub（不少大陆服务器都有这个限制）。可以联系主机服务商确认服务器能不能访问 https://api.github.com ，或者考虑让服务器走一个可用的出站代理。</p>';
        } else {
            $code     = wp_remote_retrieve_response_code( $response );
            $body_raw = wp_remote_retrieve_body( $response );

            $html .= '<p><strong>HTTP 状态码：</strong>' . esc_html( $code ) . '</p>';

            if ( 200 !== (int) $code ) {
                $html .= '<p style="color:#b31212;"><strong>GitHub 返回了非正常状态码，原始返回内容：</strong></p>';
                $html .= '<pre style="background:#fdeaea;padding:12px;border-radius:4px;max-height:300px;overflow:auto;white-space:pre-wrap;">' . esc_html( $body_raw ) . '</pre>';
            } else {
                $body = json_decode( $body_raw, true );

                if ( empty( $body['tag_name'] ) ) {
                    $html .= '<p style="color:#b31212;"><strong>请求成功，但没有解析到版本号（可能仓库还没发布任何 Release，或仓库不是 Public）：</strong></p>';
                    $html .= '<pre style="background:#fdeaea;padding:12px;border-radius:4px;max-height:300px;overflow:auto;white-space:pre-wrap;">' . esc_html( $body_raw ) . '</pre>';
                } else {
                    $remote_version = ltrim( $body['tag_name'], 'vV' );
                    $has_update     = version_compare( $remote_version, $current_version, '>' );

                    $html .= '<p style="color:#1e7e34;"><strong>请求成功！</strong></p>';
                    $html .= '<p><strong>GitHub 上最新版本：</strong>' . esc_html( $remote_version ) . '</p>';
                    $html .= '<p><strong>是否判定为有更新：</strong>' . ( $has_update
                        ? '<span style="color:#1e7e34;">是，插件页面应该会出现更新提示</span>'
                        : '<span style="color:#b31212;">否（GitHub 上的版本号没有比当前已安装版本更高，正常现象，不算错误）</span>' ) . '</p>';

                    $asset_found = false;
                    if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
                        foreach ( $body['assets'] as $asset ) {
                            if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
                                $html       .= '<p><strong>找到的 zip 附件：</strong>' . esc_html( $asset['name'] ) . '</p>';
                                $asset_found = true;
                                break;
                            }
                        }
                    }
                    if ( ! $asset_found ) {
                        $html .= '<p style="color:#b31212;">没有在 Release 里找到 .zip 格式的附件——发布 Release 时记得把打包好的 zip 拖进 Assets 区域上传。</p>';
                    }
                }
            }
        }

        return $html;
    }

    /**
     * 如果数据库结构版本落后，重新跑一次 dbDelta（用于老版本插件平滑升级到 2.1.0 的新字段）
     */
    public function maybe_upgrade_db() {
        if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
            $this->create_or_upgrade_table();
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        }
    }

    /* ---------------------- 安装 ---------------------- */

    public function on_activate() {
        $this->create_or_upgrade_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        // 首次启用时，给一套默认字段（跟原来 Fluent Forms 的字段一致），避免表单是空的
        if ( false === get_option( self::FIELDS_OPTION, false ) ) {
            $default_fields = array(
                array(
                    'key'         => 'name',
                    'label'       => 'Name',
                    'type'        => 'text',
                    'required'    => 1,
                    'placeholder' => 'Name',
                    'options'     => '',
                ),
                array(
                    'key'         => 'email',
                    'label'       => 'Email',
                    'type'        => 'email',
                    'required'    => 1,
                    'placeholder' => 'Email Address',
                    'options'     => '',
                ),
                array(
                    'key'         => 'phone',
                    'label'       => 'Phone',
                    'type'        => 'tel',
                    'required'    => 0,
                    'placeholder' => 'Phone/WhatsApp',
                    'options'     => '',
                ),
                array(
                    'key'         => 'message',
                    'label'       => 'Your Message',
                    'type'        => 'textarea',
                    'required'    => 1,
                    'placeholder' => 'Provide product details—including size, color, materials, and any special requirements—to receive an accurate quote.',
                    'options'     => '',
                ),
            );
            update_option( self::FIELDS_OPTION, $default_fields );
        }
    }

    /**
     * 建表 / 补充新增字段列（dbDelta 对已存在的表只会补差异列，不会丢数据）
     */
    private function create_or_upgrade_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            data LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            ip VARCHAR(45) NULL,
            country VARCHAR(100) NULL,
            country_code VARCHAR(10) NULL,
            browser VARCHAR(60) NULL,
            device VARCHAR(60) NULL,
            user_agent VARCHAR(255) NULL,
            source_url VARCHAR(500) NULL,
            submitted_by VARCHAR(100) NULL,
            block_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ---------------------- 字段配置读取/保存 ---------------------- */

    private function get_fields_config() {
        $fields = get_option( self::FIELDS_OPTION, array() );
        return is_array( $fields ) ? $fields : array();
    }

    private function get_settings() {
        $defaults = array(
            'to_email' => get_option( 'admin_email' ),
            'subject'  => '[新询盘] 网站表单提交通知',
        );
        return wp_parse_args( get_option( self::SETTINGS_OPTION, array() ), $defaults );
    }

    /**
     * 黑名单配置：邮箱 / 关键词 / IP，均以"每行一条"的原始文本形式存储，方便在设置页里直接编辑
     */
    private function get_blacklist_settings() {
        $defaults = array(
            'emails'   => '',
            'keywords' => '',
            'ips'      => '',
        );
        return wp_parse_args( get_option( self::BLACKLIST_OPTION, array() ), $defaults );
    }

    /**
     * 把"每行一条"的文本框内容解析成去重、去空、去首尾空格的数组
     */
    private function parse_blacklist_lines( $raw ) {
        if ( empty( $raw ) ) {
            return array();
        }
        $lines = preg_split( '/[\r\n]+/', (string) $raw );
        $lines = array_map( 'trim', $lines );
        $lines = array_filter( $lines, function( $v ) { return '' !== $v; } );
        return array_values( array_unique( $lines ) );
    }

    public function sanitize_blacklist_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $clean = array();

        // 邮箱黑名单：允许完整邮箱地址，或 @domain.com 形式匹配整个域名
        $emails = $this->parse_blacklist_lines( isset( $input['emails'] ) ? wp_unslash( $input['emails'] ) : '' );
        $emails = array_map( function( $v ) { return strtolower( sanitize_text_field( $v ) ); }, $emails );
        $clean['emails'] = implode( "\n", $emails );

        // 关键词黑名单：原样保留（不区分大小写匹配时再转小写），仅做基础过滤
        $keywords = $this->parse_blacklist_lines( isset( $input['keywords'] ) ? wp_unslash( $input['keywords'] ) : '' );
        $keywords = array_map( 'sanitize_text_field', $keywords );
        $clean['keywords'] = implode( "\n", $keywords );

        // IP 黑名单：支持精确 IP，或形如 1.2.3.* 的前缀通配符
        $ips = $this->parse_blacklist_lines( isset( $input['ips'] ) ? wp_unslash( $input['ips'] ) : '' );
        $ips = array_filter( $ips, function( $v ) {
            $bare = str_replace( '*', '0', $v );
            return false !== filter_var( $bare, FILTER_VALIDATE_IP ) || preg_match( '/^[0-9a-fA-F:.]+\*?$/', $v );
        } );
        $clean['ips'] = implode( "\n", $ips );

        return $clean;
    }

    /**
     * 判断某条即将提交的数据是否命中黑名单，命中则返回拦截原因（字符串），否则返回空字符串
     */
    private function get_blacklist_match_reason( $ip, $data ) {
        $bl = $this->get_blacklist_settings();

        // 1) IP 黑名单
        $ip_rules = $this->parse_blacklist_lines( $bl['ips'] );
        if ( ! empty( $ip ) && ! empty( $ip_rules ) ) {
            foreach ( $ip_rules as $rule ) {
                if ( $this->ip_matches_rule( $ip, $rule ) ) {
                    return 'IP黑名单：' . $rule;
                }
            }
        }

        // 收集本次提交里所有字段值，用于邮箱/关键词匹配
        $all_values = is_array( $data ) ? array_values( $data ) : array();
        $haystack   = strtolower( implode( ' ', array_map( 'strval', $all_values ) ) );

        // 2) 邮箱黑名单（整地址匹配，或 @domain.com 匹配整个域名）
        $email_rules = $this->parse_blacklist_lines( $bl['emails'] );
        if ( ! empty( $email_rules ) ) {
            foreach ( $all_values as $value ) {
                $value = (string) $value;
                if ( ! is_email( $value ) ) {
                    continue;
                }
                $value_lower = strtolower( $value );
                $domain      = '@' . substr( $value_lower, strpos( $value_lower, '@' ) + 1 );
                foreach ( $email_rules as $rule ) {
                    $rule_lower = strtolower( $rule );
                    if ( $rule_lower === $value_lower || ( 0 === strpos( $rule_lower, '@' ) && $rule_lower === $domain ) ) {
                        return '邮箱黑名单：' . $rule;
                    }
                }
            }
        }

        // 3) 关键词黑名单（在任意字段内容中匹配，不区分大小写）
        $keyword_rules = $this->parse_blacklist_lines( $bl['keywords'] );
        if ( ! empty( $keyword_rules ) && '' !== trim( $haystack ) ) {
            foreach ( $keyword_rules as $keyword ) {
                if ( '' !== trim( $keyword ) && false !== strpos( $haystack, strtolower( $keyword ) ) ) {
                    return '关键词黑名单：' . $keyword;
                }
            }
        }

        return '';
    }

    /**
     * IP 是否匹配黑名单规则，支持精确匹配和 1.2.3.* 前缀通配符
     */
    private function ip_matches_rule( $ip, $rule ) {
        if ( false === strpos( $rule, '*' ) ) {
            return hash_equals( $rule, $ip );
        }
        $prefix = rtrim( str_replace( '*', '', $rule ), '.:' );
        return '' !== $prefix && 0 === strpos( $ip, $prefix );
    }

    /**
     * reCAPTCHA 配置：版本（v2 勾选框 / v3 无感知评分）+ Site Key + Secret Key
     */
    private function get_recaptcha_settings() {
        $defaults = array(
            'enabled'         => 0,
            'version'         => 'v2',
            'site_key'        => '',
            'secret_key'      => '',
            'score_threshold' => 0.5,
        );
        return wp_parse_args( get_option( self::RECAPTCHA_OPTION, array() ), $defaults );
    }

    public function sanitize_recaptcha_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $clean = array();

        $clean['enabled']    = ! empty( $input['enabled'] ) ? 1 : 0;
        $clean['version']    = ( isset( $input['version'] ) && 'v3' === $input['version'] ) ? 'v3' : 'v2';
        $clean['site_key']   = isset( $input['site_key'] ) ? sanitize_text_field( trim( $input['site_key'] ) ) : '';
        $clean['secret_key'] = isset( $input['secret_key'] ) ? sanitize_text_field( trim( $input['secret_key'] ) ) : '';

        $threshold = isset( $input['score_threshold'] ) ? (float) $input['score_threshold'] : 0.5;
        if ( $threshold < 0 || $threshold > 1 ) {
            $threshold = 0.5;
        }
        $clean['score_threshold'] = $threshold;

        // 密钥没填全就不允许真正启用，避免表单加载了 reCAPTCHA 脚本却始终验证失败
        if ( '' === $clean['site_key'] || '' === $clean['secret_key'] ) {
            $clean['enabled'] = 0;
        }

        return $clean;
    }

    /**
     * 是否应该在前台启用 reCAPTCHA（开关打开 + 两个密钥都已填写）
     */
    private function is_recaptcha_active() {
        $r = $this->get_recaptcha_settings();
        return ! empty( $r['enabled'] ) && ! empty( $r['site_key'] ) && ! empty( $r['secret_key'] );
    }

    /**
     * 向 Google siteverify 接口校验 reCAPTCHA 令牌，成功返回空字符串，失败返回错误原因
     */
    private function verify_recaptcha_token( $token, $remote_ip ) {
        $settings = $this->get_recaptcha_settings();

        if ( empty( $token ) ) {
            return '未完成人机验证，请重试。';
        }

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            array(
                'timeout' => 5,
                'body'    => array(
                    'secret'   => $settings['secret_key'],
                    'response' => $token,
                    'remoteip' => $remote_ip,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            // Google 接口异常时不阻塞正常访客提交，静默放行
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            return '人机验证未通过，请重试。';
        }

        if ( 'v3' === $settings['version'] ) {
            $score = isset( $body['score'] ) ? (float) $body['score'] : 0;
            if ( $score < (float) $settings['score_threshold'] ) {
                return '人机验证评分过低，请重试。';
            }
        }

        return '';
    }

    public function register_settings() {
        register_setting( 'cif_settings_group', self::SETTINGS_OPTION );
        register_setting(
            'cif_blacklist_group',
            self::BLACKLIST_OPTION,
            array( 'sanitize_callback' => array( $this, 'sanitize_blacklist_settings' ) )
        );
        register_setting(
            'cif_recaptcha_group',
            self::RECAPTCHA_OPTION,
            array( 'sanitize_callback' => array( $this, 'sanitize_recaptcha_settings' ) )
        );
    }

    /**
     * 处理"字段管理器"页面的保存请求
     */
    public function handle_save_fields() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '没有权限。' );
        }
        check_admin_referer( 'cif_save_fields_action' );

        $labels    = isset( $_POST['field_label'] ) ? (array) wp_unslash( $_POST['field_label'] ) : array();
        $types     = isset( $_POST['field_type'] ) ? (array) wp_unslash( $_POST['field_type'] ) : array();
        $requireds = isset( $_POST['field_required'] ) ? (array) wp_unslash( $_POST['field_required'] ) : array();
        $placeholders = isset( $_POST['field_placeholder'] ) ? (array) wp_unslash( $_POST['field_placeholder'] ) : array();
        $options_raw  = isset( $_POST['field_options'] ) ? (array) wp_unslash( $_POST['field_options'] ) : array();
        $keys      = isset( $_POST['field_key'] ) ? (array) wp_unslash( $_POST['field_key'] ) : array();

        $new_fields  = array();
        $used_keys   = array();

        foreach ( $labels as $i => $label ) {
            $label = sanitize_text_field( $label );
            if ( '' === trim( $label ) ) {
                continue; // 跳过空行
            }

            $type = isset( $types[ $i ] ) && array_key_exists( $types[ $i ], $this->supported_types ) ? $types[ $i ] : 'text';

            // 保留已有的 key（编辑已存在字段时不改变数据库里对应的键名），新增字段才重新生成 key
            $existing_key = isset( $keys[ $i ] ) ? sanitize_key( $keys[ $i ] ) : '';
            $base_key     = $existing_key ? $existing_key : sanitize_title( $label );
            $base_key     = $base_key ? $base_key : 'field';

            $key = $base_key;
            $suffix = 1;
            while ( in_array( $key, $used_keys, true ) ) {
                $key = $base_key . '_' . $suffix;
                $suffix++;
            }
            $used_keys[] = $key;

            $new_fields[] = array(
                'key'         => $key,
                'label'       => $label,
                'type'        => $type,
                'required'    => isset( $requireds[ $i ] ) ? 1 : 0,
                'placeholder' => isset( $placeholders[ $i ] ) ? sanitize_text_field( $placeholders[ $i ] ) : '',
                'options'     => isset( $options_raw[ $i ] ) ? sanitize_text_field( $options_raw[ $i ] ) : '',
            );
        }

        update_option( self::FIELDS_OPTION, $new_fields );

        wp_safe_redirect( add_query_arg( array( 'page' => 'custom-inquiry-form-fields', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ---------------------- 工具方法：IP / 国家 / 浏览器 / 设备 ---------------------- */

    /**
     * 获取访客真实 IP（尽量兼容 CDN / 反代场景下的 X-Forwarded-For）
     */
    private function get_client_ip() {
        $keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // X-Forwarded-For 可能是逗号分隔的多级代理链，取第一个
                if ( false !== strpos( $value, ',' ) ) {
                    $parts = explode( ',', $value );
                    $value = trim( $parts[0] );
                }
                if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * 极简 User-Agent 解析，识别常见浏览器和设备/系统，避免引入第三方库
     */
    private function parse_user_agent( $ua ) {
        $browser = '未知';
        $device  = '未知';

        if ( empty( $ua ) ) {
            return array( $browser, $device );
        }

        if ( preg_match( '/Edg\//i', $ua ) ) {
            $browser = 'Edge';
        } elseif ( preg_match( '/OPR\/|Opera/i', $ua ) ) {
            $browser = 'Opera';
        } elseif ( preg_match( '/Chrome\//i', $ua ) && ! preg_match( '/Chromium/i', $ua ) ) {
            $browser = 'Chrome';
        } elseif ( preg_match( '/Firefox\//i', $ua ) ) {
            $browser = 'Firefox';
        } elseif ( preg_match( '/Version\/.*Safari/i', $ua ) ) {
            $browser = 'Safari';
        } elseif ( preg_match( '/MSIE|Trident/i', $ua ) ) {
            $browser = 'Internet Explorer';
        }

        if ( preg_match( '/iPhone/i', $ua ) ) {
            $device = 'iPhone';
        } elseif ( preg_match( '/iPad/i', $ua ) ) {
            $device = 'iPad';
        } elseif ( preg_match( '/Android/i', $ua ) ) {
            $device = 'Android';
        } elseif ( preg_match( '/Macintosh|Mac OS X/i', $ua ) ) {
            $device = 'Mac';
        } elseif ( preg_match( '/Windows/i', $ua ) ) {
            $device = 'Windows';
        } elseif ( preg_match( '/Linux/i', $ua ) ) {
            $device = 'Linux';
        }

        return array( $browser, $device );
    }

    /**
     * 根据 IP 查询国家（免费接口 ip-api.com，失败时静默返回空，不影响主流程）
     * 放在异步邮件任务里调用，不阻塞前台提交响应。
     */
    private function lookup_country_by_ip( $ip, $timeout = 3 ) {
        if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return array( '', '' );
        }

        $response = wp_remote_get(
            'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,countryCode&lang=zh-CN',
            array( 'timeout' => $timeout )
        );

        if ( is_wp_error( $response ) ) {
            return array( '', '' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || 'success' !== ( $body['status'] ?? '' ) ) {
            return array( '', '' );
        }

        return array(
            isset( $body['country'] ) ? sanitize_text_field( $body['country'] ) : '',
            isset( $body['countryCode'] ) ? sanitize_text_field( $body['countryCode'] ) : '',
        );
    }

    /**
     * 国家代码（如 CN、US）转对应的旗帜 Emoji，纯前端展示用
     */
    private function country_code_to_flag( $country_code ) {
        $country_code = strtoupper( trim( (string) $country_code ) );
        if ( 2 !== strlen( $country_code ) ) {
            return '';
        }
        $flag = '';
        foreach ( str_split( $country_code ) as $char ) {
            $flag .= mb_chr( ord( $char ) - 65 + 0x1F1E6, 'UTF-8' );
        }
        return $flag;
    }

    /* ---------------------- 前台资源 ---------------------- */

    public function enqueue_frontend_assets() {
        wp_register_style( 'cif-style', false );
        wp_enqueue_style( 'cif-style' );
        wp_add_inline_style( 'cif-style', $this->get_inline_css() );

        wp_register_script( 'cif-script', false, array(), '2.0.0', true );
        wp_enqueue_script( 'cif-script' );
        wp_add_inline_script( 'cif-script', $this->get_inline_js() );

        $recaptcha = $this->get_recaptcha_settings();
        $recaptcha_active = $this->is_recaptcha_active();

        if ( $recaptcha_active ) {
            // v2 改用 explicit render（onload=cifRecaptchaOnload&render=explicit），
            // 由我们自己的回调手动渲染 widget，不再依赖 Google 默认的"自动扫描全页面 .g-recaptcha"，
            // 避免跟站内其它脚本产生渲染冲突（见 cifRecaptchaOnload 的说明）
            $api_src = 'v3' === $recaptcha['version']
                ? 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $recaptcha['site_key'] )
                : 'https://www.google.com/recaptcha/api.js?onload=cifRecaptchaOnload&render=explicit';
            wp_register_script( 'cif-recaptcha-api', $api_src, array(), null, true );
            wp_enqueue_script( 'cif-recaptcha-api' );
        }

        wp_localize_script(
            'cif-script',
            'CIF_DATA',
            array(
                'ajax_url'  => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
                'recaptcha' => array(
                    'active'   => $recaptcha_active,
                    'version'  => $recaptcha['version'],
                    'site_key' => $recaptcha_active ? $recaptcha['site_key'] : '',
                ),
            )
        );
    }

    /* ---------------------- 前台：渲染表单 ---------------------- */

    public function render_form() {
        $fields = $this->get_fields_config();

        if ( empty( $fields ) ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p>（还没有配置任何字段，请到"询盘表单 → 字段管理"里添加。）</p>';
            }
            return '';
        }

        ob_start();
        ?>
        <div class="cif-form-wrapper">
            <div class="cif-alert cif-alert-success" style="display:none;"></div>
            <div class="cif-alert cif-alert-error" style="display:none;"></div>

            <form id="cif-inquiry-form" novalidate>
                <?php foreach ( $fields as $field ) : ?>
                    <?php $this->render_field( $field ); ?>
                <?php endforeach; ?>

                <!-- 蜜罐字段，简单防垃圾提交 -->
                <div class="cif-honeypot" aria-hidden="true">
                    <label for="cif-website">Website</label>
                    <input type="text" id="cif-website" name="website" tabindex="-1" autocomplete="off" />
                </div>

                <?php if ( $this->is_recaptcha_active() ) : ?>
                    <?php $rc = $this->get_recaptcha_settings(); ?>
                    <?php if ( 'v2' === $rc['version'] ) : ?>
                        <div class="cif-field cif-recaptcha-field">
                            <!-- 注意：这里故意不用通用的 "g-recaptcha" class，改用插件自己独有的 class + id，
                                 并且用 explicit render（见下方 JS 的 cifRecaptchaOnload）手动渲染、
                                 自己记录 widget id。避免网站上其它脚本（主题/其它插件）也在扫描通用的
                                 .g-recaptcha 元素时抢着渲染，导致 "reCAPTCHA has already been rendered"
                                 报错、widget 状态混乱、重置失效的问题 -->
                            <div class="cif-g-recaptcha" id="cif-recaptcha-widget" data-sitekey="<?php echo esc_attr( $rc['site_key'] ); ?>"></div>
                        </div>
                    <?php else : ?>
                        <input type="hidden" name="cif_recaptcha_token" class="cif-recaptcha-token" value="" />
                    <?php endif; ?>
                <?php endif; ?>

                <button type="submit" class="cif-submit-btn">
                    <span class="cif-btn-text">Send</span>
                    <span class="cif-spinner" style="display:none;"></span>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_field( $field ) {
        $key         = esc_attr( $field['key'] );
        $label       = esc_html( $field['label'] );
        $type        = $field['type'];
        $required    = ! empty( $field['required'] );
        $placeholder = esc_attr( $field['placeholder'] );
        $field_id    = 'cif-' . $key;
        ?>
        <div class="cif-field cif-field-<?php echo esc_attr( $type ); ?>">
            <label for="<?php echo esc_attr( $field_id ); ?>">
                <?php echo $label; ?>
                <?php if ( $required ) : ?><span class="cif-required">*</span><?php endif; ?>
            </label>

            <?php if ( 'textarea' === $type ) : ?>
                <textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo $key; ?>" rows="5"
                    placeholder="<?php echo $placeholder; ?>" <?php echo $required ? 'required' : ''; ?>></textarea>

            <?php elseif ( 'select' === $type ) : ?>
                <select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo $key; ?>" <?php echo $required ? 'required' : ''; ?>>
                    <option value="">请选择</option>
                    <?php foreach ( $this->parse_options( $field['options'] ) as $opt ) : ?>
                        <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ( 'radio' === $type ) : ?>
                <div class="cif-choice-group">
                    <?php foreach ( $this->parse_options( $field['options'] ) as $idx => $opt ) : ?>
                        <label class="cif-choice-label">
                            <input type="radio" name="<?php echo $key; ?>" value="<?php echo esc_attr( $opt ); ?>"
                                <?php echo ( $required && 0 === $idx ) ? 'required' : ''; ?> />
                            <?php echo esc_html( $opt ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

            <?php elseif ( 'checkbox' === $type ) : ?>
                <div class="cif-choice-group">
                    <?php foreach ( $this->parse_options( $field['options'] ) as $opt ) : ?>
                        <label class="cif-choice-label">
                            <input type="checkbox" name="<?php echo $key; ?>[]" value="<?php echo esc_attr( $opt ); ?>" />
                            <?php echo esc_html( $opt ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

            <?php else : ?>
                <input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $field_id ); ?>"
                    name="<?php echo $key; ?>" placeholder="<?php echo $placeholder; ?>"
                    <?php echo $required ? 'required' : ''; ?> />
            <?php endif; ?>
        </div>
        <?php
    }

    private function parse_options( $options_string ) {
        if ( empty( $options_string ) ) {
            return array();
        }
        $opts = array_map( 'trim', explode( ',', $options_string ) );
        return array_filter( $opts, function( $v ) { return '' !== $v; } );
    }

    private function get_inline_css() {
        return "
            .cif-form-wrapper { max-width: 700px; }
            .cif-field { margin-bottom: 18px; }
            .cif-field label { display: block; margin-bottom: 6px; font-weight: 500; }
            .cif-required { color: #e02b2b; }
            .cif-field input[type=text],
            .cif-field input[type=email],
            .cif-field input[type=tel],
            .cif-field input[type=number],
            .cif-field input[type=date],
            .cif-field select,
            .cif-field textarea {
                width: 100%; padding: 12px 14px; background: #f4f5f7;
                border: 1px solid #dcdfe4; border-radius: 4px; font-size: 15px; box-sizing: border-box;
            }
            .cif-field textarea { resize: vertical; }
            .cif-choice-group { display: flex; flex-wrap: wrap; gap: 14px; }
            .cif-choice-label { font-weight: 400; display: inline-flex; align-items: center; gap: 6px; }
            .cif-honeypot { position: absolute; left: -9999px; top: -9999px; }
            .cif-submit-btn {
                background: #1b4fd6; color: #fff; border: none; padding: 12px 28px;
                font-size: 15px; border-radius: 4px; cursor: pointer; display: inline-flex;
                align-items: center; gap: 8px;
            }
            .cif-submit-btn:disabled { opacity: 0.7; cursor: not-allowed; }
            .cif-spinner {
                width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.5);
                border-top-color: #fff; border-radius: 50%; display: inline-block;
                animation: cif-spin 0.6s linear infinite;
            }
            @keyframes cif-spin { to { transform: rotate(360deg); } }
            .cif-alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 18px; font-size: 14px; }
            .cif-alert-success { background: #e6f4ea; color: #1e7e34; border: 1px solid #b7e1c1; }
            .cif-alert-error { background: #fdeaea; color: #b31212; border: 1px solid #f3c1c1; }
        ";
    }

    private function get_inline_js() {
        return <<<'JS'
        // v2 用 explicit render：Google 的 recaptcha 脚本加载完成后，会自己调用这个全局函数
        // （脚本 URL 里带的 onload=cifRecaptchaOnload 参数指定的就是它）。
        // 我们在这里手动渲染 widget，并把返回的 widget id 记下来，reset 的时候精确指定这个 id，
        // 不会跟页面上其它脚本（比如主题或别的插件也监听 .g-recaptcha）互相干扰。
        window.cifRecaptchaWidgetId = null;
        window.cifRecaptchaOnload = function () {
            var el = document.getElementById('cif-recaptcha-widget');
            if (!el || typeof grecaptcha === 'undefined' || typeof grecaptcha.render !== 'function') {
                return;
            }
            try {
                window.cifRecaptchaWidgetId = grecaptcha.render(el, {
                    sitekey: el.getAttribute('data-sitekey')
                });
            } catch (err) {
                if (window.console && console.error) {
                    console.error('[cif] grecaptcha.render() 失败:', err);
                }
            }
        };

        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('cif-inquiry-form');
            if (!form) return;

            var successBox = document.querySelector('.cif-alert-success');
            var errorBox = document.querySelector('.cif-alert-error');
            var submitBtn = form.querySelector('.cif-submit-btn');
            var btnText = form.querySelector('.cif-btn-text');
            var spinner = form.querySelector('.cif-spinner');
            var recaptcha = (typeof CIF_DATA !== 'undefined' && CIF_DATA.recaptcha) ? CIF_DATA.recaptcha : { active: false };

            function resetRecaptchaWidget() {
                // v2 的令牌是一次性的，用过一次后必须重置勾选框，否则界面上一直显示"已完成"，
                // 但下次提交时后台用旧令牌去校验一定会失败，访客会看不懂为什么又提交不了
                // 这一步必须绝对可靠地执行——哪怕后面别的 DOM 操作报错，也不能连带把它卡住，
                // 所以单独包一层 try/catch，并且放在最前面第一件事就做。
                try {
                    if (recaptcha.active && recaptcha.version === 'v2' && typeof grecaptcha !== 'undefined' && typeof grecaptcha.reset === 'function') {
                        if (window.cifRecaptchaWidgetId !== null) {
                            grecaptcha.reset(window.cifRecaptchaWidgetId);
                        } else {
                            grecaptcha.reset();
                        }
                    }
                } catch (err) {
                    if (window.console && console.error) {
                        console.error('[cif] grecaptcha.reset() 失败:', err);
                    }
                }
            }

            function resetSubmitButton() {
                try {
                    submitBtn.disabled = false;
                    btnText.textContent = 'Send';
                    spinner.style.display = 'none';
                } catch (err) {
                    if (window.console && console.error) {
                        console.error('[cif] 重置提交按钮状态失败:', err);
                    }
                }
            }

            function doSubmit() {
                var formData = new FormData(form);
                formData.append('action', 'cif_submit');
                formData.append('nonce', CIF_DATA.nonce);

                fetch(CIF_DATA.ajax_url, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    // 无论下面的成功/失败提示渲染是否出错，reCAPTCHA 重置和按钮恢复都必须先执行，
                    // 这样访客才能立刻进行下一次提交，不需要刷新页面。
                    resetRecaptchaWidget();
                    resetSubmitButton();

                    if (data && data.success) {
                        successBox.textContent = (data.data && data.data.message) || 'Thank you! Your message has been sent.';
                        successBox.style.display = 'block';
                        form.reset();
                    } else {
                        errorBox.textContent = (data && data.data && data.data.message) || 'Something went wrong, please try again.';
                        errorBox.style.display = 'block';
                    }
                })
                .catch(function (err) {
                    resetRecaptchaWidget();
                    resetSubmitButton();
                    if (window.console && console.error) {
                        console.error('[cif] 表单提交失败:', err);
                    }
                    errorBox.textContent = 'Network error, please try again.';
                    errorBox.style.display = 'block';
                });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                successBox.style.display = 'none';
                errorBox.style.display = 'none';

                submitBtn.disabled = true;
                btnText.textContent = 'Sending...';
                spinner.style.display = 'inline-block';

                // reCAPTCHA v3 是无感知的，提交时才现场生成令牌（令牌有效期短，不能提前生成）
                if (recaptcha.active && recaptcha.version === 'v3' && typeof grecaptcha !== 'undefined') {
                    grecaptcha.ready(function () {
                        grecaptcha.execute(recaptcha.site_key, { action: 'cif_submit' }).then(function (token) {
                            var tokenInput = form.querySelector('.cif-recaptcha-token');
                            if (tokenInput) { tokenInput.value = token; }
                            doSubmit();
                        }).catch(function () {
                            doSubmit();
                        });
                    });
                } else {
                    doSubmit();
                }
            });
        });
        JS;
    }

    /* ---------------------- 处理提交 ---------------------- */

    public function handle_submit() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! empty( $_POST['website'] ) ) {
            // 蜜罐字段被填，静默假装成功
            wp_send_json_success( array( 'message' => 'Thank you for your message. We will get in touch with you shortly.' ) );
        }

        $ip = $this->get_client_ip();

        if ( $this->is_recaptcha_active() ) {
            $rc_settings = $this->get_recaptcha_settings();
            $token_field = 'v3' === $rc_settings['version'] ? 'cif_recaptcha_token' : 'g-recaptcha-response';
            $token       = isset( $_POST[ $token_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $token_field ] ) ) : '';

            $recaptcha_error = $this->verify_recaptcha_token( $token, $ip );
            if ( '' !== $recaptcha_error ) {
                wp_send_json_error( array( 'message' => $recaptcha_error ) );
            }
        }

        $fields = $this->get_fields_config();
        $data   = array();

        foreach ( $fields as $field ) {
            $key   = $field['key'];
            $type  = $field['type'];
            $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

            if ( ! empty( $field['required'] ) ) {
                $is_empty = is_array( $value ) ? empty( $value ) : ( '' === trim( (string) $value ) );
                if ( $is_empty ) {
                    /* translators: %s: field label */
                    wp_send_json_error( array( 'message' => sprintf( '请填写"%s"。', $field['label'] ) ) );
                }
            }

            if ( 'email' === $type && ! empty( $value ) && ! is_email( $value ) ) {
                wp_send_json_error( array( 'message' => '请输入有效的邮箱地址。' ) );
            }

            if ( is_array( $value ) ) {
                $value = array_map( 'sanitize_text_field', $value );
                $value = implode( ', ', $value );
            } elseif ( 'textarea' === $type ) {
                $value = sanitize_textarea_field( $value );
            } else {
                $value = sanitize_text_field( $value );
            }

            $data[ $field['label'] ] = $value;
        }

        $ua                    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        list( $browser, $device ) = $this->parse_user_agent( $ua );
        $source_url            = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
        $submitted_by          = is_user_logged_in() ? wp_get_current_user()->user_login : '访客';

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // 黑名单检测（邮箱 / 关键词 / IP）：命中则记录为"已拦截"，不发邮件通知，
        // 但前台仍返回"提交成功"，避免让垃圾发送者察觉自己被拦截后立刻变换手法重试
        $block_reason = $this->get_blacklist_match_reason( $ip, $data );
        if ( '' !== $block_reason ) {
            $wpdb->insert(
                $table,
                array(
                    'data'         => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
                    'status'       => 'blocked',
                    'ip'           => $ip,
                    'browser'      => $browser,
                    'device'       => $device,
                    'user_agent'   => mb_substr( $ua, 0, 250 ),
                    'source_url'   => mb_substr( $source_url, 0, 490 ),
                    'submitted_by' => $submitted_by,
                    'block_reason' => mb_substr( $block_reason, 0, 250 ),
                    'created_at'   => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
            wp_send_json_success( array( 'message' => 'Thank you for your message. We will get in touch with you shortly.' ) );
        }

        // 国家查询：先同步试一次（超时压到1.5秒，查不到也不阻塞提交），失败了异步邮件任务里还会再兜底重试一次
        list( $country, $country_code ) = $this->lookup_country_by_ip( $ip, 1.5 );

        $inserted = $wpdb->insert(
            $table,
            array(
                'data'         => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
                'status'       => 'new',
                'ip'           => $ip,
                'country'      => $country,
                'country_code' => $country_code,
                'browser'      => $browser,
                'device'       => $device,
                'user_agent'   => mb_substr( $ua, 0, 250 ),
                'source_url'   => mb_substr( $source_url, 0, 490 ),
                'submitted_by' => $submitted_by,
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            wp_send_json_error( array( 'message' => '提交失败，请稍后重试。' ) );
        }

        $submission_id = $wpdb->insert_id;

        wp_schedule_single_event( time(), self::CRON_HOOK, array( $submission_id ) );
        $this->spawn_cron_now();

        wp_send_json_success( array( 'message' => 'Thank you for your message. We will get in touch with you shortly.' ) );
    }

    private function spawn_cron_now() {
        $url = site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) );
        wp_remote_post(
            $url,
            array(
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            )
        );
    }

    /* ---------------------- 异步发送邮件 ---------------------- */

    public function send_notification_email( $submission_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $submission_id ) );
        if ( ! $row ) {
            return;
        }

        // 国家查询走外部接口，放在异步任务里做，不拖慢前台提交响应
        if ( empty( $row->country ) && ! empty( $row->ip ) ) {
            list( $country, $country_code ) = $this->lookup_country_by_ip( $row->ip );
            if ( $country ) {
                $wpdb->update(
                    $table,
                    array( 'country' => $country, 'country_code' => $country_code ),
                    array( 'id' => $submission_id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
                $row->country      = $country;
                $row->country_code = $country_code;
            }
        }

        $data     = json_decode( $row->data, true );
        $settings = $this->get_settings();
        $to       = ! empty( $settings['to_email'] ) ? $settings['to_email'] : get_option( 'admin_email' );
        $subject  = ! empty( $settings['subject'] ) ? $settings['subject'] : '[新询盘] 网站表单提交通知';

        $body  = "有一条新的留言/询盘：\n\n";
        if ( is_array( $data ) ) {
            foreach ( $data as $label => $value ) {
                $body .= "{$label}：{$value}\n";
            }
        }
        if ( ! empty( $row->country ) ) {
            $body .= "国家/地区：{$row->country}\n";
        }
        if ( ! empty( $row->ip ) ) {
            $body .= "IP：{$row->ip}\n";
        }
        $body .= "\n提交时间：" . $row->created_at;

        $headers = array();
        // 如果表单里有一个字段的 label 恰好叫 Email，尝试用它设置回复地址
        if ( is_array( $data ) ) {
            foreach ( $data as $label => $value ) {
                if ( false !== stripos( $label, 'email' ) && is_email( $value ) ) {
                    $headers[] = 'Reply-To: ' . $value;
                    break;
                }
            }
        }

        wp_mail( $to, $subject, $body, $headers );
    }

    /* ---------------------- 后台菜单 ---------------------- */

    private function get_unread_count() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
    }

    private function menu_badge_html( $count ) {
        if ( $count <= 0 ) {
            return '';
        }
        return ' <span class="cif-unread-badge" style="display:inline-block;min-width:18px;height:18px;line-height:18px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;text-align:center;margin-left:4px;">' . intval( $count ) . '</span>';
    }

    public function register_admin_menu() {
        $unread = $this->get_unread_count();

        add_menu_page(
            '询盘表单',
            '询盘表单' . $this->menu_badge_html( $unread ),
            'manage_options',
            'custom-inquiry-form',
            array( $this, 'render_submissions_page' ),
            'dashicons-email-alt',
            26
        );

        add_submenu_page( 'custom-inquiry-form', '提交记录', '提交记录' . $this->menu_badge_html( $unread ), 'manage_options', 'custom-inquiry-form', array( $this, 'render_submissions_page' ) );
        add_submenu_page( 'custom-inquiry-form', '字段管理', '字段管理', 'manage_options', 'custom-inquiry-form-fields', array( $this, 'render_fields_page' ) );
        add_submenu_page( 'custom-inquiry-form', '黑名单', '黑名单', 'manage_options', 'custom-inquiry-form-blacklist', array( $this, 'render_blacklist_page' ) );
        add_submenu_page( 'custom-inquiry-form', 'reCAPTCHA设置', 'reCAPTCHA设置', 'manage_options', 'custom-inquiry-form-recaptcha', array( $this, 'render_recaptcha_page' ) );
        add_submenu_page( 'custom-inquiry-form', '设置', '设置', 'manage_options', 'custom-inquiry-form-settings', array( $this, 'render_settings_page' ) );
    }

    /**
     * 在所有后台页面加载一小段轮询脚本，实时更新菜单未读角标（不用刷新页面）
     */
    public function enqueue_admin_assets( $hook ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_register_script( 'cif-admin-badge', false, array(), self::DB_VERSION, true );
        wp_enqueue_script( 'cif-admin-badge' );
        wp_localize_script(
            'cif-admin-badge',
            'CIF_ADMIN',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::ADMIN_AJAX_NONCE ),
            )
        );
        wp_add_inline_script( 'cif-admin-badge', $this->get_admin_badge_js() );

        if ( strpos( $hook, 'custom-inquiry-form-fields' ) !== false ) {
            wp_enqueue_script( 'jquery' );
        }
    }

    private function get_admin_badge_js() {
        return <<<'JS'
        (function () {
            function updateBadge(count) {
                var links = document.querySelectorAll('#adminmenu a[href*="page=custom-inquiry-form"]');
                links.forEach(function (link) {
                    var href = link.getAttribute('href');
                    // 只精确匹配 page=custom-inquiry-form 本身（主菜单 + "提交记录"子菜单共用这个slug）。
                    // 其它子菜单的 slug 都是 custom-inquiry-form-xxx（带连字符），一律排除，
                    // 这样以后再加新的子菜单页也不用记得回来改这里。
                    if ( href.indexOf( 'page=custom-inquiry-form-' ) !== -1 ) {
                        return;
                    }
                    var badge = link.querySelector('.cif-unread-badge');
                    if (count > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'cif-unread-badge';
                            badge.style.cssText = 'display:inline-block;min-width:18px;height:18px;line-height:18px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;text-align:center;margin-left:4px;';
                            link.appendChild(badge);
                        }
                        badge.textContent = count;
                    } else if (badge) {
                        badge.remove();
                    }
                });
                document.title = document.title.replace(/^\(\d+\)\s*/, '');
                if (count > 0) {
                    document.title = '(' + count + ') ' + document.title;
                }
            }

            function pollBadgeOnly() {
                var body = new FormData();
                body.append('action', 'cif_get_unread_count');
                body.append('nonce', CIF_ADMIN.nonce);
                fetch(CIF_ADMIN.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            updateBadge(data.data.count);
                        }
                    })
                    .catch(function () {});
            }

            function pollSubmissionsList(tbody) {
                var status = tbody.getAttribute('data-status') || 'all';
                var body = new FormData();
                body.append('action', 'cif_get_submissions_html');
                body.append('nonce', CIF_ADMIN.nonce);
                body.append('status', status);
                fetch(CIF_ADMIN.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.success) { return; }
                        tbody.innerHTML = data.data.rows_html;
                        updateBadge(data.data.counts.unread);

                        var allEl = document.getElementById('cif-count-all');
                        var unreadEl = document.getElementById('cif-count-unread');
                        var readEl = document.getElementById('cif-count-read');
                        var blockedEl = document.getElementById('cif-count-blocked');
                        if (allEl) { allEl.textContent = '(' + data.data.counts.all + ')'; }
                        if (unreadEl) { unreadEl.textContent = '(' + data.data.counts.unread + ')'; }
                        if (readEl) { readEl.textContent = '(' + data.data.counts.read + ')'; }
                        if (blockedEl) { blockedEl.textContent = '(' + data.data.counts.blocked + ')'; }
                    })
                    .catch(function () {});
            }

            function poll() {
                var tbody = document.getElementById('cif-submissions-tbody');
                if (tbody) {
                    pollSubmissionsList(tbody);
                } else {
                    pollBadgeOnly();
                }
            }

            poll();
            setInterval(poll, 15000);
        })();
        JS;
    }

    public function ajax_get_unread_count() {
        check_ajax_referer( self::ADMIN_AJAX_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        wp_send_json_success( array( 'count' => $this->get_unread_count() ) );
    }

    public function ajax_toggle_status() {
        check_ajax_referer( self::ADMIN_AJAX_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $status = isset( $_POST['status'] ) && 'read' === $_POST['status'] ? 'read' : 'new';

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

        wp_send_json_success( array( 'count' => $this->get_unread_count() ) );
    }

    public function ajax_mark_all_read() {
        check_ajax_referer( self::ADMIN_AJAX_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $wpdb->query( "UPDATE {$table} SET status = 'read' WHERE status = 'new'" );

        wp_send_json_success( array( 'count' => 0 ) );
    }

    /* ---------------------- 字段管理页面 ---------------------- */

    public function render_fields_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $fields = $this->get_fields_config();
        ?>
        <div class="wrap">
            <h1>字段管理</h1>
            <p>在这里自由添加/删除/编辑表单字段，跟 Fluent Forms 一样自由搭建。保存后前台短代码 <code>[custom_inquiry_form]</code> 会自动按这里的配置显示。</p>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success"><p>字段已保存。</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cif_save_fields_action' ); ?>
                <input type="hidden" name="action" value="cif_save_fields" />

                <table class="widefat" id="cif-fields-table">
                    <thead>
                        <tr>
                            <th style="width:18%;">字段名称（显示标签）</th>
                            <th style="width:14%;">类型</th>
                            <th style="width:8%;">必填</th>
                            <th style="width:24%;">占位提示文字</th>
                            <th style="width:24%;">选项（仅下拉/单选/多选需要，逗号分隔）</th>
                            <th style="width:12%;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="cif-fields-tbody">
                        <?php foreach ( $fields as $field ) : ?>
                            <?php $this->render_field_row( $field ); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="cif-add-field">+ 添加字段</button>
                </p>

                <?php submit_button( '保存字段设置' ); ?>
            </form>
        </div>

        <template id="cif-field-row-template">
            <?php $this->render_field_row( array( 'key' => '', 'label' => '', 'type' => 'text', 'required' => 0, 'placeholder' => '', 'options' => '' ) ); ?>
        </template>

        <script>
        (function () {
            document.getElementById('cif-add-field').addEventListener('click', function () {
                var template = document.getElementById('cif-field-row-template');
                var clone = template.content.cloneNode(true);
                document.getElementById('cif-fields-tbody').appendChild(clone);
            });

            document.getElementById('cif-fields-tbody').addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('cif-remove-field')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
        </script>
        <?php
    }

    private function render_field_row( $field ) {
        $key         = esc_attr( $field['key'] );
        $label       = esc_attr( $field['label'] );
        $type        = $field['type'];
        $required    = ! empty( $field['required'] );
        $placeholder = esc_attr( $field['placeholder'] );
        $options     = esc_attr( $field['options'] );
        ?>
        <tr>
            <td>
                <input type="hidden" name="field_key[]" value="<?php echo $key; ?>" />
                <input type="text" name="field_label[]" value="<?php echo $label; ?>" class="regular-text" placeholder="例如：Company Name" />
            </td>
            <td>
                <select name="field_type[]">
                    <?php foreach ( $this->supported_types as $type_key => $type_label ) : ?>
                        <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type, $type_key ); ?>>
                            <?php echo esc_html( $type_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td style="text-align:center;">
                <input type="checkbox" name="field_required[]" value="1" <?php checked( $required ); ?> />
            </td>
            <td>
                <input type="text" name="field_placeholder[]" value="<?php echo $placeholder; ?>" class="regular-text" />
            </td>
            <td>
                <input type="text" name="field_options[]" value="<?php echo $options; ?>" class="regular-text"
                       placeholder="选项1,选项2,选项3" />
            </td>
            <td>
                <button type="button" class="button cif-remove-field">删除</button>
            </td>
        </tr>
        <?php
    }

    /* ---------------------- 黑名单管理页面 ---------------------- */

    public function render_blacklist_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page_url = admin_url( 'admin.php?page=custom-inquiry-form-blacklist' );

        // 处理"移除单条黑名单"的请求（列表里每一条后面的"移除"链接）
        if ( isset( $_GET['bl_action'], $_GET['bl_type'], $_GET['bl_value'], $_GET['_wpnonce'] )
            && 'remove' === $_GET['bl_action']
        ) {
            $type  = sanitize_key( wp_unslash( $_GET['bl_type'] ) );
            $value = sanitize_text_field( wp_unslash( $_GET['bl_value'] ) );

            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cif_bl_remove_' . $type . '_' . md5( $value ) )
                && in_array( $type, array( 'emails', 'keywords', 'ips' ), true )
            ) {
                $bl    = $this->get_blacklist_settings();
                $lines = $this->parse_blacklist_lines( $bl[ $type ] );
                $lines = array_filter( $lines, function( $v ) use ( $value ) { return $v !== $value; } );
                $bl[ $type ] = implode( "\n", $lines );
                update_option( self::BLACKLIST_OPTION, $this->sanitize_blacklist_settings( $bl ) );
                echo '<div class="notice notice-success is-dismissible"><p>已从黑名单移除。</p></div>';
            }
        }

        $bl = $this->get_blacklist_settings();
        ?>
        <div class="wrap">
            <h1>询盘表单 - 黑名单</h1>
            <p class="description">命中黑名单的提交不会出现在"新留言"里、也不会发送邮件通知；但会记录到提交记录的"已拦截"分类中，方便你复查是否误拦截。访客端仍会看到"提交成功"的提示。</p>

            <h2 style="margin-top:28px;">当前黑名单列表</h2>
            <p class="description">下面是目前已生效的黑名单条目，点击每条后面的"移除"即可单独删除。</p>
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:10px;">
                <?php $this->render_blacklist_chip_group( 'emails', '邮箱', $page_url ); ?>
                <?php $this->render_blacklist_chip_group( 'keywords', '关键词', $page_url ); ?>
                <?php $this->render_blacklist_chip_group( 'ips', 'IP', $page_url ); ?>
            </div>

            <h2 style="margin-top:32px;">批量编辑</h2>
            <p class="description">如果要一次性粘贴导入很多条，或者想直接改文本，可以在这里批量编辑（每行一条，保存后上面的列表会同步更新）。</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'cif_blacklist_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cif-bl-emails">邮箱黑名单</label></th>
                        <td>
                            <textarea id="cif-bl-emails" name="<?php echo esc_attr( self::BLACKLIST_OPTION ); ?>[emails]"
                                rows="6" class="large-text code" placeholder="spam@example.com&#10;@baddomain.com"><?php echo esc_textarea( $bl['emails'] ); ?></textarea>
                            <p class="description">每行一条。可以填完整邮箱地址（如 spam@example.com），也可以填 @域名 拦截整个域名（如 @baddomain.com）。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cif-bl-keywords">关键词黑名单</label></th>
                        <td>
                            <textarea id="cif-bl-keywords" name="<?php echo esc_attr( self::BLACKLIST_OPTION ); ?>[keywords]"
                                rows="6" class="large-text code" placeholder="viagra&#10;seo优化&#10;赌博"><?php echo esc_textarea( $bl['keywords'] ); ?></textarea>
                            <p class="description">每行一条。只要提交内容中任意字段包含该关键词（不区分大小写）即会被拦截。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cif-bl-ips">IP黑名单</label></th>
                        <td>
                            <textarea id="cif-bl-ips" name="<?php echo esc_attr( self::BLACKLIST_OPTION ); ?>[ips]"
                                rows="6" class="large-text code" placeholder="1.2.3.4&#10;5.6.7.*"><?php echo esc_textarea( $bl['ips'] ); ?></textarea>
                            <p class="description">每行一条。支持精确 IP，也支持前缀通配符（如 5.6.7.* 会拦截 5.6.7.0～5.6.7.255 这一段）。</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( '保存黑名单' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * 渲染某一类黑名单（邮箱/关键词/IP）的可视化列表卡片，每条带单独的"移除"链接
     */
    private function render_blacklist_chip_group( $type, $label, $page_url ) {
        $bl      = $this->get_blacklist_settings();
        $entries = $this->parse_blacklist_lines( $bl[ $type ] );
        ?>
        <div style="flex:1;min-width:260px;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;">
            <h3 style="margin-top:0;"><?php echo esc_html( $label ); ?>黑名单 <span style="color:#8a8a8a;font-weight:400;">(<?php echo count( $entries ); ?>)</span></h3>
            <?php if ( empty( $entries ) ) : ?>
                <p style="color:#8a8a8a;">暂无。</p>
            <?php else : ?>
                <ul style="margin:0;padding:0;list-style:none;">
                    <?php foreach ( $entries as $entry ) : ?>
                        <?php
                        $remove_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'bl_action' => 'remove',
                                    'bl_type'   => $type,
                                    'bl_value'  => rawurlencode( $entry ),
                                ),
                                $page_url
                            ),
                            'cif_bl_remove_' . $type . '_' . md5( $entry )
                        );
                        ?>
                        <li style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f1;">
                            <span style="word-break:break-all;"><?php echo esc_html( $entry ); ?></span>
                            <a href="<?php echo esc_url( $remove_url ); ?>" style="color:#d63638;font-size:12px;white-space:nowrap;margin-left:10px;" onclick="return confirm('确定要将「<?php echo esc_js( $entry ); ?>」从黑名单移除吗？');">移除</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 提交详情页里"拉黑此IP / 拉黑此邮箱"按钮的处理逻辑
     */
    public function handle_blacklist_quick_add() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '没有权限。' );
        }
        check_admin_referer( 'cif_blacklist_quick_add' );

        $type  = isset( $_POST['bl_type'] ) ? sanitize_key( $_POST['bl_type'] ) : '';
        $value = isset( $_POST['bl_value'] ) ? sanitize_text_field( wp_unslash( $_POST['bl_value'] ) ) : '';
        $back  = isset( $_POST['bl_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['bl_redirect'] ) ) : admin_url( 'admin.php?page=custom-inquiry-form' );

        if ( '' !== $value && in_array( $type, array( 'email', 'ip' ), true ) ) {
            $bl  = $this->get_blacklist_settings();
            $key = 'email' === $type ? 'emails' : 'ips';

            $lines = $this->parse_blacklist_lines( $bl[ $key ] );
            if ( ! in_array( strtolower( $value ), array_map( 'strtolower', $lines ), true ) ) {
                $lines[]     = $value;
                $bl[ $key ]  = implode( "\n", $lines );
                $sanitized   = $this->sanitize_blacklist_settings( $bl );
                update_option( self::BLACKLIST_OPTION, $sanitized );
                $back = add_query_arg( 'blacklisted', '1', $back );
            }
        }

        wp_safe_redirect( $back );
        exit;
    }

    /* ---------------------- reCAPTCHA 设置页面 ---------------------- */

    public function render_recaptcha_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $rc = $this->get_recaptcha_settings();
        ?>
        <div class="wrap">
            <h1>询盘表单 - reCAPTCHA 设置</h1>
            <p class="description">
                集成 Google reCAPTCHA，免费保护表单不被垃圾提交和滥用。只有填写了 Site Key 和 Secret Key 并勾选启用后才会在表单中生效。
                请前往 <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">reCAPTCHA 管理控制台</a> 生成密钥，
                了解更多请查看 <a href="http://www.google.com/recaptcha/" target="_blank" rel="noopener">reCAPTCHA 官方说明</a>。
            </p>
            <form method="post" action="options.php">
                <?php settings_fields( 'cif_recaptcha_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">启用 reCAPTCHA</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::RECAPTCHA_OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $rc['enabled'] ) ); ?> />
                                在表单提交时启用 reCAPTCHA 人机验证
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">reCAPTCHA 版本</th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="radio" name="<?php echo esc_attr( self::RECAPTCHA_OPTION ); ?>[version]" value="v2" <?php checked( 'v2', $rc['version'] ); ?> />
                                reCAPTCHA v2（访客需要勾选"我不是机器人"）
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="<?php echo esc_attr( self::RECAPTCHA_OPTION ); ?>[version]" value="v3" <?php checked( 'v3', $rc['version'] ); ?> />
                                reCAPTCHA v3（无感知，根据行为评分自动判断，不会打扰访客）
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cif-rc-site-key">Site Key</label></th>
                        <td>
                            <input type="text" id="cif-rc-site-key" name="<?php echo esc_attr( self::RECAPTCHA_OPTION ); ?>[site_key]"
                                value="<?php echo esc_attr( $rc['site_key'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cif-rc-secret-key">Secret Key</label></th>
                        <td>
                            <input type="text" id="cif-rc-secret-key" name="<?php echo esc_attr( self::RECAPTCHA_OPTION ); ?>[secret_key]"
                                value="<?php echo esc_attr( $rc['secret_key'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cif-rc-threshold">v3 通过分数阈值</label></th>
                        <td>
                            <input type="number" id="cif-rc-threshold" name="<?php echo esc_attr( self::RECAPTCHA_OPTION ); ?>[score_threshold]"
                                value="<?php echo esc_attr( $rc['score_threshold'] ); ?>" min="0" max="1" step="0.1" style="width:100px;" />
                            <p class="description">仅 v3 生效。Google 会给每次提交打 0～1 分（越接近1越像真人），低于此分数的提交将被拒绝。默认 0.5。</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( '保存 reCAPTCHA 设置' ); ?>
            </form>

            <hr />
            <h2>插件自动更新诊断</h2>
            <p class="description">如果后台迟迟不出现"有新版本"的提示，点这个按钮直接实时检查一次服务器能不能连上 GitHub、返回了什么，方便判断具体卡在哪一步。</p>
            <button type="button" class="button button-secondary" id="cif-update-diagnostic-btn">立即检查 GitHub 更新连通性</button>
            <div id="cif-update-diagnostic-result" style="margin-top:16px;max-width:800px;"></div>
            <script>
            (function () {
                var btn = document.getElementById('cif-update-diagnostic-btn');
                var resultBox = document.getElementById('cif-update-diagnostic-result');
                if (!btn || typeof CIF_ADMIN === 'undefined') { return; }

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    var originalText = btn.textContent;
                    btn.textContent = '检查中…';
                    resultBox.innerHTML = '';

                    var formData = new FormData();
                    formData.append('action', 'cif_debug_update_check');
                    formData.append('nonce', CIF_ADMIN.nonce);

                    fetch(CIF_ADMIN.ajax_url, { method: 'POST', body: formData, credentials: 'same-origin' })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            btn.disabled = false;
                            btn.textContent = originalText;
                            if (data && data.success) {
                                resultBox.innerHTML = data.data.html;
                            } else {
                                resultBox.innerHTML = '<p style="color:#b31212;">检查失败：' + ((data && data.data && data.data.message) || '未知错误') + '</p>';
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            btn.textContent = originalText;
                            resultBox.innerHTML = '<p style="color:#b31212;">请求出错，请重试。</p>';
                        });
                });
            })();
            </script>
        </div>
        <?php
    }

    /* ---------------------- 设置页面 ---------------------- */

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>询盘表单 - 设置</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'cif_settings_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="to_email">收件邮箱</label></th>
                        <td>
                            <input type="email" id="to_email" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[to_email]"
                                   value="<?php echo esc_attr( $settings['to_email'] ); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="subject">邮件主题</label></th>
                        <td>
                            <input type="text" id="subject" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[subject]"
                                   value="<?php echo esc_attr( $settings['subject'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
                <?php submit_button( '保存设置' ); ?>
            </form>
        </div>
        <?php
    }

    /* ---------------------- 提交记录页面 ---------------------- */

    public function render_submissions_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // 详情页路由
        if ( isset( $_GET['action'], $_GET['id'] ) && 'view' === $_GET['action'] ) {
            $this->render_submission_detail( intval( $_GET['id'] ) );
            return;
        }

        // 删除
        if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] )
            && 'delete' === $_GET['action']
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cif_delete_' . intval( $_GET['id'] ) )
        ) {
            $wpdb->delete( $table, array( 'id' => intval( $_GET['id'] ) ), array( '%d' ) );
            echo '<div class="notice notice-success"><p>已删除。</p></div>';
        }

        // 全部标记已读
        if ( isset( $_GET['action'], $_GET['_wpnonce'] )
            && 'mark_all_read' === $_GET['action']
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cif_mark_all_read' )
        ) {
            $wpdb->query( "UPDATE {$table} SET status = 'read' WHERE status = 'new'" );
            echo '<div class="notice notice-success"><p>已全部标记为已读。</p></div>';
        }

        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
        $counts        = $this->get_status_counts();
        $base_url      = admin_url( 'admin.php?page=custom-inquiry-form' );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                询盘表单 - 提交记录
                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'mark_all_read', $base_url ), 'cif_mark_all_read' ) ); ?>"
                   class="page-title-action">全部标记已读</a>
                <span id="cif-live-indicator" style="font-size:12px;font-weight:400;color:#787c82;">● 每15秒自动刷新</span>
            </h1>

            <ul class="subsubsub" id="cif-status-tabs">
                <li><a href="<?php echo esc_url( add_query_arg( 'status', 'all', $base_url ) ); ?>" <?php echo 'all' === $status_filter ? 'class="current"' : ''; ?>>全部 <span class="count" id="cif-count-all">(<?php echo intval( $counts['all'] ); ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( add_query_arg( 'status', 'unread', $base_url ) ); ?>" <?php echo 'unread' === $status_filter ? 'class="current"' : ''; ?>>未读 <span class="count" id="cif-count-unread">(<?php echo intval( $counts['unread'] ); ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( add_query_arg( 'status', 'read', $base_url ) ); ?>" <?php echo 'read' === $status_filter ? 'class="current"' : ''; ?>>已读 <span class="count" id="cif-count-read">(<?php echo intval( $counts['read'] ); ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( add_query_arg( 'status', 'blocked', $base_url ) ); ?>" <?php echo 'blocked' === $status_filter ? 'class="current"' : ''; ?>>已拦截 <span class="count" id="cif-count-blocked">(<?php echo intval( $counts['blocked'] ); ?>)</span></a></li>
            </ul>

            <table class="widefat striped" style="margin-top:10px;">
                <thead>
                <tr>
                    <th style="width:70px;">提交ID</th>
                    <th style="width:80px;">状态</th>
                    <th>摘要</th>
                    <th style="width:140px;">国家/地区</th>
                    <th style="width:110px;">浏览器/设备</th>
                    <th style="width:150px;">提交时间</th>
                    <th style="width:120px;">操作</th>
                </tr>
                </thead>
                <tbody id="cif-submissions-tbody" data-status="<?php echo esc_attr( $status_filter ); ?>">
                    <?php echo $this->render_submissions_rows_html( $status_filter ); // phpcs:ignore ?>
                </tbody>
            </table>
            <p class="description">最多显示最近 200 条记录。列表每 15 秒自动刷新一次，无需手动刷新页面。</p>
        </div>
        <?php
    }

    private function get_status_counts() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('new','read')" );
        $unread  = $this->get_unread_count();
        $blocked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'blocked'" );
        return array( 'all' => $total, 'unread' => $unread, 'read' => $total - $unread, 'blocked' => $blocked );
    }

    /**
     * 渲染提交记录表格的 <tr> 行，供页面首次加载和AJAX实时刷新共用
     */
    private function render_submissions_rows_html( $status_filter ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $where = "WHERE status IN ('new','read')";
        if ( 'unread' === $status_filter ) {
            $where = "WHERE status = 'new'";
        } elseif ( 'read' === $status_filter ) {
            $where = "WHERE status = 'read'";
        } elseif ( 'blocked' === $status_filter ) {
            $where = "WHERE status = 'blocked'";
        }

        $rows     = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT 200" );
        $base_url = admin_url( 'admin.php?page=custom-inquiry-form' );

        ob_start();

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="7">暂无提交记录。</td></tr>';
        } else {
            foreach ( $rows as $row ) {
                $decoded = json_decode( $row->data, true );
                $decoded = is_array( $decoded ) ? $decoded : array();
                $summary = '';
                foreach ( $decoded as $label => $value ) {
                    if ( '' !== trim( (string) $value ) ) {
                        $summary = $label . '：' . wp_trim_words( $value, 8 );
                        break;
                    }
                }
                $view_url   = add_query_arg( array( 'action' => 'view', 'id' => $row->id ), $base_url );
                $delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'id' => $row->id ), $base_url ), 'cif_delete_' . $row->id );
                $is_unread  = 'new' === $row->status;
                $is_blocked = 'blocked' === $row->status;
                $flag       = $this->country_code_to_flag( $row->country_code ?? '' );
                ?>
                <tr <?php echo $is_unread ? 'style="font-weight:600;background:#f6f9ff;"' : ''; ?>>
                    <td><a href="<?php echo esc_url( $view_url ); ?>">#<?php echo intval( $row->id ); ?></a></td>
                    <td>
                        <?php if ( $is_blocked ) : ?>
                            <span style="color:#d63638;" title="<?php echo esc_attr( $row->block_reason ?: '' ); ?>">已拦截</span>
                        <?php elseif ( $is_unread ) : ?>
                            <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#d63638;margin-right:5px;"></span>未读
                        <?php else : ?>
                            <span style="color:#8a8a8a;">已读</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $summary ?: '（无内容）' ); ?></a><?php echo $is_blocked && $row->block_reason ? '<br><span style="font-size:12px;color:#8a8a8a;">' . esc_html( $row->block_reason ) . '</span>' : ''; ?></td>
                    <td><?php echo $flag ? esc_html( $flag ) . ' ' : ''; ?><?php echo esc_html( $row->country ?: '未知' ); ?></td>
                    <td><?php echo esc_html( trim( ( $row->browser ?: '未知' ) . ' / ' . ( $row->device ?: '' ), ' /' ) ); ?></td>
                    <td><?php echo esc_html( $row->created_at ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( $view_url ); ?>">查看</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('确定要删除这条记录吗？');">删除</a>
                    </td>
                </tr>
                <?php
            }
        }

        return ob_get_clean();
    }

    public function ajax_get_submissions_html() {
        check_ajax_referer( self::ADMIN_AJAX_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $status_filter = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'all';
        $counts        = $this->get_status_counts();

        wp_send_json_success(
            array(
                'rows_html' => $this->render_submissions_rows_html( $status_filter ),
                'counts'    => $counts,
            )
        );
    }

    /* ---------------------- 提交详情页面 ---------------------- */

    private function render_submission_detail( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        $list_url = admin_url( 'admin.php?page=custom-inquiry-form' );

        if ( ! $row ) {
            echo '<div class="wrap"><p>记录不存在，或已被删除。</p><p><a href="' . esc_url( $list_url ) . '">&larr; 返回列表</a></p></div>';
            return;
        }

        // 手动强制重新识别国家（忽略已有缓存值），用于刷新老数据为中文国家名
        if ( isset( $_GET['refresh_country'], $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cif_refresh_country_' . $id )
        ) {
            list( $country, $country_code ) = $this->lookup_country_by_ip( $row->ip );
            if ( $country ) {
                $wpdb->update(
                    $table,
                    array( 'country' => $country, 'country_code' => $country_code ),
                    array( 'id' => $id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
                $row->country      = $country;
                $row->country_code = $country_code;
                echo '<div class="notice notice-success"><p>国家信息已刷新。</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>刷新失败，请稍后重试（可能是接口限流）。</p></div>';
            }
        }

        // 打开详情即标记为已读
        if ( 'new' === $row->status ) {
            $wpdb->update( $table, array( 'status' => 'read' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
            $row->status = 'read';
        }

        $decoded    = json_decode( $row->data, true );
        $decoded    = is_array( $decoded ) ? $decoded : array();
        $flag       = $this->country_code_to_flag( $row->country_code ?? '' );
        $delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'id' => $row->id ), $list_url ), 'cif_delete_' . $row->id );

        // 从本条提交数据里找出第一个邮箱字段，用于"拉黑此邮箱"按钮
        $submitted_email = '';
        foreach ( $decoded as $value ) {
            if ( is_email( (string) $value ) ) {
                $submitted_email = (string) $value;
                break;
            }
        }
        $status_labels = array( 'new' => '未读', 'read' => '已读', 'blocked' => '已拦截' );
        ?>
        <div class="wrap">
            <p><a href="<?php echo esc_url( $list_url ); ?>">&larr; 返回列表</a></p>
            <h1>提交详情 #<?php echo intval( $row->id ); ?></h1>

            <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;margin-top:10px;">
                <div style="flex:2;min-width:320px;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px;">
                    <?php if ( 'blocked' === $row->status ) : ?>
                        <div class="notice notice-error inline" style="margin:0 0 16px;"><p>这条提交命中了黑名单：<?php echo esc_html( $row->block_reason ?: '' ); ?>，未计入"新留言"，也未发送邮件通知。</p></div>
                    <?php endif; ?>
                    <h2 style="margin-top:0;">表单输入数据</h2>
                    <table class="widefat" style="border:none;">
                        <?php foreach ( $decoded as $label => $value ) : ?>
                            <tr>
                                <td style="width:160px;font-weight:600;vertical-align:top;border:none;padding:10px 0;"><?php echo esc_html( $label ); ?></td>
                                <td style="border:none;padding:10px 0;white-space:pre-wrap;"><?php echo esc_html( $value ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <div style="flex:1;min-width:280px;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px;">
                    <h2 style="margin-top:0;">提交信息</h2>
                    <table class="widefat" style="border:none;">
                        <tr><td style="border:none;padding:6px 0;color:#666;">提交ID</td><td style="border:none;padding:6px 0;">#<?php echo intval( $row->id ); ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;color:#666;">状态</td><td style="border:none;padding:6px 0;"><?php echo esc_html( $status_labels[ $row->status ] ?? $row->status ); ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;color:#666;">国家/地区</td><td style="border:none;padding:6px 0;">
                            <?php echo $flag ? esc_html( $flag ) . ' ' : ''; ?><?php echo esc_html( $row->country ?: '未知（异步识别中或查询失败）' ); ?>
                            &nbsp;<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'view', 'id' => $row->id, 'refresh_country' => 1 ), $list_url ), 'cif_refresh_country_' . $row->id ) ); ?>" style="font-size:12px;">刷新</a>
                        </td></tr>
                        <tr><td style="border:none;padding:6px 0;color:#666;">用户IP</td><td style="border:none;padding:6px 0;">
                            <?php echo esc_html( $row->ip ?: '未知' ); ?>
                            <?php if ( $row->ip ) : ?>
                                <?php $this->render_blacklist_quick_add_button( 'ip', $row->ip, '拉黑此IP' ); ?>
                            <?php endif; ?>
                        </td></tr>
                        <tr><td style="border:none;padding:6px 0;color:#666;">来源URL</td><td style="border:none;padding:6px 0;word-break:break-all;"><?php echo $row->source_url ? '<a href="' . esc_url( $row->source_url ) . '" target="_blank">' . esc_html( $row->source_url ) . '</a>' : '未知'; ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;color:#666;">浏览器</td><td style="border:none;padding:6px 0;"><?php echo esc_html( $row->browser ?: '未知' ); ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;color:#666;">设备/系统</td><td style="border:none;padding:6px 0;"><?php echo esc_html( $row->device ?: '未知' ); ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;color:#666;">提交用户</td><td style="border:none;padding:6px 0;"><?php echo esc_html( $row->submitted_by ?: '访客' ); ?></td></tr>
                        <?php if ( $submitted_email ) : ?>
                        <tr><td style="border:none;padding:6px 0;color:#666;">提交邮箱</td><td style="border:none;padding:6px 0;">
                            <?php echo esc_html( $submitted_email ); ?>
                            <?php $this->render_blacklist_quick_add_button( 'email', $submitted_email, '拉黑此邮箱' ); ?>
                        </td></tr>
                        <?php endif; ?>
                        <tr><td style="border:none;padding:6px 0;color:#666;">提交时间</td><td style="border:none;padding:6px 0;"><?php echo esc_html( $row->created_at ); ?></td></tr>
                    </table>

                    <p style="margin-top:16px;">
                        <a href="<?php echo esc_url( $delete_url ); ?>" class="button" onclick="return confirm('确定要删除这条记录吗？');">删除记录</a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染详情页里"拉黑此IP / 拉黑此邮箱"的小按钮（提交到 admin-post，加入黑名单后跳回原页面）
     */
    private function render_blacklist_quick_add_button( $type, $value, $label ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
            <?php wp_nonce_field( 'cif_blacklist_quick_add' ); ?>
            <input type="hidden" name="action" value="cif_blacklist_quick_add" />
            <input type="hidden" name="bl_type" value="<?php echo esc_attr( $type ); ?>" />
            <input type="hidden" name="bl_value" value="<?php echo esc_attr( $value ); ?>" />
            <input type="hidden" name="bl_redirect" value="<?php echo esc_url( isset( $_SERVER['REQUEST_URI'] ) ? admin_url( ltrim( wp_unslash( $_SERVER['REQUEST_URI'] ), '/' ) ) : '' ); ?>" />
            <button type="submit" class="button button-small" onclick="return confirm('确定要将 <?php echo esc_js( $value ); ?> 加入黑名单吗？');"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }
}

new Custom_Inquiry_Form_V2();
