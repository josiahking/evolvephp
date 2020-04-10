<?php

/**
 * @see       https://github.com/josiahking/evolvephp for read me and documentation
 * @copyright https://github.com/josiahking/evolvephp/blob/master/COPYRIGHT.md
 * @license   https://github.com/josiahking/evolvephp/blob/master/LICENSE.md
 * @package EvolvePHP
 * @author 
 * @link Documentation on this file
 * @since Version 1.0
 * @filesource
 */

namespace EvolvePhpHelper;

/**
 * FormData Helper
 * This class helps handle submitted form data
 */
class FormData {
    
    protected $formData = null;
    
    public function setFormData(array $formData = [],bool $clean = true) 
    {
        if(empty($formData)){
            $formType = $this->getRequestMethod();
            if($formType == 'GET'){
                $formData = $_GET;
            }
            elseif($formType == 'POST'){
                $formData = $_POST;
            }
            else{
                throw new \DomainException('$formData is required. Request method is not supported.');
            }
        }
        if($clean){
            $this->formData = $this->clean($formData);
        }
        else{
            $this->formData = $formData;
        }
    }
    
    public function clean(array $formData,int $sanitizeOption = null) :array
    {
        if($sanitizeOption != null){
            return filter_var_array($formData,$sanitizeOption);
        }
        return filter_var_array($formData,FILTER_SANITIZE_STRING);
    }
    
    public function cleanField(string $value,int $sanitizeOption = null)
    {
        if($sanitizeOption != null){
            return filter_var($value,$sanitizeOption);
        }
        return filter_var($value,FILTER_SANITIZE_STRING);
    }
    
    public function getFormData() :array
    {
        return $this->formData;
    }
    
    public function getRequestMethod() : string
    {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    public function getField(string $name) :string
    {
        foreach ($this->getFormData() as $field => $value){
            if($field == $name){
                return $value;
            }
        }
        throw new \DomainException(var_export($name).'is not available in form data');
    }
    
    public function setField(string $name,string $value,bool $clean = true) 
    {
        if($clean){
            $this->formData[$name] = $this->cleanField($value);
        }
        else{
            $this->formData[$name] = $value;
        }
    }
}
