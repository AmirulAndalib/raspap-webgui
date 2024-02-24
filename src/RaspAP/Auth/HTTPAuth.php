<?php

/**
 * Authentication class
 *
 * @description Basic HTTP authentication class for RaspAP
 * @author      Bill Zimmerman <billzimmerman@gmail.com>
 * @license     https://github.com/raspap/raspap-webgui/blob/master/LICENSE
 * @see         https://www.php.net/manual/en/features.http-auth.php
 */

declare(strict_types=1);

namespace RaspAP\Auth;

class HTTPAuth
{

    /**
     * @var string $realm
     */
    public $realm = 'Authentication Required';

    /**
     * Stored login credentials
     * @var array $auth_config
     */
    protected $auth_config;

    /**
     * Default login credentials
     * @var array $auth_default
     */
    private $auth_default = array(
        'admin_user' => 'admin',
        'admin_pass' => '$2y$10$YKIyWAmnQLtiJAy6QgHQ.eCpY4m.HCEbiHaTgN6.acNC6bDElzt.i'
    );

    // Constructor
    public function __construct()
    {
        $this->auth_config = $this->getAuthConfig();
    }

    /*
     * Determines if user is logged in
     * return boolean
     */
    public function isLogged()
    {
        return isset($_SESSION['user_id']);
    }

    /*
     * Authenticate a user using HTTP basic auth
     */
    public function authenticate()
    {
        if (!$this->isLogged()) {
            header('HTTP/1.0 401 Unauthorized');
            header('WWW-Authenticate: Basic realm="'.$this->realm.'"');
            if (function_exists('http_response_code')) {
                // http_response_code will respond with proper HTTP version
                http_response_code(401);
            } else {
                header('HTTP/1.0 401 Unauthorized');
            }
            exit('Not authorized'.PHP_EOL);
        }
    }

    /*
     * Attempt to login a user with supplied credentials
     * @var string $user
     * @var string $pass
     * return boolean
     */
    public function login(string $user, string $pass)
    {
        if ($this->isValidCredentials($user, $pass)) {
            $_SESSION['user_id'] = $user;
            return true;
        }
        return false;
    }

    /*
     * Gets the current authentication config
     * return array $config
     */
    public function getAuthConfig()
    {
        $config = $this->auth_default;

        if (file_exists(RASPI_CONFIG . '/raspap.auth')) {
            if ($auth_details = fopen(RASPI_CONFIG . '/raspap.auth', 'r')) {
                $config['admin_user'] = trim(fgets($auth_details));
                $config['admin_pass'] = trim(fgets($auth_details));
                fclose($auth_details);
            }
        }
        return $config;
    }

    /*
     * Validates a set of credentials
     * @var string $user
     * @var string $pass
     * return boolean
     */
    protected function isValidCredentials(string $user, string $pass)
    {
        return $this->validateUser($user) && $this->validatePassword($pass);
    }

    /**
     * Validates a user
     *
     * @param string $user
     */
    protected function validateUser(string $user)
    {
        return $user == $this->auth_config['admin_user'];
    }

    /**
     * Validates a password
     *
     * @param string $pass
     */
    protected function validatePassword(string $pass)
    {
        return password_verify($pass, $this->auth_config['admin_pass']);
    }

}
