<?php

namespace Controllers;

use Libraries\Core\Controller;

class ErrorController extends Controller {
    public function index() {
        $this->views->render($this, "error404");
    }
}