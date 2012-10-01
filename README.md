CodeIgniter-Dispatcher (WIP)
============================


Introduction:
-------------

CodeIgniter-Dispatcher uses CodeIgniter's _remap function to do an extra routing
to a class-based controller instead of function-based.


Example:
--------

#### CodeIgniter's function-based controller: ####

```php
<?php

class Welcome extends CI_Controller
{
    public function index()
    {
        $this->load->view('welcome_message', array('title' => 'Welcome!'));
    }
}
```

#### With CodeIgniter-Dispatcher's class-based controller: ####

```php
<?php

class Index extends Dispatcher\DispatchableController
{
    protected $views = 'welcome_message';

    public function get($request)
    {
        return $this->renderView(array('title' => 'Welcome!'));
    }
}
```
