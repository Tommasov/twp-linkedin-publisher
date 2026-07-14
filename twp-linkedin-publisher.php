<?php
/**
 * Plugin Name: TWP - LinkedIn Publisher
 * Description: A lightweight WordPress plugin that allows manual publishing of posts to a LinkedIn Company Page using OAuth2 authentication. It adds a "Publish to LinkedIn" button to the post editor sidebar.
 * Version: 1.2
 * Author: Tommaso Vietina
 * Text Domain: twp-linkedin-publisher
 * License: GPL-2.0-or-later
 */

use JetBrains\PhpStorm\NoReturn;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

add_action( 'admin_notices', function () {
    if ( isset( $_GET['twp_linkedin_status'] ) ) {
        switch ( $_GET['twp_linkedin_status'] ) {
            case 'success':
                echo '<div class="notice notice-success is-dismissible"><p>✅ Il post è stato pubblicato su LinkedIn con successo.</p></div>';
                break;
            case 'error':
                echo '<div class="notice notice-error is-dismissible"><p>❌ Errore durante la pubblicazione su LinkedIn.</p></div>';
                break;
        }
    }
} );

class TWP_LinkedIn_Publisher {

    /**
     * Numero massimo di immagini consentite da LinkedIn in un post multi-immagine.
     */
    const MAX_IMAGES = 9;

    private $redirect_uri;

