<?php

namespace barrelstrength\sproutforms\rules\conditions;

use barrelstrength\sproutforms\base\BaseCondition;

class IsNotCondition extends BaseCondition
{
    public $fieldRule;

    public function getLabel(): string
    {
        return 'is not';
    }

    public function getValue(): string
    {
        return 'isNot';
    }

    public function getValueInputHtml($name)
    {
        if ($this->fieldRule instanceof BaseCondition){

        }else{

        }

        return "";
    }
}