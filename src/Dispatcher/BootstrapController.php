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
     * This will be called by CodeIgniter.php to remap to user defined function.
     * <i>Note: We'll use this to remap to our class-based controller.</i>
     *
     * @param $method string The CodeIgniter controller function to be called.
     * @param $uri    array  Array of uri segments
     * @throws \Exception Exception thrown if request does not implement
     *                    {@link \Dispatcher\HttpRequestInterface}
     */
    public function _remap($method, $uri)
    {
        $this->initializeConfig();

        $request = $this->createHttpRequest();

        if (!$request instanceof HttpRequestInterface) {
            throw new \Exception(
                'object must implements \Dispatcher\HttpRequestInterface');
        }

        $middlewares = $this->loadMiddlewares();
        foreach ($middlewares as $m) {
            if (method_exists($m, 'processRequest')) {
                $m->processRequest($request);
            }
        }

        array_unshift($uri, $method);
        $response = $this->dispatch($uri, $request);

        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            if (method_exists($middlewares[$i], 'processResponse')) {
                $middlewares[$i]->processResponse($response);
            }
        }

        $this->renderResponse($request, $response);
    }

    protected function initializeConfig()
    {
        foreach ($this->loadDispatcherConfig() as $k => $v) {
            if (property_exists($this, '_' . $k)) {
                $this->{'_' . $k} = $v;
            }
        }
        $this->container = $this->createContainer(
            $this->loadDependenciesConfig());
    }

    /**
     * Loads and returns the Dispatcher configuration.
     * @return array The Dispatcher configuration array
     */
    protected function loadDispatcherConfig()
    {
        $config = array();
        require(APPPATH . 'config/dispatcher.php');
        return $config;
    }

    /**
     * Loads and returns the dependency container configuration.
     * @return array The dependency configuration for DIContainer
     */
    protected function loadDependenciesConfig()
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

    protected function renderResponse(HttpRequestInterface $request,
                                      HttpResponseInterface $response)
    {
        $this->output->set_content_type($response->getContentType());

        foreach ($response->getHeaders() as $k => $v) {
            $this->output->set_header($k . ': ' . $v);
        }

        if ($response->getStatusCode() !== 200) {
            $this->output->set_status_header($response->getStatusCode());
        }

        // TODO: respect to the `Accept` header?
        if ($response instanceof Error404Response) {
            show_404();
        } else if ($response instanceof ViewTemplateResponse) {
            foreach ($response->getViews() as $v) {
                $this->load->view($v, $response->getData());
            }
        } else if ($response instanceof RawHtmlResponse) {
            $this->output->set_output($response->getContent());
        } else if ($response instanceof JsonResponse) {
            $data = $response->getData();
            $content = (is_array($data) || is_object($data))
                        ? json_encode($data) : '';
            $this->output->set_output($content);
        }
    }

    /**
     * Dispatches the incoming request to the proper resource.
     * @param array                $uri     The incoming resource URI in array
     * @param HttpRequestInterface $request The incoming request object
     * @return \Dispatcher\HttpResponseInterface
     */
    protected function dispatch($uri, HttpRequestInterface $request)
    {
        // gets the class infomation that we will be dispatching to
        $classInfo = $this->loadClassInfoOn($uri);

        // 404 page if we cannot find any assocaited class info
        if ($classInfo === null) {
            log_message('debug', '404 due to unknown classInfo for '.
                implode(',', $request->getUriArray()));
            return new Error404Response();
        }

        // Finally, let's load the class and dispatch it
        $controller = $this->loadClass($classInfo->getName(),
            $classInfo->getPath());

        if (!$controller instanceof DispatchableInterface) {
            return new Error404Response();
        }

        return $controller->doDispatch($request, $classInfo->getParams(),
            !$this->_debug);
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
                $clspath = '';
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
            }

            $mw = $this->loadClass($name, $clspath);
            if ($mw !== null) {
                $middlewares[] = $mw;
            }
        }

        return $middlewares;
    }

    protected function loadClassInfoOn(array $routes)
    {
        $path = APPPATH . 'controllers'; // default path to look for the class

        $classInfo = NULL;

        // We always take the first element in `$routes`
        // and try to see if the file exists with the same name
        while ($r = array_shift($routes)) {
            $path .= DIRECTORY_SEPARATOR . $r;

            if (is_file($path . EXT)) {
                // if file exists,
                // we assume that the uri is mapped to this class


                // Taken from the inflector helper
                // it normalizes the uri name into
                // camelized word with underscore
                // e.g.  myname -> Myname, your_name -> Your_Name
                $huamnized = ucwords(preg_replace(
                    '/[_]+/', ' ', strtolower(trim($r))));
                $underscored = preg_replace('/[\s]+/', '_', trim($huamnized));

                $classInfo = new ClassInfo($underscored, $path . EXT, $routes);
            } else if (is_file($path . '/index' . EXT)) {
                // see if we have an index.php in the mapped uri directory
                $classInfo = new ClassInfo('Index', $path . '/index' . EXT,
                    $routes);
            }
        }

        return $classInfo;
    }

    /**
     * Loads and returns an instance of $className with the given $classPath.
     * <i>Note: The default implementation uses Reflection to inject
     * dependencies into constructor from the dependencies config.</i>
     *
     * @param  $className string The class to be loaded
     * @param  $classPath string The optional file path of the class
     * @return object|null       The instance of the class, or null if failed
     */
    protected function loadClass($className, $classPath = '')
    {
        if (file_exists($classPath)) {
            require_once($classPath);
        }

        if (!class_exists($className)) {
            return NULL;
        }

        $clsReflect = new ReflectionClass($className);
        $ctor = $clsReflect->getConstructor();

        // if constructor found, get all the parameters
        // for dependency injection
        $expectedParams = array();
        if ($ctor) {
            $expectedParams = $ctor->getParameters();
        }

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
}