    public function __construct() {
        $this->redirect_uri = home_url( '/linkedin-callback/' );
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_action( 'init', [ $this, 'maybe_flush_rules' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'template_redirect', [ $this, 'handle_linkedin_callback' ] );
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_linkedin_publish_button' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_assets' ] );
        add_action( 'save_post', [ $this, 'save_linkedin_meta' ] );
        add_action( 'admin_post_twp_publish_to_linkedin', [ $this, 'publish_to_linkedin' ] );
        add_action( 'admin_post_twp_dryrun_to_linkedin', [ $this, 'dry_run_to_linkedin' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_dryrun_notice' ] );

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    public function register_settings(): void {
        register_setting( 'twp_linkedin_settings_group', 'twp_linkedin_client_id' );
        register_setting( 'twp_linkedin_settings_group', 'twp_linkedin_client_secret' );
    }

    public function activate(): void {
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        update_option('twp_linkedin_flush_rules', 1);
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }

    public function maybe_flush_rules(): void {
        if ( get_option( 'twp_linkedin_flush_rules' ) ) {
            flush_rewrite_rules();
            delete_option( 'twp_linkedin_flush_rules' );
        }
    }

    public function add_rewrite_rules(): void {
        add_rewrite_rule( 'linkedin-callback/?$', 'index.php?linkedin_callback=1', 'top' );
    }

    public function add_query_vars( $vars ): array {
        $vars[] = 'linkedin_callback';
        return $vars;
    }

    public function handle_linkedin_callback(): void {
        if ( get_query_var( 'linkedin_callback' ) !== '1' ) {
            return;
        }

        if ( isset( $_GET['error'] ) ) {
            wp_die( 'Errore da LinkedIn: ' . sanitize_text_field( $_GET['error_description'] ?? $_GET['error'] ) );
        }

        if ( isset( $_GET['code'] ) ) {
            $this->handle_linkedin_auth();
        }

        wp_redirect( admin_url( 'options-general.php?page=twp_linkedin_settings' ) );
        exit;
    }

    public function add_settings_page(): void {
        add_options_page( 'LinkedIn Publisher', 'LinkedIn Publisher', 'manage_options', 'twp_linkedin_settings', [
            $this,
            'render_settings_page'
        ] );
    }

    public function render_settings_page(): void {

        if ( isset( $_GET['set_org_id'] ) && current_user_can( 'manage_options' ) ) {
            update_option( 'twp_linkedin_organization_id', sanitize_text_field( $_GET['set_org_id'] ) );

            if ( ! headers_sent() ) {
                wp_redirect( admin_url( 'options-general.php?page=twp_linkedin_settings' ) );
            } else {
                echo '<script>window.location.href="' . admin_url( 'options-general.php?page=twp_linkedin_settings' ) . '";</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . admin_url( 'options-general.php?page=twp_linkedin_settings' ) . '"></noscript>';
            }
            exit;
        }

        if ( isset( $_GET['choose_org'] ) && current_user_can( 'manage_options' ) ) {
            $elements = get_transient( 'twp_linkedin_org_choices' );
            if ( ! $elements ) {
                echo '<div class="notice notice-error"><p>Nessuna organizzazione trovata o sessione scaduta.</p></div>';
            } else {
                echo '<div class="wrap"><h1>Seleziona la tua Pagina Aziendale LinkedIn</h1>';
                echo '<p>Hai più di una pagina. Seleziona quella da collegare:</p><ul>';
                foreach ( $elements as $el ) {
                    if ( ! isset( $el['organizationalTarget'] ) ) {
                        continue;
                    }
                    $id  = str_replace( 'urn:li:organization:', '', $el['organizationalTarget'] );
                    $url = esc_url( admin_url( "options-general.php?page=twp_linkedin_settings&set_org_id={$id}" ) );
                    echo "<li><a class='button button-primary' href='{$url}'>Usa Organization ID: <strong>{$id}</strong></a></li>";
                }
                echo '</ul></div>';
            }
            delete_transient( 'twp_linkedin_org_choices' );

            return;
        }

        if ( isset( $_GET['reset'] ) && current_user_can( 'manage_options' ) ) {
            delete_option( 'twp_linkedin_access_token' );
            delete_option( 'twp_linkedin_organization_id' );
        }

        if ( isset( $_GET['code'] ) ) {
            $this->handle_linkedin_auth();
        }

        $access_token    = get_option( 'twp_linkedin_access_token' );
        $organization_id = get_option( 'twp_linkedin_organization_id' );
        $client_id       = get_option( 'twp_linkedin_client_id' );
        $client_secret   = get_option( 'twp_linkedin_client_secret' );

        echo '<div class="wrap">';
        echo '<h1>Impostazioni LinkedIn Publisher</h1>';
        echo '<p>Con questo plugin puoi collegare il tuo sito WordPress a LinkedIn e pubblicare i post aziendali manualmente.</p>';

        echo '<form method="post" action="options.php">';
        settings_fields( 'twp_linkedin_settings_group' );
        do_settings_sections( 'twp_linkedin_settings_group' );

        echo '<table class="form-table">';

        echo '<tr>';
        echo '<th scope="row"><label for="twp_linkedin_client_id">Client ID:</label></th>';
        echo '<td><input type="text" id="twp_linkedin_client_id" name="twp_linkedin_client_id" value="' . esc_attr( $client_id ) . '" class="regular-text"></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="twp_linkedin_client_secret">Client Secret:</label></th>';
        echo '<td><input type="password" id="twp_linkedin_client_secret" name="twp_linkedin_client_secret" value="' . esc_attr( $client_secret ) . '" class="regular-text"></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Stato connessione:</th>';
        echo '<td>';
        if ( $access_token ) {
            echo '<span style="color:green;font-weight:bold;">Connesso ✅</span>';
        } else {
            echo '<span style="color:red;font-weight:bold;">Non Connesso ❌</span>';
        }
        echo '</td>';
        echo '</tr>';

        if ( $access_token && $organization_id ) {
            echo '<tr>';
            echo '<th scope="row">Organization ID:</th>';
            echo '<td>' . esc_html( $organization_id ) . '</td>';
            echo '</tr>';
        }

        echo '</table>';

        submit_button( 'Salva Credenziali' );
        echo '</form>';

        echo '<hr>';

        echo '<p>';

        if ( ! $access_token ) {
            if ( $client_id && $client_secret ) {
                $state    = wp_generate_password( 24, false );
                set_transient( 'twp_linkedin_oauth_state', $state, HOUR_IN_SECONDS );
                $auth_url = "https://www.linkedin.com/oauth/v2/authorization?response_type=code&client_id={$client_id}&redirect_uri=" . urlencode( $this->redirect_uri ) . "&scope=w_member_social%20rw_organization_admin%20w_organization_social&state={$state}";
                echo '<a href="' . esc_url( $auth_url ) . '" class="button button-primary">🔗 Connetti a LinkedIn</a>';
            } else {
                echo '<p><em>Inserisci Client ID e Client Secret per abilitare la connessione.</em></p>';
            }
        } else {
            echo '<a href="' . esc_url( admin_url( 'options-general.php?page=twp_linkedin_settings&reset=1' ) ) . '" class="button button-secondary">❌ Disconnetti</a>';
        }

        echo '</p>';
        echo '</div>';
    }

    #[NoReturn] public function handle_linkedin_auth(): void {
        if ( ! isset( $_GET['code'] ) ) {
            wp_die( 'Code mancante.' );
        }

        $state    = sanitize_text_field( $_GET['state'] ?? '' );
        $saved_state = get_transient( 'twp_linkedin_oauth_state' );

        if ( ! $state || $state !== $saved_state ) {
            error_log( "TWP LinkedIn Error: State mismatch. Received: $state, Saved: $saved_state" );
            wp_die( 'Stato OAuth non valido. Possibile attacco CSRF. Prova a ricaricare la pagina delle impostazioni e riprova.' );
        }

        delete_transient( 'twp_linkedin_oauth_state' );

        $client_id     = get_option( 'twp_linkedin_client_id' );
        $client_secret = get_option( 'twp_linkedin_client_secret' );

        if ( ! $client_id || ! $client_secret ) {
            wp_die( 'Credenziali Client ID o Client Secret mancanti nelle impostazioni.' );
        }

        $response = wp_remote_post( 'https://www.linkedin.com/oauth/v2/accessToken', [
            'body' => [
                'grant_type'    => 'authorization_code',
                'code'          => sanitize_text_field( $_GET['code'] ),
                'redirect_uri'  => $this->redirect_uri,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            wp_die( 'Errore nella richiesta accessToken: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['access_token'] ) ) {
            $error_msg = $body['error_description'] ?? $body['error'] ?? 'Sconosciuto';
            wp_die( 'Access token non ricevuto. Errore: ' . esc_html( $error_msg ) );
        }

        update_option( 'twp_linkedin_access_token', $body['access_token'] );

        $org_response = wp_remote_get( 'https://api.linkedin.com/v2/organizationalEntityAcls?q=roleAssignee&role=ADMINISTRATOR', [
            'headers' => [
                'Authorization' => 'Bearer ' . $body['access_token']
            ]
        ] );

        $org_body = json_decode( wp_remote_retrieve_body( $org_response ), true );

        if ( is_wp_error( $org_response ) ) {
            wp_die( 'Errore nel recupero delle organizzazioni: ' . $org_response->get_error_message() );
        }

        if ( isset( $org_body['status'] ) && $org_body['status'] >= 400 ) {
            wp_die( 'Errore API LinkedIn: ' . ( $org_body['message'] ?? 'Errore sconosciuto' ) );
        }

        $elements = $org_body['elements'] ?? [];

        if ( empty( $elements ) ) {
            wp_die( 'Non sei amministratore di nessuna pagina LinkedIn.' );
        }

        set_transient( 'twp_linkedin_org_choices', $elements, 300 );
        wp_redirect( admin_url( 'options-general.php?page=twp_linkedin_settings&choose_org=1' ) );
        exit;
    }


    public function add_linkedin_publish_button(): void {
        add_meta_box(
            'twp_linkedin_publish',
            'Pubblica su LinkedIn',
            [ $this, 'render_publish_button' ],
            'post',
            'side',
            'high'
        );
    }

    /**
     * @param $post
     *
     * @return void
     */
    public function render_publish_button( $post ): void {
        $url  = admin_url( 'admin-post.php' );
        $link = add_query_arg( [
            'action'   => 'twp_publish_to_linkedin',
            'post_id'  => $post->ID,
            '_wpnonce' => wp_create_nonce( 'twp_linkedin_publish' )
        ], $url );

        $dryrun_link = add_query_arg( [
            'action'   => 'twp_dryrun_to_linkedin',
            'post_id'  => $post->ID,
            '_wpnonce' => wp_create_nonce( 'twp_linkedin_dryrun' )
        ], $url );

        $custom_content  = get_post_meta( $post->ID, '_twp_linkedin_content', true );
        $selected_images = get_post_meta( $post->ID, '_twp_linkedin_images', true );
        if ( ! is_array( $selected_images ) ) {
            $selected_images = [];
        }

        wp_nonce_field( 'twp_linkedin_save_meta', 'twp_linkedin_meta_nonce' );

        // Contenuto dedicato per il post LinkedIn.
        echo '<p><label for="twp_linkedin_content"><strong>Testo del post LinkedIn</strong></label></p>';
        echo '<textarea id="twp_linkedin_content" name="twp_linkedin_content" rows="5" style="width:100%;" placeholder="Se lasci vuoto verrà usato il riassunto (excerpt) dell\'articolo.">' . esc_textarea( $custom_content ) . '</textarea>';

        // Galleria immagini: rilevate nell'articolo + eventuali immagini
        // aggiunte manualmente dalla libreria (salvate ma non presenti nel post).
        $selected_ints = array_map( 'intval', $selected_images );
        $detected      = $this->get_post_images( $post->ID );

        // Aggiungi in coda le immagini selezionate che non sono state rilevate
        // (es. media caricato apposta per LinkedIn, non presente nell'articolo).
        $all_images = $detected;
        foreach ( $selected_ints as $sel_id ) {
            if ( $sel_id && ! in_array( $sel_id, $all_images, true ) && wp_attachment_is_image( $sel_id ) ) {
                $all_images[] = $sel_id;
            }
        }

        echo '<p style="margin-top:12px;"><strong>Immagini da pubblicare</strong></p>';

        echo '<div id="twp-li-images" style="display:flex;flex-wrap:wrap;gap:8px;max-height:260px;overflow-y:auto;">';
        foreach ( $all_images as $att_id ) {
            $thumb = wp_get_attachment_image_url( $att_id, 'thumbnail' );
            if ( ! $thumb ) {
                continue;
            }
            $checked = in_array( $att_id, $selected_ints, true ) ? 'checked' : '';
            echo '<label style="position:relative;cursor:pointer;display:inline-block;">';
            echo '<input type="checkbox" name="twp_linkedin_images[]" value="' . esc_attr( $att_id ) . '" ' . $checked . ' style="position:absolute;top:4px;left:4px;">';
            echo '<img src="' . esc_url( $thumb ) . '" style="width:70px;height:70px;object-fit:cover;border:1px solid #ccc;border-radius:4px;" alt="">';
            echo '</label>';
        }
        echo '</div>';

        echo '<p style="margin-top:6px;"><button type="button" class="button button-secondary" id="twp-li-add-image">➕ Aggiungi immagine dalla libreria</button></p>';
        echo '<p><em>Spunta le immagini da includere nel post (galleria multi-immagine, massimo ' . self::MAX_IMAGES . '). Puoi aggiungere anche immagini non presenti nell\'articolo dalla libreria media.</em></p>';

        $this->render_media_picker_script();

        echo '<hr>';
        echo '<p style="color:#856404;background:#fff3cd;padding:6px 8px;border-radius:4px;font-size:12px;">⚠️ <strong>Salva/Aggiorna il post</strong> prima di usare Simula o Pubblica: questi pulsanti aprono un\'altra pagina e <strong>non salvano</strong> il testo e le immagini appena spuntati.</p>';
        echo '<p><a href="' . esc_url( $dryrun_link ) . '" class="button button-secondary">🧪 Simula (dry-run)</a></p>';
        echo '<p><a href="' . esc_url( $link ) . '" class="button button-primary">🔗 Pubblica su LinkedIn</a></p>';
    }

    /**
     * Carica gli script del media picker di WordPress nelle schermate di modifica del post.
     *
     * @param string $hook
     *
     * @return void
     */
    public function enqueue_media_assets( string $hook ): void {
        if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
            wp_enqueue_media();
        }
    }

    /**
     * Stampa lo script che apre la libreria media di WordPress e aggiunge
     * le immagini selezionate (anche non presenti nell'articolo) alla galleria LinkedIn.
     *
     * @return void
     */
    private function render_media_picker_script(): void {
        ?>
        <script>
        ( function () {
            function init() {
                var btn = document.getElementById( 'twp-li-add-image' );
                var box = document.getElementById( 'twp-li-images' );
                if ( ! btn || ! box ) {
                    return;
                }

                var frame;
                btn.addEventListener( 'click', function ( e ) {
                    e.preventDefault();

                    if ( typeof wp === 'undefined' || ! wp.media ) {
                        console.warn( 'TWP LinkedIn: la libreria media di WordPress non è disponibile.' );
                        return;
                    }

                    if ( frame ) {
                        frame.open();
                        return;
                    }

                    frame = wp.media( {
                        title: 'Seleziona immagini per LinkedIn',
                        button: { text: 'Aggiungi alla galleria LinkedIn' },
                        library: { type: 'image' },
                        multiple: true
                    } );

                    frame.on( 'select', function () {
                        var selection = frame.state().get( 'selection' ).toJSON();
                        selection.forEach( function ( att ) {
                            // Se l'immagine è già presente, limitati a spuntarla.
                            var existing = box.querySelector( 'input[name="twp_linkedin_images[]"][value="' + att.id + '"]' );
                            if ( existing ) {
                                existing.checked = true;
                                return;
                            }

                            var thumb = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
                            var label = document.createElement( 'label' );
                            label.style.cssText = 'position:relative;cursor:pointer;display:inline-block;';

                            var input = document.createElement( 'input' );
                            input.type = 'checkbox';
                            input.name = 'twp_linkedin_images[]';
                            input.value = att.id;
                            input.checked = true;
                            input.style.cssText = 'position:absolute;top:4px;left:4px;';

                            var img = document.createElement( 'img' );
                            img.src = thumb;
                            img.alt = '';
                            img.style.cssText = 'width:70px;height:70px;object-fit:cover;border:1px solid #ccc;border-radius:4px;';

                            label.appendChild( input );
                            label.appendChild( img );
                            box.appendChild( label );
                        } );
                    } );

                    frame.open();
                } );
            }

            if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', init );
            } else {
                init();
            }
        } )();
        </script>
        <?php
    }

    /**
     * Restituisce gli ID degli allegati immagine dell'articolo:
     * immagine in evidenza + immagini caricate nel post + immagini
     * referenziate nel contenuto (shortcode WPBakery/gallerie, tag <img>).
     *
     * @param int $post_id
     *
     * @return int[]
     */
    private function get_post_images( int $post_id ): array {
        $ids = [];

        // 1. Immagine in evidenza (mostrata per prima).
        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $ids[] = $thumb_id;
        }

        // 2. Allegati caricati direttamente nel post.
        $attachments = get_children( [
            'post_parent'    => $post_id,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'numberposts'    => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ] );
        foreach ( array_map( 'intval', wp_list_pluck( $attachments, 'ID' ) ) as $att_id ) {
            $ids[] = $att_id;
        }

        // 3. Immagini referenziate nel contenuto (WPBakery media grid/gallery,
        //    single image, e tag <img> del contenuto).
        foreach ( $this->extract_content_image_ids( $post_id ) as $content_id ) {
            $ids[] = $content_id;
        }

        // Dedup mantenendo l'ordine e tenendo solo veri allegati immagine.
        $unique = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id && ! in_array( $id, $unique, true ) && wp_attachment_is_image( $id ) ) {
                $unique[] = $id;
            }
        }

