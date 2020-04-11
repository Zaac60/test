<?php

namespace App\Document\Configuration;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class ConfigurationSaas
{
    /**
    * Displayed on top of create project form
    *
    * @MongoDB\Field(type="string")
    */
    public $newProjectInstructions = null;

    /**
    * If present, users might agree those term when creating a new project
    *
    * @MongoDB\Field(type="string")
    */
    public $endUserLicenceAgreement = null;

    function getNewProjectInstructions() {
        return $this->newProjectInstructions;
    }

    function setNewProjectInstructions($value) {
        $this->newProjectInstructions = $value;
        return $this;
    }

    function getEndUserLicenceAgreement() {
        return $this->endUserLicenceAgreement;
    }

    function setEndUserLicenceAgreement($value) {
        $this->endUserLicenceAgreement = $value;
        return $this;
    }

}
