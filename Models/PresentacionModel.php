<?php

namespace Models;

use Libraries\Core\Model;

class PresentacionModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'presentacion';
        $this->primaryKey = 'id_presentacion';
    }
}
