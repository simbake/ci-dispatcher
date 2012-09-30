<?php
namespace Dispatcher;

use stdClass;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionException;

/**
 * Re-routes incoming uri to class based controller instead of CI's default
 * function based controller
 */
class BootstrapController extends \CI_Controller
{
    /**
     * Array of middlewares to handle before and after the dispatch.
     * @var array
     */
    private $_middlewares = array();

    /**
     * Whether to show/hide debug info
     * @var boolean
     */
    private $_debug = FALSE;

    /**
     * Dependency injection container (IoC)
     * @var DIContainer
     */
    private $container;

    /**
     * Creates a new instance of Dispatcher.
     */
    public function __construct()
    {
        parent::__construct();

        foreach ($this->getDispatcherConfig() as $k => $v) {
            if (property_exists($this, '_' . $k)) {
                $this->{'_' . $k} = $v;
            }
        }

        $this->container = $this->createContainer(
            $this->getDependenciesConfig());
    }

    /**
     * This will be called by CodeIgniter.php to remap to user defined function.
     * <i>Note: We'll use this to remap to our class-based controller.</i>
     *
     * @param $method string The CodeIgniter controller function to be called.
     * @param $uri    array  Array of uri segments
     * @throws \Exception
     */
    public function _remap($method, $uri)
    {
        $request = $this->createHttpRequest();

        if (!$request instanceof HttpRequestInterface) {
            throw new \Exception(
                'object must implements \Dispatcher\HttpRequestInterface');
        }

        $middlewares = $this->loadMiddlewares();
        foreach ($middlewares as $m) {
            $m->processRequest($request);
        }

        array_unshift($uri, $method);
        $response = $this->dispatch($uri, $request);

        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $middlewares[$i]->processResponse($response);
        }

