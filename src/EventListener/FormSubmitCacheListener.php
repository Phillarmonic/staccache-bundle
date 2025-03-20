<?php

namespace Phillarmonic\StaccacheBundle\EventListener;

use Phillarmonic\StaccacheBundle\Cache\EntityCacheManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Listener to update entity cache after form submissions
 */
class FormSubmitCacheListener implements EventSubscriberInterface
{
    private EntityCacheManager $cacheManager;

    public function __construct(EntityCacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::POST_SUBMIT => 'onPostSubmit',
        ];
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        // Only process root form and valid submissions
        if (!$form->isRoot() || !$form->isValid()) {
            return;
        }

        $data = $form->getData();

        // Only process objects
        if (!is_object($data)) {
            return;
        }

        // If the entity is cacheable, update its cache
        if ($this->cacheManager->isCacheable($data)) {
            $this->cacheManager->cacheEntity($data);
        }
    }
}