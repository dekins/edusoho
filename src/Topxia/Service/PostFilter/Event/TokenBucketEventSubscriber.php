<?php
namespace Topxia\Service\PostFilter\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topxia\Common\StringToolkit;
use Topxia\Common\ArrayToolkit;
use Topxia\Service\Common\ServiceEvent;
use Topxia\Service\Common\ServiceKernel;

class TokenBucketEventSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
    {
        return array(
            'thread.before_create' => 'before',
            'thread.create' => 'incrToken',

            'thread.before_create_post' => 'before',
            'thread.post_create' => 'incrToken',

            'courseThread.before_create' => 'before',
            'courseThread.create' => 'incrToken',

            'courseThread.before_ceate_post' => 'before',
            'courseThread.post_create' => 'incrToken',
        );
    }

    public function before(ServiceEvent $event)
    {
        $currentUser = ServiceKernel::instance()->getCurrentUser();
        $currentIp = $currentUser->currentIp;

        $eventName = $event->getName();
        $names = explode('.', $eventName);

        if(!$this->getTokenBucketService()->hasToken($currentIp, $names[0])){
    	   $event->stopPropagation();
        }
    }

    public function incrToken(ServiceEvent $event)
    {
        $eventName = $event->getName();
        $names = explode('.', $eventName);

        $currentUser = ServiceKernel::instance()->getCurrentUser();
        $currentIp = $currentUser->currentIp;

        $this->getTokenBucketService()->incrToken($currentIp, $names[0]);
    }

    public function getTokenBucketService()
    {
    	return ServiceKernel::instance()->createService('PostFilter.TokenBucketService');
    }
}
