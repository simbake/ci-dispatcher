<?php
namespace Dispatcher;

interface HttpResponseInterface
{
    public function getStatusCode();

    public function setStatusCode($code);

    public function getContent();

    public function setContent($content);

    public function getContentType();

    public function setContentType($type);

    public function getViews();

    public function setViews(array $views);

    public function getData();

    public function setData(array $data);
}