        $this->renderResponse($request, $response);
    }

    /**
     * Loads and returns the Dispatcher configuration.
     * @return array The Dispatcher configuration array
     */
    protected function getDispatcherConfig()
    {
        $config = array();
        require(APPPATH . 'config/dispatcher.php');
        return $config;
    }

    /**
     * Loads and returns the dependency container configuration.
     * @return array The dependency configuration for DIContainer
     */
    protected function getDependenciesConfig()
    {
        $config = array();
        require(APPPATH . 'config/dependencies.php');
        return $config;
    }

    /**
     * Creates the concrete {@link \Dispatcher\HttpRequestInterface} object.
     * @return \Dispatcher\HttpRequestInterface
     */
    protected function createHttpRequest()
    {
        static $request = NULL;
        if ($request === NULL) {
            $request = new HttpRequest();
            $this->container['request'] = $request;
        }
        return $request;
    }

    /**
     * Creates the DIContainer with the given configuration.
     * @param  array $config The dependency configuration
     * @return \Dispatcher\DIContainer
     */
    protected function createContainer(array $config = array())
    {
        $container = new DIContainer();

        $containerCfg = isset($config['container'])
            ? $config['container']
            : array();

        $sharedContainerCfg = isset($config['sharedContainer'])
            ? $config['sharedContainer']
            : array();

        foreach ($containerCfg as $k => $v) {
            $container[$k] = $v;
        }

        foreach ($sharedContainerCfg as $k => $v) {
            $container->share($k, $v);
        }

        return $container;
    }

    /**
     * Renders the final response base on the `$request` and `$response`.
     * @param $request  \Dispatcher\HttpRequestInterface   the incoming request object
     * @param $response HttpResponse  the outgoing response object
     */
    protected function renderResponse(HttpRequestInterface $request,
                                      HttpResponse $response)
    {
        $this->output->set_content_type($response->contentType);

        foreach ($response->header as $k => $v) {
            $this->output->set_header($k.': '.$v);
        }

        if ($response->statusCode !== 200) {
            $this->output->set_status_header($response->statusCode);
        }


        // TODO: respect to the `Accept` header?
        if ($response instanceof Error404Response) {
            show_404();
        } else if ($response instanceof ViewTemplateResponse) {
            foreach ($response->views as $v) {
                $this->load->view($v, $response->contextData);
            }
        } else if ($response instanceof RawHtmlResponse) {
            $this->output->set_output($response->content);
        } else if ($response instanceof JsonResponse) {
            $content = (is_array($response->content)
                       || is_object($response->content))
                       ? json_encode($response->content)
                       : '';
            $this->output->set_output($content);
        }
    }

    /**
     * Takes the incoming uri and request and returns a `HttpResponse`
     */
    protected function dispatch($uri, HttpRequestInterface $request)
    {
        // Gets the class infomation that we will dispatching to
        $classInfo = $this->_getClassInfo($uri);

        // 404 page if we cannot find any assocaited class info
        if ($classInfo === NULL) {
            log_message('debug', '404 due to unknown classInfo for '.
                implode(',', $request->getUriArray()));
            return new Error404Response();
        }

        // Finally, let's load the class and dispatch it
        $class = $this->_loadClass($classInfo->classPath,
            $classInfo->className);

        // see what is the requested method, e.g. 'GET', 'POST' and etc...
        try {
            $req_method = new ReflectionMethod(
                $classInfo->className,
                strtolower($request->getMethod()));

            if (count($classInfo->params) >
                count($req_method->getParameters()) - 1) {
                log_message('debug', '404 due to not enough expected params');
                return new Error404Response();
            }
        } catch (ReflectionException $ex) {
            log_message('error', 'Unable to reflect on method');
            return new Error404Response();
        }


        // TODO: Maybe a DispatchableResource for RESTful API?
        if ($class instanceof DispatchableController) {
            $response = $this->_dispatchAsController(
                $class, $classInfo, $request);
        } else {
            $response = new Error404Response();
        }

        return $response;
    }

    /**
     * Loads and returns an array of middleware instance.
     * @return array(DispatchableMiddleware)
     */
    protected function loadMiddlewares()
    {
        $middlewares = array();
        foreach ($this->_middlewares as $name) {
            $mw = NULL;
            if (class_exists($name)) {
                $mw = $this->_loadClass('', $name);
            } else {
                $paths = explode('/', $name);
                $name = array_pop($paths);
                $name = ucwords(preg_replace(
                    '/[_]+/', ' ', strtolower(trim($name))));
                $name = preg_replace('/[\s]+/', '_', trim($name));

                $parts = array_merge(
                    array(rtrim(APPPATH, '/'), 'middlewares'),
                    $paths,
                    array(strtolower($name).EXT)
                );
                $clspath = implode('/', $parts);
                $mw = $this->_loadClass($clspath, $name);
            }

            if ($mw instanceof DispatchableMiddleware) {
                $middlewares[] = $mw;
            }
        }

        return $middlewares;
    }

    /**
     * Gets the dispatched class information base on the incoming `$uri` array.
     *
     * `$classInfo` is a standard php object with 3 properties:
     * - className
     * - classPath
     * - classParams
     * @param  array    $routes The uri array
     * @return stdClass         Class info
     */
    private function _getClassInfo($routes)
    {
        $path = APPPATH . 'controllers'; // default path to look for the class

        $classInfo = NULL;

        // We always take the first element in `$routes`
        // and try to see if the file exists with the same name
        while ($r = array_shift($routes)) {
            $path .= '/' . $r;

            if (is_file($path . EXT)) {
                // if file exists,
                // we assume that the uri is mapped to this class
                $classInfo = new stdClass;


                // Taken from the inflector helper
                // it normalizes the uri name into
                // camelized word with underscore
                // e.g.  myname -> Myname, your_name -> Your_Name
                $huamnized = ucwords(preg_replace(
                                '/[_]+/', ' ', strtolower(trim($r))));
                $underscored = preg_replace('/[\s]+/', '_', trim($huamnized));

                $classInfo->className = $underscored;
                $classInfo->classPath = $path.EXT;
                $classInfo->params = $routes;
            } else if (is_file($path.'/index'.EXT)) {
                // see if we have an index.php in the mapped uri directory
                $classInfo = new stdClass;
                $classInfo->className = 'Index';
                $classInfo->classPath = $path.'/index'.EXT;
                $classInfo->params = $routes;
            }
        }

        return $classInfo;
    }

    /**
     * Loads and returns an instance of $className
     *
     * @param  $classPath string The file path of the class
     * @param  $className string The class to be loaded
     * @throws \Exception
     * @return object            The instance of $className
     */
    private function _loadClass($classPath, $className)
    {
        if (file_exists($classPath)) {
            require_once($classPath);
        }

        if (!class_exists($className)) {
            return NULL;
        }

        $clsReflect = new ReflectionClass($className);
        $expectedParams = $clsReflect->getConstructor()->getParameters();

        $deps = array();
        foreach ($expectedParams as $param) {
            $depName = $param->getName();

            try {
                $deps[] = $this->container[$param->getName()];
            } catch (\InvalidArgumentException $ex) {
                die("$depName is not found in your dependencies.php");
            }
        }

        $class = $clsReflect->newInstanceArgs($deps);
        return $class;
    }

    /**
     * Dispatches the incoming `$request` by using `DispatchableController`.
     */
    private function _dispatchAsController(DispatchableController $class,
                                           $classInfo,
                                           HttpRequest $request)
    {
        $params = array_unshift($classInfo->params, $request);
        if (!$this->_debug) {
            set_error_handler(function() {
                throw new Exception('Hacky exception to hide the CI '.
                                    'error handler message');
            });
            try {
                // dispatch and get the response
                $response = call_user_func_array(array(
                        $class, $request->getMethod()),
                    $classInfo->params);
            } catch (Exception $ex) {
                log_message('debug', '404 due to '.$ex->getMessage());
                return new Error404Response();
            }
            restore_error_handler();
        } else {
            // dispatch and get the response
            $response = call_user_func_array(array(
                    $class, $request->getMethod()),
                $classInfo->params);
        }

        return $response;
    }

    private function _dispatchAsResource($class,
                                         $classInfo,
                                         HttpRequest $request)
    {
        // TODO: different way to dispatch restful resource
    }
}