<?php
namespace Dispatcher\Tests;

use Dispatcher\Tests\Stub\SimpleControllerMock;

class DispatchableControllerTest extends \PHPUnit_Framework_Testcase
{
    /**
     * @var \Dispatcher\DispatchableController
     */
    private $controller;

    public function setUp()
    {
        $this->controller = new SimpleControllerMock();
        if ($this->controller === NULL) {
            $this->fail();
        }
    }

    public function test_GET_OnRequestWithSimpleControllerStub_ShouldReturn200ResponseWithIndexViews()
    {
        $requestMock = $this->getMock('Dispatcher\\HttpRequestInterface');
        $response = $this->controller->get($requestMock);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertContains('index', $response->getViews());
    }
}
