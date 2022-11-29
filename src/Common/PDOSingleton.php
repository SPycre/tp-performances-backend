<?php

namespace App\Common;
use PDO;

class PDOSingleton {

    private PDO $pdo;
    private static PDOSingleton $instance;

    private function __construct ($bdd,$user,$pass) {
        $this->pdo = new PDO($bdd,$user,$pass);
    }

    public static function getInstance () : PDO {
        if ( ! isset( self::$instance ) )
            self::$instance = new PDOSingleton("mysql:host=db;dbname=tp;charset=utf8mb4", "root", "root");
        return self::$instance->pdo;
    }

}