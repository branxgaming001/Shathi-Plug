<?php
/**
 * REST Server — registers all API routes and dispatches to controllers.
 *
 * @package NeerMedia\Sathi\Rest
 */

namespace NeerMedia\Sathi\Rest;

class RestServer {

    /** @var string API namespace */
    public const NAMESPACE = 'sathi/v1';

    /** @var ChatController */
    private ChatController $chat;

    /** @var SettingsController */
    private SettingsController $settings;

    /** @var KnowledgeController */
    private KnowledgeController $knowledge;

    /** @var PersonasController */
    private PersonasController $personas;

    /** @var MemoryController */
    private MemoryController $memory;

    /** @var CommerceController */
    private CommerceController $commerce;

    /** @var LicenseController */
    private LicenseController $license;

    /** @var PlaygroundController */
    private PlaygroundController $playground;

    public function __construct() {
        $this->chat       = new ChatController();
        $this->settings   = new SettingsController();
        $this->knowledge  = new KnowledgeController();
        $this->personas   = new PersonasController();
        $this->memory     = new MemoryController();
        $this->commerce   = new CommerceController();
        $this->license    = new LicenseController();
        $this->playground = new PlaygroundController();
    }

    /**
     * Register all REST routes.
     */
    public function register_routes(): void {
        $this->chat->register_routes();
        $this->settings->register_routes();
        $this->knowledge->register_routes();
        $this->personas->register_routes();
        $this->memory->register_routes();
        $this->commerce->register_routes();
        $this->license->register_routes();
        $this->playground->register_routes();

        do_action( 'sathi_rest_routes_registered' );
    }
}
