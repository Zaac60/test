<?php

namespace Biopen\GeoDirectoryBundle\Controller\Admin;

use Sonata\AdminBundle\Controller\CRUDController as Controller;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Biopen\GeoDirectoryBundle\Document\ElementStatus;
use Biopen\GeoDirectoryBundle\Document\OptionValue;
use Biopen\GeoDirectoryBundle\Document\UserInteractionContribution;

class ElementAdminController extends Controller
{
    public function redirectEditAction()
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }

        return $this->redirect($this->generateUrl('biopen_element_edit', array('id' => $object->getId())));
    }

    public function redirectShowAction()
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }

        return $this->redirect(str_replace('%23', '#', $this->generateUrl('biopen_directory_showElement', array('name' => $object->getName(), 'id'=>$object->getId()))));
    }

    public function redirectBackAction()
    {
        return $this->redirect($this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters())));
    }

    public function batchActionDelete(ProxyQueryInterface $selectedModelQuery)
    {
        return $this->batchStatus(ElementStatus::Deleted, $selectedModelQuery);
    }

    public function batchActionValidation(ProxyQueryInterface $selectedModelQuery)
    {
        return $this->batchStatus(ElementStatus::AdminValidate, $selectedModelQuery);
    }

    public function batchActionRefusal(ProxyQueryInterface $selectedModelQuery)
    {
        return $this->batchStatus(ElementStatus::AdminRefused, $selectedModelQuery);
    }

    private function batchStatus($status, ProxyQueryInterface $selectedModelQuery)
    {
        $this->admin->checkAccess('edit');
        $this->admin->checkAccess('delete');

        $request = $this->get('request')->request;
        $modelManager = $this->admin->getModelManager();

        $selectedModels = $selectedModelQuery->execute();
        $nbreModelsToProceed = $selectedModels->count();        
        $selectedModels->limit(5000);

        $mailService = $this->container->get('biopen.mail_service');
        $sendMails = !($request->has('dont-send-mail') && $request->get('dont-send-mail'));            

        try {
            // getting the document manager (quite ugly way) so we can control better the flush and clear
            $documentManager = $modelManager->getDocumentManager($selectedModels->getNext());

            $i = 0;
            foreach ($selectedModels as $selectedModel) {
                $selectedModel->setStatus($status);
                if ($sendMails) 
                {
                    $mailService->sendAutomatedMail($request->get('action'), $selectedModel, $request->get('comment'));
                }

                if ((++$i % 20) == 0) {
                    $documentManager->flush();
                    $documentManager->clear();
                }
            }

            $documentManager->flush();
            $documentManager->clear();
        } catch (\Exception $e) {
            $this->addFlash('sonata_flash_error', 'Une erreur est survenue :' . $e->getMessage());

            return new RedirectResponse(
                $this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters()))
            );
        }

        $statusMessage = [
            '-4'=>'Supprimés', 
            '-2'=>'Réfusés', 
            '1' => 'Validés (admin)'
        ];       
        
        $this->addFlash('sonata_flash_success', 'Les '. min([$nbreModelsToProceed,5000]) .' élements ont bien été ' . $statusMessage[$status]);

        if ($nbreModelsToProceed >= 5000)
            $this->addFlash('sonata_flash_info', "Trop d'éléments à traiter ! Seulement 5000 ont été traités");

        return new RedirectResponse(
            $this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters()))
        );
    }

    public function batchActionSendMail(ProxyQueryInterface $selectedModelQuery) 
    {        
        $selectedModels = $selectedModelQuery->execute();
        $nbreModelsToProceed = $selectedModels->count();
        $selectedModels->limit(5000);

        $request = $this->get('request')->request;
        

        $mailsSent = 0;
        $elementWithoutEmail = 0;
            
        try {
            foreach ($selectedModels as $element) {
                $mail = $element->getMail();
                if ($mail) 
                {
                    $mails[] = $mail;
                }
                else 
                {
                    $elementWithoutEmail++;
                }
                
            }
        } catch (\Exception $e) {
            $this->addFlash('sonata_flash_error', 'ERROR : ' . $e->getMessage());
            return new RedirectResponse($this->admin->generateUrl('list', $this->admin->getFilterParameters()));
        }

        if (!$request->get('mail-subject') || !$request->get('mail-content'))
        {
            $this->addFlash('sonata_flash_error', "Vous devez renseigner un objet et un contenu. Veuillez recommencer");
        }
        else if (count($mails) > 0)
        {
            $mailService = $this->container->get('biopen.mail_service');
            $mailService->sendMail(null, $request->get('mail-subject'), $request->get('mail-content'), $request->get('from'), $mails);
            $this->addFlash('sonata_flash_success', count($mails) . ' mails ont bien été envoyés');
        } 
        
        if ($elementWithoutEmail > 0)
            $this->addFlash('sonata_flash_error', $elementWithoutEmail . " mails n'ont pas pu être envoyé car aucune adresse n'était renseignée");

        if ($nbreModelsToProceed >= 5000)
        {
            $this->addFlash('sonata_flash_info', "Trop d'éléments à traiter ! Seulement 5000 ont été traités");
        }

        return new RedirectResponse($this->admin->generateUrl('list', $this->admin->getFilterParameters()));
    }

    public function batchActionEditOptions(ProxyQueryInterface $selectedModelQuery) 
    {
        $this->admin->checkAccess('edit');

        $request = $this->get('request')->request;

        $optionstoRemoveIds = $request->get('optionsToRemove');
        $optionstoAddIds = $request->get('optionsToAdd');

        $modelManager = $this->admin->getModelManager();

        $selectedModels = $selectedModelQuery->execute();
        $nbreModelsToProceed = $selectedModels->count(); 

        $limit = 2000;       
        $selectedModels->limit($limit);          

        try {
            // getting the document manager (quite ugly way) so we can control better the flush and clear
            $documentManager = $modelManager->getDocumentManager($selectedModels->getNext());

            $i = 0;
            foreach ($selectedModels as $selectedModel) {
                $optionsValues = $selectedModel->getOptionValues()->toArray();
                if ($optionstoRemoveIds && count($optionstoRemoveIds) > 0)
                {                    
                    $optionValuesToBeRemoved = array_filter($optionsValues, function($oV) use($optionstoRemoveIds){ return in_array($oV->getOptionId(), $optionstoRemoveIds); });

                    foreach ($optionValuesToBeRemoved as $key => $optionValue) {
                        $selectedModel->removeOptionValue($optionValue);
                    }
                }

                if ($optionstoAddIds && count($optionstoAddIds) > 0)
                {
                    $optionValuesIds = array_map( function($x) { return $x->getOptionId(); }, $optionsValues);          

                    foreach ($optionstoAddIds as $key => $optionId) {
                        if (!in_array($optionId, $optionValuesIds))
                        {
                            $optionValue = new OptionValue();
                            $optionValue->setOptionId($optionId);
                            $selectedModel->addOptionValue($optionValue);
                        }
                    }  
                }              

                if ((++$i % 20) == 0) {
                    $documentManager->flush();
                    $documentManager->clear();
                }
            }

            $documentManager->flush();
            $documentManager->clear();
        } catch (\Exception $e) {
            $this->addFlash('sonata_flash_error', 'Une erreur est survenue :' . $e->getMessage());

            return new RedirectResponse(
                $this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters()))
            );
        }      
        
        $this->addFlash('sonata_flash_success', 'Les catégories des '. min([$nbreModelsToProceed,$limit]) .' élements ont bien été mis à jour');

        if ($nbreModelsToProceed >= $limit)
            $this->addFlash('sonata_flash_info', "Trop d'éléments à traiter ! Seulement " . $limit . " ont été traités");

        return new RedirectResponse(
            $this->admin->generateUrl('list', array('filter' => $this->admin->getFilterParameters()))
        );
    }

    public function showEditAction($id = null)
    {
        $request = $this->getRequest();

        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id : %s', $id));
        }

        $this->admin->checkAccess('edit', $object);
        $this->admin->setSubject($object);

        /** @var $form Form */
        $form = $this->admin->getForm();
        $form->setData($object);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {

            //TODO: remove this check for 4.0
            if (method_exists($this->admin, 'preValidate')) {
                $this->admin->preValidate($object);
            }
            $isFormValid = $form->isValid();

            // persist if the form was valid and if in preview mode the preview was approved
            if ($isFormValid) {
                try {
                    $contribution = new UserInteractionContribution();
                    $contribution->updateUserInformation($this->container->get('security.context'));
                    $contribution->setType(InteractionType::Edit);
                    $contribution->setStatus(ElementStatus::ModifiedByAdmin);
                    $object->addContribution($contribution);

                    $object = $this->admin->update($object);

                    $this->addFlash(
                        'sonata_flash_success',
                        $this->trans(
                            'flash_edit_success',
                            array('%name%' => $this->escapeHtml($this->admin->toString($object))),
                            'SonataAdminBundle'
                        )
                    );

                    if ($request->get('submit_redirect')) 
                        return new RedirectResponse(
                            $this->admin->generateUrl('list')
                        );

                } catch (ModelManagerException $e) {
                    $this->handleModelManagerException($e);

                    $isFormValid = false;
                } catch (LockException $e) {
                    $this->addFlash('sonata_flash_error', $this->trans('flash_lock_error', array(
                        '%name%' => $this->escapeHtml($this->admin->toString($object)),
                        '%link_start%' => '<a href="'.$this->admin->generateObjectUrl('edit', $object).'">',
                        '%link_end%' => '</a>',
                    ), 'SonataAdminBundle'));
                }
            }

            // show an error message if the form failed validation
            if (!$isFormValid) {
                if (!$this->isXmlHttpRequest()) {
                    $this->addFlash(
                        'sonata_flash_error',
                        $this->trans(
                            'flash_edit_error',
                            array('%name%' => $this->escapeHtml($this->admin->toString($object))),
                            'SonataAdminBundle'
                        )
                    );
                }
            }
        }

        $view = $form->createView();

        // set the theme for the current Admin Form
        $this->get('twig')->getExtension('form')->renderer->setTheme($view, $this->admin->getFormTheme());

        return $this->render('BiopenGeoDirectoryBundle:admin:edit_element.html.twig', array(
            'action' => 'edit',
            'form' => $view,
            'object' => $object,
            'elements' => $this->admin->getShow(),
        ), null);
    }}