<?php
namespace Dispatcher\Tests;

use Dispatcher\JsonResponse;

class BootstrapControllerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function _remap_OnAnyUri_ShouldCallRenderResponseWithRequestAndResponse()
    {
        // setup mock
        $controller = $this->getMock('Dispatcher\\BootstrapController',
            array('loadMiddlewares', 'dispatch', 'renderResponse'));
        $controller->expects($this->any())
            ->method('loadMiddlewares')
            ->will($this->returnValue(array()));
        $controller->expects($this->any())
            ->method('dispatch')
            ->with($this->logicalOr(
                $this->equalTo(array('method', 'api', 'v1', 'books')),
                $this->equalTo(array('method', 'index')),
                $this->equalTo(array('method'))))
            ->will($this->returnValue(\Dispatcher\JsonResponse::create()));
        $controller->expects($this->any())
            ->method('renderResponse')
            ->with($this->isInstanceOf('Dispatcher\\HttpRequestInterface'),
                   $this->isInstanceOf('Dispatcher\\HttpResponseInterface'));

        // run
        $controller->_remap('method', array('api', 'v1', 'books'));
        $controller->_remap('method', array('index'));
        $controller->_remap('method', array());
    }

    /**
     * @test
     */
    public function loadMiddleware_IncludesNamespace_ShouldCallLoadClass()
    {
        $ctrl = $this->getMock('Dispatcher\\BootstrapController',
            array('dispatch', 'renderResponse',
                'loadDispatcherConfig', 'loadClass'));

        $ctrl->expects($this->once())
            ->method('dispatch')
            ->will($this->returnValue(JsonResponse::create()));

        $ctrl->expects($this->once())
            ->method('loadDispatcherConfig')
            ->will($this->returnValue(array(
                'middlewares' => array(
                    'Dispatcher\\Tests\Stub\\MiddlewareSpy'
                ),
                'debug' => false)));

        $arg0Constrains = $this->logicalAnd(
            $this->equalTo('Dispatcher\\Tests\Stub\\MiddlewareSpy'),
            $this->classHasAttribute('processRequestCalled'),
            $this->classHasAttribute('processResponseCalled'));

        $ctrl->expects($this->once())
            ->method('loadClass')
            ->with($arg0Constrains, $this->isEmpty());

        $ctrl->_remap('method', array('api', 'v1', 'books'));
    }

    /**
     * @test
     */
    public function loadMiddleware_WithoutNamespace_ShouldDefaultToMiddlewareDir()
    {
        $ctrl = $this->getMock('Dispatcher\\BootstrapController',
            array('dispatch', 'renderResponse',
                'loadDispatcherConfig', 'loadClass'));

        $ctrl->expects($this->once())
            ->method('dispatch')
            ->will($this->returnValue(JsonResponse::create()));

        $ctrl->expects($this->once())
            ->method('loadDispatcherConfig')
            ->will($this->returnValue(array(
            'middlewares' => array(
                'filters/debug_filter'
            ),
            'debug' => false)));

        $ctrl->expects($this->once())
            ->method('loadClass')
            ->with($this->equalTo('Debug_Filter'),
                   $this->equalTo(
                       APPPATH . 'middlewares/filters/debug_filter.php'));

        $ctrl->_remap('method', array('api', 'v1', 'books'));
    }

    /**
     * @test
     */
    public function dispatch_OnNonexistentURI_ShouldReturnError404Response()
    {
        $ctrl = $this->getMock('Dispatcher\\BootstrapController',
            array('renderResponse'));

        $ctrl->expects($this->once())
            ->method('renderResponse')
            ->with($this->isInstanceOf('Dispatcher\\HttpRequestInterface'),
                   $this->isInstanceOf('Dispatcher\\Error404Response'));

        $ctrl->_remap('method', array('api', 'v1', 'books'));
    }

    /**
     * @test
     */
    public function dispatch_OnExistentURI_ShouldReturnNormalResponse()
    {
        $reqMock = $this->getMock('Dispatcher\\HttpRequest', array('getMethod'));
        $reqMock->expects($this->any())
            ->method('getMethod')
            ->will($this->returnValue('GET'));

        $dispatchableController = $this->getMockForAbstractClass(
            'Dispatcher\\DispatchableController',
            array(),
            '',
            true,
            true,
            true,
            array('getViews'));
        $dispatchableController->expects($this->once())
            ->method('getViews')
            ->will($this->returnValue(array('index')));


        $ctrl = $this->getMock('Dispatcher\\BootstrapController',
            array('renderResponse', 'loadClassInfoOn',
                  'loadClass', 'createHttpRequest'));

        $ctrl->expects($this->once())
            ->method('renderResponse')
            ->with($this->isInstanceOf('Dispatcher\\HttpRequestInterface'),
                   $this->isInstanceOf('Dispatcher\\ViewTemplateResponse'));

        $ctrl->expects($this->once())
            ->method('createHttpRequest')
            ->will($this->returnValue($reqMock));

        $ctrl->expects($this->once())
            ->method('loadClassInfoOn')
            ->will($this->returnValue(new \Dispatcher\ClassInfo('Books', '')));

        $ctrl->expects($this->once())
            ->method('loadClass')
            ->will($this->returnValue($dispatchableController));

        $ctrl->_remap('method', array('api', 'v1', 'books'));
    }
}
