<?php
namespace Dispatcher;

interface ResourceOptionInterface
{
    public static function create();
    public function getAllowedMethods();
    public function setAllowedMethods(array $methods);
    public function getDefaultFormat();
    public function setDefaultFormat($format);
    public function getSupportedFormats();
    public function setSupportedFormats(array $formats);
    public function getAllowedFields();
    public function setAllowedFields(array $fields);
}
