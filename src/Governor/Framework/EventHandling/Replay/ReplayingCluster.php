<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The software is based on the Axon Framework project which is
 * licensed under the Apache 2.0 license. For more information on the Axon Framework
 * see <http://www.axonframework.org/>.
 * 
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.governor-framework.org/>.
 */

namespace Governor\Framework\EventHandling\Replay;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Governor\Framework\EventHandling\EventProcessingMonitorInterface;
use Governor\Framework\EventStore\Management\EventStoreManagementInterface;
use Governor\Framework\EventStore\Management\CriteriaBuilderInterface;
use Governor\Framework\EventStore\Management\CriteriaInterface;
use Governor\Framework\EventHandling\ClusterInterface;
use Governor\Framework\EventHandling\EventListenerInterface;

/**
 * Description of ReplayingCluster
 *
 * @author    "David Kalosi" <david.kalosi@gmail.com>  
 * @license   <a href="http://www.opensource.org/licenses/mit-license.php">MIT License</a> 
 */
class ReplayingCluster implements ClusterInterface, LoggerAwareInterface
{

    const STATUS_LIVE = 0;
    const STATUS_REPLAYING = 1;
    const STATUS_PROCESSING_BACKLOG = 2;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ClusterInterface
     */
    private $delegate;

    /**
     * @var EventStoreManagementInterface
     */
    private $replayingEventStore;
    //private final int commitThreshold;
    private $incomingMessageHandler;

    /**
     * @var SplObjectStorage
     */
    private $replayAwareListeners; //new CopyOnWriteArraySet<ReplayAware>();
    private $status = self::STATUS_LIVE;
    private $eventHandlingListeners;

    public function __construct(ClusterInterface $delegate,
            EventStoreManagementInterface $eventStore,
            IncomingMessageHandlerInterface $incomingMessageHandler)
    {
        $this->delegate = $delegate;
        $this->replayingEventStore = $eventStore;
        $this->incomingMessageHandler = $incomingMessageHandler;

        $this->eventHandlingListeners = new EventProcessingListeners(array());
        $this->replayAwareListeners = new \SplObjectStorage();

        //this.delegate.subscribeEventProcessingMonitor(eventHandlingListeners);
    }

    /**
     * Returns a CriteriaBuilder that allows the construction of criteria for this EventStore implementation
     *
     * @return CriteriaBuilderInterface a builder to create Criteria for this Event Store.
     */
    public function newCriteriaBuilder()
    {
        return $this->replayingEventStore->newCriteriaBuilder();
    }

    public function startReplay(CriteriaInterface $criteria = null)
    {        
        $this->incomingMessageHandler->prepareForReplay($this->delegate);        
        $this->status = self::STATUS_REPLAYING;
        $visitor = new ReplayingEventVisitor($this->delegate, $this->logger);
                    
        foreach ($this->replayAwareListeners as $replayAwareListener) {            
            $replayAwareListener->beforeReplay();
        }
           
        $this->replayingEventStore->visitEvents($visitor, $criteria);
        
        foreach ($this->replayAwareListeners as $replayAwareListener) {            
            $replayAwareListener->afterReplay();
        }
        
        $this->status = self::STATUS_PROCESSING_BACKLOG;        
        $this->incomingMessageHandler->processBacklog($this->delegate);
        
        $this->status = self::STATUS_LIVE;
    }

    /**
     * Indicates whether this cluster is in replay mode. While in replay mode, EventMessages published to this cluster
     * are forwarded to the IncomingMessageHandler.
     *
     * @return boolean <code>true</code> if this cluster is in replay mode, <code>false</code> otherwise.
     */
    public function isInReplayMode()
    {
        return $this->status !== self::STATUS_LIVE;
    }

    public function getMembers()
    {
        return $this->delegate->getMembers();
    }

    public function getMetaData()
    {
        return $this->delegate->getMetaData();
    }

    public function getName()
    {
        return $this->delegate->getName();
    }

    public function publish(array $events)
    {
        if ($this->status === self::STATUS_LIVE) {
            $this->delegate->publish($events);
        } else {
            $this->logger->debug("Cluster is in replaying: sending message to process backlog");
            $acknowledgedMessages = $this->incomingMessageHandler->onIncomingMessages($this->delegate,
                    $events);
            if (null !== $acknowledgedMessages && !empty($acknowledgedMessages)) {
                $this->eventHandlingListeners->onEventProcessingCompleted($acknowledgedMessages);
            }
        }
    }

    public function subscribe(EventListenerInterface $eventListener)
    {             
        $this->delegate->subscribe($eventListener);

        if ($eventListener instanceof ReplayAwareInterface) {                   
            $this->replayAwareListeners->attach($eventListener);
        }
    }

    public function unsubscribe(EventListenerInterface $eventListener)
    {        
        if ($eventListener instanceof ReplayAwareInterface) {            
            $this->replayAwareListeners->detach($eventListener);
        }

        $this->delegate->unsubscribe($eventListener);
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function subscribeEventProcessingMonitor(EventProcessingMonitorInterface $monitor)
    {
        //$this->eventHandlingListeners-
    }

    public function unsubscribeEventProcessingMonitor(EventProcessingMonitorInterface $monitor)
    {
        
    }

}
