<?php
/**
 * Plugin Name: Last Uploaded Image as Featured
 * Description: Imposta come featured image l'immagine più recente del contenuto o dell'articolo più recente di una categoria.
 * Version: 6.0
 * Author: Custom
 */

if (!defined('ABSPATH')) {
    exit;
}

class LastUploadedImageFeatured {

    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu',            [$this, 'addAdminMenu'], 20);
        add_action('admin_init',            [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'loadMediaScript']);
        add_filter('get_post_metadata',     [$this, 'overrideThumbnailMeta'], 10, 4);

        // Filtri Yoast SEO (se attivo) - sovrascrive direttamente il valore prima che venga stampato
        add_filter('wpseo_opengraph_image',     [$this, 'overrideYoastOgImage'], 999);
        add_filter('wpseo_opengraph_image_url', [$this, 'overrideYoastOgImage'], 999);

        // Fallback generico: stampa og:image dopo qualsiasi plugin SEO
        add_action('wp_head', [$this, 'printOgImageOverride'], 99999);
    }

    // =========================================================================
    // CORE
    // =========================================================================

    public function overrideThumbnailMeta($value, $post_id, $meta_key, $single) {
        if ($meta_key !== '_thumbnail_id') return $value;
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return $value;

        $config = $this->getPostConfig($post_id);
        if (!$config) return $value;

        $image_id = $this->resolveImageId($post_id, $config);

        if ($image_id) {
            return $single ? (int)$image_id : [(int)$image_id];
        }

        return $value;
    }

    /**
     * Filtro Yoast: sovrascrive il valore dell'immagine prima che Yoast lo stampi.
     * Riceve l'URL corrente e restituisce il nuovo URL se la pagina e' abilitata.
     */
    public function overrideYoastOgImage($image_url) {
        if (is_admin()) return $image_url;

        $post_id = get_queried_object_id();
        if (!$post_id) return $image_url;

        $config = $this->getPostConfig($post_id);
        if (!$config) return $image_url;

        $image_id = $this->resolveImageId($post_id, $config);
        if (!$image_id) return $image_url;

        $new_url = wp_get_attachment_url($image_id);
        return $new_url ?: $image_url;
    }

    /**
     * Fallback generico: stampa og:image in fondo all'head dopo qualsiasi plugin SEO.
     * Necessario per plugin SEO diversi da Yoast che non espongono filtri sull'immagine.
     * Nota: i crawler leggono l'ULTIMO og:image trovato nella pagina.
     */
    public function printOgImageOverride() {
        if (is_admin()) return;

        $post_id = get_queried_object_id();
        if (!$post_id) return;

        $config = $this->getPostConfig($post_id);
        if (!$config) return;

        $image_id = $this->resolveImageId($post_id, $config);
        if (!$image_id) return;

        $image_url = wp_get_attachment_url($image_id);
        if (!$image_url) return;

        $meta = wp_get_attachment_metadata($image_id);
        $width  = $meta['width']  ?? '';
        $height = $meta['height'] ?? '';

        echo "\n<!-- Last Uploaded Image as Featured: og:image override -->\n";
        echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";
        if ($width)  echo '<meta property="og:image:width" content="'  . esc_attr($width)  . '" />' . "\n";
        if ($height) echo '<meta property="og:image:height" content="' . esc_attr($height) . '" />' . "\n";
        echo "<!-- /Last Uploaded Image as Featured -->\n";
    }

    /**
     * Logica centrale: dato post_id e config, restituisce l'image_id da usare.
     */
    private function resolveImageId($post_id, $config) {
        $image_id = false;

        if ($config['mode'] === 'archive') {
            $image_id = $this->getImageFromLatestPost($config['include_cats'], $config['exclude_cats']);
        } else {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                $image_id = $this->getMostRecentImageFromPost($post);
            }
        }

        if (!$image_id) {
            $fallback = get_option('luif_fallback_image', '');
            if (!empty($fallback) && is_numeric($fallback)) {
                $image_id = (int)$fallback;
            }
        }

        return $image_id;
    }

    private function getPostConfig($post_id) {
        $configs = get_option('luif_post_configs', []);
        if (!is_array($configs) || !isset($configs[$post_id])) return false;
        return $configs[$post_id];
    }

    // =========================================================================
    // MODALITÀ ARCHIVIO
    // =========================================================================

    private function getImageFromLatestPost($include_cats = [], $exclude_cats = []) {
        global $wpdb;

        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 10, // prende i 10 più recenti così troviamo il primo con featured image
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $tax_query = [];

        if (!empty($include_cats)) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => array_map('intval', $include_cats),
                'operator' => 'IN',
            ];
        }

        if (!empty($exclude_cats)) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => array_map('intval', $exclude_cats),
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $posts = get_posts($args);
        if (empty($posts)) return false;

        // Legge _thumbnail_id direttamente dal DB per evitare ricorsione
        foreach ($posts as $post) {
            $thumbnail_id = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key = '_thumbnail_id' LIMIT 1",
                $post->ID
            ));
            if ($thumbnail_id && is_numeric($thumbnail_id)) {
                return (int)$thumbnail_id;
            }
        }

        return false;
    }

    // =========================================================================
    // MODALITÀ CONTENUTO
    // =========================================================================

    private function getMostRecentImageFromPost($post) {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_parent'    => $post->ID,
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'numberposts'    => 1,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ]);

        if (!empty($attachments)) return $attachments[0]->ID;

        return $this->extractNewestImageFromContent($post->post_content);
    }

    private function extractNewestImageFromContent($content) {
        if (empty($content)) return false;

        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
        if (empty($matches[1])) return false;

        $best_id = false;
        foreach ($matches[1] as $url) {
            $id = $this->urlToAttachmentId($url);
            if ($id && (!$best_id || $id > $best_id)) {
                $best_id = $id;
            }
        }
        return $best_id;
    }

    private function urlToAttachmentId($url) {
        global $wpdb;
        if (empty($url) || !$wpdb) return false;

        $clean_url = preg_replace('/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp|avif)$)/i', '', $url);
        $clean_url = strtok($clean_url, '?');

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1",
            $clean_url
        ));
        if ($id) return (int)$id;

        $upload_dir = wp_upload_dir();
        $relative   = str_replace($upload_dir['baseurl'] . '/', '', $clean_url);
        if ($relative !== $clean_url) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                $relative
            ));
        }

        return $id ? (int)$id : false;
    }

    // =========================================================================
    // ADMIN — registrazione impostazioni
    // =========================================================================

    public function addAdminMenu() {
        add_options_page(
            'Last Uploaded Image as Featured',
            'Last Uploaded Image',
            'manage_options',
            'last-uploaded-image',
            [$this, 'settingsPage']
        );
    }

    public function registerSettings() {
        register_setting('luif_settings', 'luif_post_configs', [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => [$this, 'sanitizeConfigs'],
        ]);
        register_setting('luif_settings', 'luif_fallback_image', [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'absint',
        ]);
    }

    /**
     * BUG FIX 1: salva SOLO i post che hanno il flag enabled=1 esplicito nel POST.
     * I radio e hidden dei post non spuntati NON vengono inviati grazie al flag.
     * Ma siccome i radio arrivano comunque, filtriamo per enabled.
     */
    public function sanitizeConfigs($input) {
        if (!is_array($input)) return [];

        // Leggi i post abilitati direttamente dal POST grezzo (non da $input
        // che WordPress ha già processato) per sapere quali checkbox erano spuntati
        $enabled_ids = [];
        if (isset($_POST['luif_enabled_ids']) && is_array($_POST['luif_enabled_ids'])) {
            $enabled_ids = array_map('intval', $_POST['luif_enabled_ids']);
        }

        $clean = [];
        foreach ($enabled_ids as $post_id) {
            if (!$post_id) continue;
            $cfg = $input[$post_id] ?? [];
            $clean[$post_id] = [
                'mode'         => ($cfg['mode'] ?? '') === 'archive' ? 'archive' : 'content',
                'include_cats' => isset($cfg['include_cats']) ? array_map('intval', (array)$cfg['include_cats']) : [],
                'exclude_cats' => isset($cfg['exclude_cats']) ? array_map('intval', (array)$cfg['exclude_cats']) : [],
            ];
        }
        return $clean;
    }

    public function loadMediaScript($hook) {
        if ($hook !== 'settings_page_last-uploaded-image') return;
        wp_enqueue_media();
    }

    // =========================================================================
    // ADMIN — pagina impostazioni
    // =========================================================================

    public function settingsPage() {
        if (!is_admin()) return;

        if (isset($_POST['luif_reset']) && check_admin_referer('luif_reset_action', 'luif_reset_nonce')) {
            delete_option('luif_post_configs');
            delete_option('luif_fallback_image');
            echo '<div class="notice notice-success"><p>✅ Plugin resettato.</p></div>';
        }

        $configs      = get_option('luif_post_configs', []);
        if (!is_array($configs)) $configs = [];

        $fallback_id  = get_option('luif_fallback_image', '');
        $fallback_url = $fallback_id ? wp_get_attachment_url($fallback_id) : '';

        $all_posts = get_posts([
            'post_type'   => ['page', 'post'],
            'numberposts' => 200,
            'post_status' => 'publish',
            'orderby'     => 'type',
            'order'       => 'ASC',
        ]);

        $all_categories = get_categories(['hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1>Last Uploaded Image as Featured</h1>
            <p>Per ogni pagina o articolo abilitato, scegli come ricavare l'immagine in evidenza.</p>

            <form method="post" action="options.php">
                <?php settings_fields('luif_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Configurazione pagine/articoli</th>
                        <td>
                        <?php if (empty($all_posts)): ?>
                            <p>Nessun contenuto pubblicato trovato.</p>
                        <?php else: ?>
                            <div style="max-height:540px; overflow-y:auto; border:1px solid #ccc; padding:14px; background:#fafafa;">
                            <?php
                            $current_type = '';
                            foreach ($all_posts as $p):
                                $pid    = $p->ID;
                                $cfg    = $configs[$pid] ?? null;
                                $active = !empty($cfg);
                                $mode   = $cfg['mode'] ?? 'content';
                                $inc    = $cfg['include_cats'] ?? [];
                                $exc    = $cfg['exclude_cats'] ?? [];

                                if ($p->post_type !== $current_type):
                                    $current_type = $p->post_type;
                                    $label = $current_type === 'page' ? '📄 Pagine' : '📝 Articoli';
                                    echo '<p style="font-weight:700;font-size:13px;margin:16px 0 8px;border-bottom:1px solid #ddd;padding-bottom:4px;">' . $label . '</p>';
                                endif;
                            ?>
                                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:10px 14px;margin-bottom:10px;">

                                    <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;">
                                        <?php /* BUG FIX 1: checkbox separato dall'array config, inviato come lista piatta */ ?>
                                        <input type="checkbox"
                                               name="luif_enabled_ids[]"
                                               value="<?php echo $pid; ?>"
                                               class="luif-enable-toggle"
                                               data-pid="<?php echo $pid; ?>"
                                               <?php checked($active); ?>>
                                        <?php echo esc_html($p->post_title); ?>
                                        <span style="color:#999;font-weight:400;font-size:12px;">(ID: <?php echo $pid; ?>)</span>
                                    </label>

                                    <div class="luif-post-options"
                                         data-pid="<?php echo $pid; ?>"
                                         style="margin-top:10px;padding-left:24px;<?php echo $active ? '' : 'display:none;'; ?>">

                                        <div style="margin-bottom:8px;">
                                            <strong style="font-size:12px;">Modalità:</strong><br>
                                            <label style="margin-right:16px;">
                                                <input type="radio"
                                                       name="luif_post_configs[<?php echo $pid; ?>][mode]"
                                                       value="content"
                                                       class="luif-mode-radio"
                                                       data-pid="<?php echo $pid; ?>"
                                                       <?php checked($mode, 'content'); ?>>
                                                🖼️ Immagine più recente nel contenuto
                                            </label>
                                            <label>
                                                <input type="radio"
                                                       name="luif_post_configs[<?php echo $pid; ?>][mode]"
                                                       value="archive"
                                                       class="luif-mode-radio"
                                                       data-pid="<?php echo $pid; ?>"
                                                       <?php checked($mode, 'archive'); ?>>
                                                📋 Featured image dell'articolo più recente (archivio)
                                            </label>
                                        </div>

                                        <div class="luif-archive-options"
                                             data-pid="<?php echo $pid; ?>"
                                             style="<?php echo $mode === 'archive' ? '' : 'display:none;'; ?>background:#f0f4ff;border-radius:4px;padding:10px;margin-top:6px;">

                                            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                                                <div>
                                                    <strong style="font-size:12px;color:#2271b1;">✅ Includi solo queste categorie</strong>
                                                    <p style="font-size:11px;color:#666;margin:2px 0 6px;">(vuoto = tutte)</p>
                                                    <div style="max-height:150px;overflow-y:auto;border:1px solid #c8d0e0;background:#fff;padding:6px;border-radius:3px;">
                                                    <?php foreach ($all_categories as $cat): ?>
                                                        <label style="display:block;margin-bottom:4px;font-size:13px;">
                                                            <input type="checkbox"
                                                                   name="luif_post_configs[<?php echo $pid; ?>][include_cats][]"
                                                                   value="<?php echo $cat->term_id; ?>"
                                                                   <?php checked(in_array($cat->term_id, $inc)); ?>>
                                                            <?php echo esc_html($cat->name); ?>
                                                            <span style="color:#aaa;font-size:11px;">(<?php echo $cat->count; ?>)</span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                    </div>
                                                </div>

                                                <div>
                                                    <strong style="font-size:12px;color:#d63638;">🚫 Escludi queste categorie</strong>
                                                    <p style="font-size:11px;color:#666;margin:2px 0 6px;">(vuoto = nessuna esclusa)</p>
                                                    <div style="max-height:150px;overflow-y:auto;border:1px solid #c8d0e0;background:#fff;padding:6px;border-radius:3px;">
                                                    <?php foreach ($all_categories as $cat): ?>
                                                        <label style="display:block;margin-bottom:4px;font-size:13px;">
                                                            <input type="checkbox"
                                                                   name="luif_post_configs[<?php echo $pid; ?>][exclude_cats][]"
                                                                   value="<?php echo $cat->term_id; ?>"
                                                                   <?php checked(in_array($cat->term_id, $exc)); ?>>
                                                            <?php echo esc_html($cat->name); ?>
                                                            <span style="color:#aaa;font-size:11px;">(<?php echo $cat->count; ?>)</span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <p class="description">Solo le voci spuntate sono gestite dal plugin.</p>
                        <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Immagine di fallback</th>
                        <td>
                            <input type="hidden" name="luif_fallback_image" id="luif_fallback_image" value="<?php echo esc_attr($fallback_id); ?>">
                            <button type="button" class="button" id="luif_upload_btn">📷 Seleziona immagine</button>
                            <button type="button" class="button" id="luif_remove_btn" style="display:<?php echo $fallback_id ? 'inline-block' : 'none'; ?>">🗑️ Rimuovi</button>
                            <div id="luif_preview" style="margin-top:10px;">
                                <?php if ($fallback_url): ?>
                                    <img src="<?php echo esc_url($fallback_url); ?>" style="max-width:200px;border:1px solid #ccc;">
                                <?php endif; ?>
                            </div>
                            <p class="description">Mostrata se non viene trovata nessuna immagine.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Salva impostazioni'); ?>
            </form>

            <hr>
            <h3>🔧 Strumenti</h3>
            <form method="post" style="display:inline-block;">
                <input type="hidden" name="luif_reset" value="1">
                <?php wp_nonce_field('luif_reset_action', 'luif_reset_nonce'); ?>
                <?php submit_button('Reset completo', 'secondary', 'reset_plugin', false); ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {

            $(document).on('change', '.luif-enable-toggle', function() {
                var pid  = $(this).data('pid');
                var opts = $('.luif-post-options[data-pid="' + pid + '"]');
                opts.toggle($(this).is(':checked'));
            });

            $(document).on('change', '.luif-mode-radio', function() {
                var pid = $(this).data('pid');
                $('.luif-archive-options[data-pid="' + pid + '"]').toggle($(this).val() === 'archive');
            });

            var uploader;
            $('#luif_upload_btn').on('click', function(e) {
                e.preventDefault();
                if (uploader) { uploader.open(); return; }
                uploader = wp.media({
                    title: 'Immagine di fallback',
                    button: { text: 'Usa questa immagine' },
                    multiple: false
                });
                uploader.on('select', function() {
                    var att = uploader.state().get('selection').first().toJSON();
                    $('#luif_fallback_image').val(att.id);
                    $('#luif_preview').html('<img src="' + att.url + '" style="max-width:200px;border:1px solid #ccc;">');
                    $('#luif_remove_btn').show();
                });
                uploader.open();
            });

            $('#luif_remove_btn').on('click', function(e) {
                e.preventDefault();
                $('#luif_fallback_image').val('');
                $('#luif_preview').html('');
                $(this).hide();
            });
        });
        </script>
        <?php
    }
}

add_action('plugins_loaded', function() {
    LastUploadedImageFeatured::getInstance();
});