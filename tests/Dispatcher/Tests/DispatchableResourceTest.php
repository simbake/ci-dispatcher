<?php
namespace Dispatcher\Tests;

class DispatchableResourceTest extends \PHPUnit_Framework_TestCase
{
    public function mockRequest($method)
    {
        $mock = $this->getMock('Dispatcher\\Http\\HttpRequest',
            array('getMethod', 'getAcceptableContentTypes'));
        $mock->expects($this->any())
            ->method('getMethod')
            ->will($this->returnValue($method));
        $mock->expects($this->any())
            ->method('getAcceptableContentTypes')
            ->will($this->returnValue(array('application/watever', '*/*', 'application/json')));
        return $mock;
    }

    /**
     * @test
     */
    public function invoke_get_without_uri_segments_should_invoke_readCollection_with_ResourceBundle_as_argument()
    {
        $reqMock = $this->mockRequest('GET');

        $controller = $this->getMock(
            'Dispatcher\\DispatchableResource',
            array('readCollection'));

        $controller->expects($this->once())
            ->method('readCollection')
            ->with($this->isInstanceOf('Dispatcher\\Common\\ResourceBundle'));

        $controller->get($reqMock);
    }

    /**
     * @test
     */
    public function invoke_get_without_uri_segments_and_without_readCollection_should_throw_DispatchingException_with_response()
    {
        $reqMock = $this->mockRequest('GET');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('some'));
        $controller->expects($this->never())
            ->method('some');

        try {
            $controller->get($reqMock);
        } catch (\Dispatcher\Exception\DispatchingException $ex) {
            $this->assertNotNull($ex->getResponse());
            return;
        }

