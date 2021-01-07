<?php
/**
 * @Author: Adrien Pavie
 */

namespace App\Admin;

use Sonata\AdminBundle\Form\FormMapper;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class ConfigurationOsmAdmin extends ConfigurationAbstractAdmin
{
    protected $baseRouteName = 'gogo_core_bundle_config_osm_admin_classname';

    protected $baseRoutePattern = 'gogo/core/configuration-osm';

    protected function configureFormFields(FormMapper $formMapper)
    {
        $dm = $this->getModelManager()->getDocumentManager('App\Document\Configuration');

        $formMapper
            ->with('Compte d\'instance', ['description' => "Pour permettre l'édition vers OpenStreetMap, renseignez un compte utilisateur ci-dessous. Si vous n'avez pas de compte, vous pouvez en créer un sur <a href='https://www.openstreetmap.org/user/new'>le site d'OpenStreetMap</a>."])
                ->add('osm.osmUsername', TextType::class, ['label' => 'Nom d\'utilisateur', 'required' => false])
                ->add('osm.osmPassword', PasswordType::class, ['label' => 'Mot de passe', 'required' => false])
            ->end()
        ;
    }
}