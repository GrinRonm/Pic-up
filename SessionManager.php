<?php
/**
 * Session Management Helper
 */

require_once __DIR__ . '/vendor/autoload.php';

use Aura\Session\SessionFactory;

class SessionManager {
    private static $instance = null;
    private $session;
    private $segment;

    private function __construct() {
        $session_factory = new SessionFactory;
        $this->session = $session_factory->newInstance($_COOKIE);
        
        // Configure cookie to live for a long time (e.g., 10 years)
        $this->session->setCookieParams([
            'lifetime' => 10 * 365 * 24 * 60 * 60,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        $this->segment = $this->session->getSegment('ImgHost');
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function start() {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }
    }

    public function getSessionId() {
        $this->start();
        return $this->session->getId();
    }

    public function getSegment() {
        return $this->segment;
    }
}
