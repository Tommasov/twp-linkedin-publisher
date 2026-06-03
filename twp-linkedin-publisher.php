<?php
/**
 * Plugin Name: TWP - LinkedIn Publisher
 * Description: A lightweight WordPress plugin that allows manual publishing of posts to a LinkedIn Company Page using OAuth2 authentication. It adds a "Publish to LinkedIn" button to the post editor sidebar.
 * Version: 1.1
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
        add_action( 'admin_post_twp_publish_to_linkedin', [ $this, 'publish_to_linkedin' ] );

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

        echo '<a href="' . esc_url( $link ) . '" class="button button-primary">🔗 Pubblica su LinkedIn</a>';
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
        $post_text        = get_the_excerpt( $post_id );
        $link_url         = get_permalink( $post_id );
        $image_url        = get_the_post_thumbnail_url( $post_id );

        $asset_urn = '';

        //
        // 1. REGISTER UPLOAD (Solo se c'è un'immagine)
        //
        if ( $image_url ) {
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

            //
            // 2. SCARICA IMMAGINE E REINVIA SU LINKEDIN
            //
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
        }

        //
        // 3. PUBBLICA IL POST (CON O SENZA IMMAGINE)
        //
        $share_content = [
            'shareCommentary'    => [ 'text' => $post_text . "\n\n" . $link_url ],
            'shareMediaCategory' => $asset_urn ? 'IMAGE' : 'NONE',
        ];

        if ( $asset_urn ) {
            $share_content['media'] = [
                [
                    'status' => 'READY',
                    'media'  => $asset_urn,
                    'title'  => [ 'text' => $post_text ]
                ]
            ];
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