        $this->fail();
    }

    /**
     * @test
     */
    public function invoke_get_with_schema_as_uri_argument_should_invoke_readSchema()
    {
        $reqMock = $this->mockRequest('GET');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('readSchema'));

        $controller->expects($this->once())
            ->method('readSchema');

        $controller->get($reqMock, array('schema'));
    }

    /**
     * @test
     */
    public function invoke_get_with_uri_arguments_should_invoke_readObject()
    {
        $reqMock = $this->mockRequest('GET');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('readObject'));

        $controller->expects($this->once())
            ->method('readObject');

        $controller->get($reqMock, array('some-id'));
    }

    /**
     * @test
     */
    public function response_for_readCollection_on_get_should_have_correct_paginated_meta_and_objects()
    {
        $reqMock = $this->mockRequest('GET');

        $options = new \Dispatcher\Common\DefaultResourceOptions();
        $options->setPageLimit(2);

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('readCollection', 'getOptions'));

        $controller->expects($this->any())
            ->method('getOptions')
            ->will($this->returnValue($options));

        $controller->expects($this->once())
            ->method('readCollection')
            ->will($this->returnValue(array(
                array('username' => 'user1'),
                array('username' => 'user2'),
                array('username' => 'user3')
            )));


        $response = $controller->get($reqMock);
        $this->assertEquals(
            '{"meta":{"offset":0,"limit":2,"total":3},"objects":[{"username":"user1"},{"username":"user2"}]}',
            $response->getContent());
    }

    /**
     * @test
     */
    public function get_for_readObject_should_have_correct_serialized_contents_in_response()
    {
        $reqMock = $this->mockRequest('GET');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('readObject'));

        $controller->expects($this->once())
            ->method('readObject')
            ->will($this->returnValue(
                array('username' => 'someone', 'id' => 5)));

        $response = $controller->get($reqMock, array('id'));
        $this->assertEquals(
            '{"username":"someone","id":5}',
            $response->getContent());
    }

    /**
     * @test
     */
    public function createResponse_should_have_correct_content_type_from_request()
    {
        $reqMock = $this->mockRequest('GET');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('readObject'));

        $controller->expects($this->once())
            ->method('readObject')
            ->will($this->returnValue(
                array('username' => 'someone', 'id' => 5)));

        $response = $controller->get($reqMock, array('id'));
        $this->assertEquals('application/json', $response->getContentType());
    }

    /**
     * @test
     */
    public function doDispatch_should_throw_HttpErrorException_with_406_response_for_no_supported_formats()
    {
        $reqMock = $this->getMock('Dispatcher\\Http\\HttpRequest',
            array('getMethod', 'getAcceptableContentTypes'));
        $reqMock->expects($this->any())
            ->method('getMethod')
            ->will($this->returnValue('GET'));
        $reqMock->expects($this->any())
            ->method('getAcceptableContentTypes')
            ->will($this->returnValue(array('text/some-crazy-formats')));

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('some'));
        $controller->expects($this->never())
            ->method('some');

        try {
            $response = $controller->doDispatch($reqMock, array('id'));
        } catch (\Dispatcher\Http\Exception\HttpErrorException $ex) {
            $this->assertEquals(406, $ex->getResponse()->getStatusCode());
            return;
        }

        $this->fail('Expected HttpErrorException');
    }

    /**
     * @test
     */
    public function resource_not_found_in_readObject_should_have_404_in_response()
    {
        $reqMock = $this->mockRequest('GET');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('readObject'));

        $controller->expects($this->once())
            ->method('readObject')
            ->will($this->throwException(
                new \Dispatcher\Exception\ResourceNotFoundException()));


        $response = $controller->get($reqMock, array('id'));

        $this->assertEquals(
            '{"error":"Not Found"}',
            $response->getContent());
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function invoke_post_without_uri_segments_should_invoke_writeObject()
    {
        $reqMock = $this->mockRequest('POST');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('createObject'));
        $controller->expects($this->once())
            ->method('createObject');

        $controller->post($reqMock);
    }

    /**
     * @test
     */
    public function invoke_post_with_uri_segments_should_return_method_not_allowed_response()
    {
        $reqMock = $this->mockRequest('POST');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('createObject'));
        $controller->expects($this->never())
            ->method('createObject');

        $response = $controller->post($reqMock, array('someid'));
        $this->assertEquals(405, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function response_for_createObject_should_have_location_header()
    {
        $reqMock = $this->mockRequest('POST');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('createObject'));
        $controller->expects($this->once())
            ->method('createObject')
            ->will($this->returnValue(array('id' => 'someid')));

        $response = $controller->post($reqMock);
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertNotNull($response->getHeader('Location'));
    }

    /**
     * @test
     */
    public function invoke_put_with_2_uri_segments_should_return_method_not_allowed()
    {
        $reqMock = $this->mockRequest('PUT');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('updateObject'));
        $controller->expects($this->never())
            ->method('updateObject');

        $response = $controller->put($reqMock, array('id', 'sub'));
        $this->assertEquals(405, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function invoke_put_with_uri_should_trigger_updateObject()
    {
        $reqMock = $this->mockRequest('PUT');

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('updateObject'));
        $controller->expects($this->once())
            ->method('updateObject');

        $controller->put($reqMock, array('0302'));
    }

    /**
     * @test
     */
    public function readSchema_should_return_basic_options()
    {
        $reqMock = $this->mockRequest('GET');
        $options = new \Dispatcher\Common\DefaultResourceOptions();
        $options->setAllowedMethods(array('GET', 'POST'))
                ->setSupportedFormats(array('application/lolformat'));

        $controller = $this->getMock('Dispatcher\\DispatchableResource',
            array('getOptions'));
        $controller->expects($this->any())
            ->method('getOptions')
            ->will($this->returnValue($options));

        $response = $controller->get($reqMock, array('schema'));
        $this->assertEquals(preg_replace('/[\s]+/', '', '
            {
                "meta": {
                    "defaultFormat": "application\/lolformat",
                    "supportedFormats": [
                        "application\/lolformat"
                    ],
                    "allowedMethods": [
                        "GET",
                        "POST"
                    ]
                }
            }
        '), $response->getContent());
    }

    /**
     * @test
     */
    public function applyHydrationOn_should_get_deserialized_json_data_from_bundle()
    {
        $req = new \Dispatcher\Http\DummyRequest(array(
            'rawContent' => '{"username":"someone"}',
            'contentType' => 'application/json'
        ));


        // Test subject
        $matcher = $this->logicalAnd(
            $this->isInstanceOf('Dispatcher\\Common\\ResourceBundle'),
            $this->attributeEqualTo('_attr', array('data' => array('username' => 'someone')))
        );


        $sut = $this->getMockBuilder('Dispatcher\\DispatchableResource')
            ->setMethods(array('applyHydrationOn'))
            ->getMock();
        $sut->expects($this->once())
            ->method('applyHydrationOn')
            ->with($matcher)
            ->will($this->throwException(new \InvalidArgumentException()));

        try {
            $sut->post($req);
        } catch (\InvalidArgumentException $ex) {
            return;
        }
        $this->fail();
    }

    /**
     * @test
     */
    public function applyHydrationOn_should_get_deserialized_xml_data_from_bundle()
    {
        $req = new \Dispatcher\Http\DummyRequest(array(
            'rawContent' => '<?xml version="1.0" encoding="utf-8"?><request><username>someone</username></request>',
            'contentType' => 'application/xml'
        ));


        // Test subject
        $matcher = $this->logicalAnd(
            $this->isInstanceOf('Dispatcher\\Common\\ResourceBundle'),
            $this->attributeEqualTo('_attr', array('data' => array('username' => 'someone')))
        );


        $sut = $this->getMockBuilder('Dispatcher\\DispatchableResource')
            ->setMethods(array('applyHydrationOn'))
            ->getMock();
        $sut->expects($this->once())
            ->method('applyHydrationOn')
            ->with($matcher)
            ->will($this->throwException(new \InvalidArgumentException()));

        try {
            $sut->post($req);
        } catch (\InvalidArgumentException $ex) {
            return;
        }
        $this->fail();
    }
}
