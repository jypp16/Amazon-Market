<?php

namespace Libraries\Core;

class Controller {

    protected $views, $model;

    public function __construct() {
        $this->views = new Views();
        $this->loadModel();
    }

    public function loadModel() {
        $className = get_class($this);
        $modelName = str_replace('Controllers\\', 'Models\\', $className);
        $modelName = str_replace('Controller', 'Model', $modelName);
        if(class_exists($modelName)) {
            $this->model = new $modelName();
        }
    }
}