        return $unique;
    }

    /**
     * Estrae gli ID degli allegati immagine referenziati nel contenuto del post.
     * Copre gli shortcode WPBakery (vc_media_grid, vc_gallery, vc_single_image)
     * e gallerie/immagini che espongono gli ID via attributi o classi wp-image-*.
     *
     * @param int $post_id
     *
     * @return int[]
     */
    private function extract_content_image_ids( int $post_id ): array {
        $content = (string) get_post_field( 'post_content', $post_id );
        if ( $content === '' ) {
            return [];
        }

        $ids = [];

        // Attributi shortcode che contengono liste di ID: images="1,2,3",
        // include="1,2,3", image="123" (WPBakery, [gallery], ecc.).
        if ( preg_match_all( '/\b(?:images|include|image|ids)\s*=\s*["\']([0-9,\s]+)["\']/i', $content, $matches ) ) {
            foreach ( $matches[1] as $list ) {
                foreach ( preg_split( '/[,\s]+/', $list ) as $id ) {
                    if ( (int) $id ) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        // Tag <img> con classe wp-image-123 (editor classico/Gutenberg).
        if ( preg_match_all( '/wp-image-([0-9]+)/', $content, $matches ) ) {
            foreach ( $matches[1] as $id ) {
                if ( (int) $id ) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Salva il testo dedicato e le immagini selezionate per LinkedIn.
     *
     * @param int $post_id
     *
     * @return void
     */
    public function save_linkedin_meta( int $post_id ): void {
        if ( ! isset( $_POST['twp_linkedin_meta_nonce'] ) || ! wp_verify_nonce( $_POST['twp_linkedin_meta_nonce'], 'twp_linkedin_save_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['twp_linkedin_content'] ) ) {
            update_post_meta( $post_id, '_twp_linkedin_content', sanitize_textarea_field( wp_unslash( $_POST['twp_linkedin_content'] ) ) );
        }

        $images = isset( $_POST['twp_linkedin_images'] ) ? (array) $_POST['twp_linkedin_images'] : [];
        $images = array_values( array_filter( array_map( 'intval', $images ) ) );
        update_post_meta( $post_id, '_twp_linkedin_images', $images );
    }

    #[NoReturn] public function publish_to_linkedin(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Non hai i permessi per eseguire questa operazione.' );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'twp_linkedin_publish' ) ) {
            wp_die( 'Nonce non valido.' );
        }

        $post_id = intval( $_GET['post_id'] );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            wp_die( 'Post non trovato.' );
        }


        $access_token    = get_option( 'twp_linkedin_access_token' );
        $organization_id = get_option( 'twp_linkedin_organization_id' );

        if ( ! $access_token || ! $organization_id ) {
            wp_die( 'Non sei autenticato con LinkedIn.' );
        }

        $organization_urn = 'urn:li:organization:' . $organization_id;
        $link_url         = get_permalink( $post_id );

        $post_text  = $this->get_linkedin_text( $post_id );
        $image_urls = $this->get_linkedin_image_urls( $post_id );

        if ( count( $image_urls ) > self::MAX_IMAGES ) {
            $this->redirect_with_error( $post_id, sprintf(
                'Hai selezionato %d immagini: LinkedIn ne consente al massimo %d. Deseleziona le eccedenti e riprova.',
                count( $image_urls ),
                self::MAX_IMAGES
            ) );
        }

        //
        // 1. + 2. REGISTER UPLOAD E CARICAMENTO DI OGNI IMMAGINE
        //
        $asset_urns = [];
        foreach ( $image_urls as $image_url ) {
            $asset_urns[] = $this->register_and_upload_image( $post_id, $organization_urn, $access_token, $image_url );
        }

        //
        // 3. PUBBLICA IL POST (CON O SENZA IMMAGINI)
        //
        $share_content = [
            'shareCommentary'    => [ 'text' => $post_text . "\n\n" . $link_url ],
            'shareMediaCategory' => ! empty( $asset_urns ) ? 'IMAGE' : 'NONE',
        ];

        if ( ! empty( $asset_urns ) ) {
            $share_content['media'] = array_map( static function ( $asset_urn ) use ( $post_text ) {
                return [
                    'status' => 'READY',
                    'media'  => $asset_urn,
                    'title'  => [ 'text' => $post_text ],
                ];
            }, $asset_urns );
        }

        $post_body = [
            'author'          => $organization_urn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => $share_content
            ],
            'visibility'      => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
            ]
        ];

        $publish_response = wp_remote_post( 'https://api.linkedin.com/v2/ugcPosts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode( $post_body )
        ] );

        if ( is_wp_error( $publish_response ) ) {
            $this->redirect_with_error( $post_id, 'Errore nella pubblicazione: ' . $publish_response->get_error_message() );
        }

        $final_code = wp_remote_retrieve_response_code( $publish_response );
        $final_body = wp_remote_retrieve_body( $publish_response );

        if ( $final_code !== 201 && $final_code !== 200 ) {
            $this->redirect_with_error( $post_id, 'Errore risposta finale LinkedIn: ' . $final_body );
        }

        $redirect_url = add_query_arg( [
            'post'                => $post_id,
            'action'              => 'edit',
            'twp_linkedin_status' => 'success'
        ], admin_url( 'post.php' ) );

        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Testo del post LinkedIn: contenuto dedicato se presente, altrimenti l'excerpt.
     *
     * @param int $post_id
     *
     * @return string
     */
    private function get_linkedin_text( int $post_id ): string {
        $custom_content = get_post_meta( $post_id, '_twp_linkedin_content', true );

        return ( is_string( $custom_content ) && trim( $custom_content ) !== '' )
            ? $custom_content
            : (string) get_the_excerpt( $post_id );
    }

    /**
     * URL delle immagini da pubblicare: quelle selezionate nel meta box,
     * altrimenti fallback all'immagine in evidenza.
     *
     * @param int $post_id
     *
     * @return string[]
     */
    private function get_linkedin_image_urls( int $post_id ): array {
        $image_urls      = [];
        $selected_images = get_post_meta( $post_id, '_twp_linkedin_images', true );

        if ( is_array( $selected_images ) ) {
            foreach ( $selected_images as $att_id ) {
                $img_url = wp_get_attachment_url( (int) $att_id );
                if ( $img_url ) {
                    $image_urls[] = $img_url;
                }
            }
        }

        if ( empty( $image_urls ) ) {
            $thumb_url = get_the_post_thumbnail_url( $post_id );
            if ( $thumb_url ) {
                $image_urls[] = $thumb_url;
            }
        }

        return $image_urls;
    }

    /**
     * Simula la pubblicazione (dry-run) senza creare il post né caricare asset su LinkedIn.
     * Verifica: permessi, autenticazione, validità del token (chiamata read-only),
     * testo, categoria media e raggiungibilità/tipo di ogni immagine.
     *
     * @return void
     */
    #[NoReturn] public function dry_run_to_linkedin(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Non hai i permessi per eseguire questa operazione.' );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'twp_linkedin_dryrun' ) ) {
            wp_die( 'Nonce non valido.' );
        }

        $post_id = intval( $_GET['post_id'] );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            wp_die( 'Post non trovato.' );
        }

        $report = [];
        $ok     = true;

        $access_token    = get_option( 'twp_linkedin_access_token' );
        $organization_id = get_option( 'twp_linkedin_organization_id' );

        // Autenticazione.
        if ( ! $access_token || ! $organization_id ) {
            $ok       = false;
            $report[] = [ 'status' => 'error', 'label' => 'Autenticazione', 'detail' => 'Access token o Organization ID mancanti. Collega LinkedIn nelle impostazioni.' ];
        } else {
            $report[] = [ 'status' => 'ok', 'label' => 'Autenticazione', 'detail' => 'Token presente. Organization ID: ' . esc_html( $organization_id ) . '.' ];

            // Validità del token: chiamata read-only alla stessa API usata in fase di login.
            $token_check = wp_remote_get( 'https://api.linkedin.com/v2/organizationalEntityAcls?q=roleAssignee&role=ADMINISTRATOR', [
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
                'timeout' => 15,
            ] );

            if ( is_wp_error( $token_check ) ) {
                $ok       = false;
                $report[] = [ 'status' => 'error', 'label' => 'Validità token', 'detail' => 'Errore di rete: ' . esc_html( $token_check->get_error_message() ) ];
            } else {
                $check_code = wp_remote_retrieve_response_code( $token_check );
                if ( $check_code === 200 ) {
                    $report[] = [ 'status' => 'ok', 'label' => 'Validità token', 'detail' => 'Token valido (LinkedIn ha risposto 200).' ];
                } else {
                    $ok       = false;
                    $report[] = [ 'status' => 'error', 'label' => 'Validità token', 'detail' => 'LinkedIn ha risposto ' . intval( $check_code ) . '. Il token potrebbe essere scaduto: riconnetti l\'account.' ];
                }
            }
        }

        // Testo.
        $post_text = $this->get_linkedin_text( $post_id );
        $full_text = $post_text . "\n\n" . get_permalink( $post_id );
        if ( trim( $post_text ) === '' ) {
            $report[] = [ 'status' => 'warn', 'label' => 'Testo', 'detail' => 'Nessun testo dedicato né excerpt: il post conterrà solo il link.' ];
        } else {
            $report[] = [ 'status' => 'ok', 'label' => 'Testo', 'detail' => nl2br( esc_html( $full_text ) ) ];
        }

        // Immagini.
        $selected_meta  = get_post_meta( $post_id, '_twp_linkedin_images', true );
        $selected_count = is_array( $selected_meta ) ? count( array_filter( array_map( 'intval', $selected_meta ) ) ) : 0;
        $image_urls     = $this->get_linkedin_image_urls( $post_id );
        $count          = count( $image_urls );

        // Origine delle immagini: selezione salvata oppure fallback all'immagine in evidenza.
        if ( $selected_count > 0 ) {
            $report[] = [ 'status' => 'ok', 'label' => 'Selezione salvata', 'detail' => sprintf( '%d immagine/i spuntata/e e salvata/e nel post.', $selected_count ) ];
        } else {
            $report[] = [ 'status' => 'warn', 'label' => 'Selezione salvata', 'detail' => 'Nessuna immagine selezionata risulta salvata: verrà usata l\'immagine in evidenza come fallback. Se avevi spuntato delle immagini, <strong>Salva/Aggiorna il post</strong> prima di simulare: i pulsanti Simula/Pubblica non salvano le modifiche in corso.' ];
        }

        if ( $count === 0 ) {
            $report[] = [ 'status' => 'warn', 'label' => 'Immagini', 'detail' => 'Nessuna immagine: il post sarà pubblicato come solo testo (shareMediaCategory NONE).' ];
        } elseif ( $count > self::MAX_IMAGES ) {
            $ok       = false;
            $report[] = [ 'status' => 'error', 'label' => 'Immagini', 'detail' => sprintf( 'Selezionate %d immagini: LinkedIn ne consente al massimo %d. La pubblicazione verrebbe bloccata.', $count, self::MAX_IMAGES ) ];
        } else {
            $report[] = [ 'status' => 'ok', 'label' => 'Immagini', 'detail' => sprintf( '%d immagine/i (shareMediaCategory IMAGE, entro il limite di %d).', $count, self::MAX_IMAGES ) ];
        }

        // Raggiungibilità e tipo di ogni immagine (fino al limite).
        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/gif' ];
        foreach ( array_slice( $image_urls, 0, self::MAX_IMAGES ) as $i => $image_url ) {
            $head = wp_remote_head( $image_url, [ 'timeout' => 15, 'redirection' => 3 ] );
            $n    = $i + 1;

            if ( is_wp_error( $head ) ) {
                $ok       = false;
                $report[] = [ 'status' => 'error', 'label' => "Immagine {$n}", 'detail' => 'Non raggiungibile: ' . esc_html( $head->get_error_message() ) . ' — ' . esc_html( $image_url ) ];
                continue;
            }

            $img_code = wp_remote_retrieve_response_code( $head );
            $mime     = wp_remote_retrieve_header( $head, 'content-type' );

            if ( $img_code !== 200 ) {
                $ok       = false;
                $report[] = [ 'status' => 'error', 'label' => "Immagine {$n}", 'detail' => 'HTTP ' . intval( $img_code ) . ' — ' . esc_html( $image_url ) ];
            } elseif ( $mime && ! in_array( strtolower( explode( ';', $mime )[0] ), $allowed_mimes, true ) ) {
                $report[] = [ 'status' => 'warn', 'label' => "Immagine {$n}", 'detail' => 'Tipo non standard (' . esc_html( $mime ) . '): LinkedIn potrebbe rifiutarla. — ' . esc_html( $image_url ) ];
            } else {
                $report[] = [ 'status' => 'ok', 'label' => "Immagine {$n}", 'detail' => esc_html( $mime ?: 'raggiungibile' ) . ' — ' . esc_html( $image_url ) ];
            }
        }

        $report[] = [
            'status' => 'info',
            'label'  => 'Nota',
            'detail' => 'Questa è una simulazione: nessun asset è stato caricato e nessun post è stato pubblicato su LinkedIn. La registrazione/upload degli asset e la creazione del post vengono eseguite solo con "Pubblica su LinkedIn".',
        ];

        set_transient( 'twp_linkedin_dryrun_' . get_current_user_id(), [ 'ok' => $ok, 'items' => $report ], 120 );

        $redirect_url = add_query_arg( [
            'post'                 => $post_id,
            'action'               => 'edit',
            'twp_linkedin_dryrun'  => $ok ? 'ok' : 'fail',
        ], admin_url( 'post.php' ) );

        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Mostra il report del dry-run come admin notice dopo il redirect.
     *
     * @return void
     */
    public function maybe_show_dryrun_notice(): void {
        if ( ! isset( $_GET['twp_linkedin_dryrun'] ) ) {
            return;
        }

        $data = get_transient( 'twp_linkedin_dryrun_' . get_current_user_id() );
        if ( ! $data || empty( $data['items'] ) ) {
            return;
        }
        delete_transient( 'twp_linkedin_dryrun_' . get_current_user_id() );

        $class = ! empty( $data['ok'] ) ? 'notice-success' : 'notice-error';
        $icons = [ 'ok' => '✅', 'warn' => '⚠️', 'error' => '❌', 'info' => 'ℹ️' ];

        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible">';
        echo '<p><strong>🧪 Dry-run LinkedIn — ' . ( ! empty( $data['ok'] ) ? 'nessun problema bloccante rilevato' : 'rilevati problemi da correggere' ) . '</strong></p>';
        echo '<ul style="margin-left:1em;list-style:disc;">';
        foreach ( $data['items'] as $item ) {
            $icon = $icons[ $item['status'] ] ?? '•';
            // I detail sono già passati da esc_html/nl2br dove necessario.
            echo '<li>' . $icon . ' <strong>' . esc_html( $item['label'] ) . ':</strong> ' . wp_kses( $item['detail'], [ 'br' => [], 'strong' => [] ] ) . '</li>';
        }
        echo '</ul></div>';
    }

    /**
     * Registra e carica una singola immagine su LinkedIn, restituendo l'asset URN.
     * In caso di errore reindirizza con messaggio (non ritorna).
     *
     * @param int    $post_id
     * @param string $organization_urn
     * @param string $access_token
     * @param string $image_url
     *
     * @return string Asset URN dell'immagine caricata.
     */
    private function register_and_upload_image( int $post_id, string $organization_urn, string $access_token, string $image_url ): string {
        $register_body = [
            'registerUploadRequest' => [
                'owner'                    => $organization_urn,
                'recipes'                  => [ 'urn:li:digitalmediaRecipe:feedshare-image' ],
                'supportedUploadMechanism' => [ 'SYNCHRONOUS_UPLOAD' ],
                'serviceRelationships'     => [
                    [
                        'relationshipType' => 'OWNER',
                        'identifier'       => 'urn:li:userGeneratedContent'
                    ]
                ]
            ]
        ];

        $register_response = wp_remote_post( 'https://api.linkedin.com/v2/assets?action=registerUpload', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode( $register_body )
        ] );

        if ( is_wp_error( $register_response ) ) {
            $this->redirect_with_error( $post_id, 'Errore nella registerUpload: ' . $register_response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $register_response );
        if ( $code !== 200 && $code !== 201 ) {
            $this->redirect_with_error( $post_id, 'Errore LinkedIn registerUpload: ' . wp_remote_retrieve_body( $register_response ) );
        }

        $register_data = json_decode( wp_remote_retrieve_body( $register_response ), true );
        $upload_url    = $register_data['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];
        $asset_urn     = $register_data['value']['asset'];

        $image_data = wp_remote_get( $image_url );
        if ( is_wp_error( $image_data ) ) {
            $this->redirect_with_error( $post_id, 'Errore nel download immagine: ' . $image_data->get_error_message() );
        }

        $image_body = wp_remote_retrieve_body( $image_data );

        if ( empty( $image_body ) ) {
            $this->redirect_with_error( $post_id, 'Errore: immagine scaricata vuota' );
        }

        $upload_response = wp_remote_request( $upload_url, [
            'method'  => 'PUT',
            'headers' => [
                'Content-Type' => 'application/octet-stream'
            ],
            'body'    => $image_body
        ] );

        if ( is_wp_error( $upload_response ) ) {
            $this->redirect_with_error( $post_id, 'Errore nell\'upload dell\'immagine: ' . $upload_response->get_error_message() );
        }
        $upload_code = wp_remote_retrieve_response_code( $upload_response );
        if ( $upload_code !== 201 && $upload_code !== 200 ) {
            $this->redirect_with_error( $post_id, 'Errore upload immagine (LinkedIn): ' . wp_remote_retrieve_body( $upload_response ) );
        }

        return $asset_urn;
    }

    /**
     * Reindirizza alla pagina di modifica del post con un messaggio di errore.
     *
     * @param int    $post_id
     * @param string $message
     * @return void
     */
    #[NoReturn] private function redirect_with_error( int $post_id, string $message ): void {
        // Log error if possible or pass a simplified error status
        error_log( "LinkedIn Publisher Error: " . $message );

        $redirect_url = add_query_arg( [
            'post'                => $post_id,
            'action'              => 'edit',
            'twp_linkedin_status' => 'error'
        ], admin_url( 'post.php' ) );

        wp_redirect( $redirect_url );
        exit;
    }
}

new TWP_LinkedIn_Publisher();