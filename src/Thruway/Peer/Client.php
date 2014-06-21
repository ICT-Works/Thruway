<?php
/**
 * Created by PhpStorm.
 * User: matt
 * Date: 6/7/14
 * Time: 11:58 AM
 */

namespace Thruway\Peer;


use Thruway\ClientSession;
use Thruway\Message\AbortMessage;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\ChallengeMessage;
use Thruway\Message\GoodbyeMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\WelcomeMessage;
use Thruway\Realm;
use Thruway\Role\AbstractRole;
use Thruway\Role\Callee;
use Thruway\Role\Caller;
use Thruway\Role\Publisher;
use Thruway\Role\Subscriber;
use Thruway\Transport\AbstractTransportProvider;
use Thruway\Transport\TransportInterface;
use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

/**
 * Class Client
 * @package Thruway
 */
class Client extends AbstractPeer implements EventEmitterInterface
{
    use EventEmitterTrait;

    /**
     * @var
     */
    private $roles;

    /**
     * @var Callee
     */
    private $callee;

    /**
     * @var Caller
     */
    private $caller;

    /**
     * @var Publisher
     */
    private $publisher;

    /**
     * @var Subscriber
     */
    private $subscriber;


    /**
     * @var AbstractTransportProvider
     */
    private $transportProvider;

    /**
     * @var ClientSession
     */
    private $session;

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\LoopInterface|\React\EventLoop\StreamSelectLoop
     */
    private $loop;

    /**
     * @var string
     */
    private $realm;

    /**
     * @var Array
     */
    private $authMethods;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @var int
     */
    private $retryTimer = 0;

    /**
     * @var array
     */
    private $reconnectOptions;

    /**
     * @var int
     */
    private $retryAttempts = 0;

    /**
     * @var bool
     */
    private $attemptRetry = true;

    /**
     * @param $realm
     * @param LoopInterface $loop
     */
    function __construct($realm, LoopInterface $loop = null)
    {
        $this->transportProvider = null;
        $this->roles = array();
        $this->realm = $realm;
        $this->authMethods = array();

        if ($loop === null) {
            $loop = Factory::create();
        }

        $this->loop = $loop;

        $this->reconnectOptions = [
            "max_retries" => 15,
            "initial_retry_delay" => 1.5,
            "max_retry_delay" => 300,
            "retry_delay_growth" => 1.5,
            "retry_delay_jitter" => 0.1 //not implemented
        ];
    }

    /**
     * @param AbstractRole $role
     * @return $this
     */
    public function addRole(AbstractRole $role)
    {

        if ($role instanceof Publisher):
            $this->publisher = $role;
        elseif ($role instanceof Subscriber):
            $this->subscriber = $role;
        elseif ($role instanceof Callee):
            $this->callee = $role;
        elseif ($role instanceof Caller):
            $this->caller = $role;
        endif;

        array_push($this->roles, $role);

        return $this;
    }

    /**
     * @param array $authMethod
     */
    public function addAuthMethod(Array $authMethod)
    {
        $this->authMethods = $this->authMethods + $authMethod;
    }

    /**
     * @param ClientSession $session
     */
    public function startSession(ClientSession $session)
    {
        $details = [
            "roles" => [
                "publisher" => new \stdClass(),
                "subscriber" => new \stdClass(),
                "caller" => new \stdClass(),
                "callee" => new \stdClass(),
            ]
        ];

        $details["authmethods"] = array_keys($this->authMethods);

        $this->addRole(new Callee())
            ->addRole(new Caller())
            ->addRole(new Publisher())
            ->addRole(new Subscriber());

        $session->setRealm($this->realm);

        $session->sendMessage(new HelloMessage($session->getRealm(), $details, array()));
    }

    /**
     * @param TransportInterface $transport
     */
    public function onOpen(TransportInterface $transport)
    {
        $this->retryTimer = 0;
        $this->retryAttempts = 0;
        $this->transport = $transport;
        $session = new ClientSession($transport, $this);
        $this->session = $session;
        $this->startSession($session);
    }

    /**
     * @param $reason
     */
    public function onClose($reason)
    {

        if (isset($this->session)) {
            $this->session->onClose();
            $this->session = null;
        }

        $this->roles = array();
        $this->callee = null;
        $this->caller = null;
        $this->subscriber = null;
        $this->publisher = null;

        $this->emit('close', [$reason]);

        $this->retryConnection();

    }

    /**
     * @param TransportInterface $transport
     * @param Message $msg
     */
    public function onMessage(TransportInterface $transport, Message $msg)
    {

        echo "Client onMessage!\n";

        $session = $this->session;

        if ($msg instanceof WelcomeMessage):
            $this->processWelcome($session, $msg);
        elseif ($msg instanceof AbortMessage):
            $this->processAbort($session, $msg);
        elseif ($msg instanceof GoodbyeMessage):
            $this->processGoodbye($session, $msg);
        //advanced
        elseif ($msg instanceof ChallengeMessage):
            $this->processChallenge($session, $msg);
        else:
            $this->processOther($session, $msg);
        endif;


    }

    /**
     * @param ClientSession $session
     * @param WelcomeMessage $msg
     */
    public function processWelcome(ClientSession $session, WelcomeMessage $msg)
    {
        //TODO: I'm sure that there are some other things that we need to do here
        $session->setSessionId($msg->getSessionId());
        $this->emit('open', [$session, $this->transport]);
    }

    /**
     * @param ClientSession $session
     * @param AbortMessage $msg
     */
    public function processAbort(ClientSession $session, AbortMessage $msg)
    {
        //TODO:  Implement this
    }

    /**
     * @param ClientSession $session
     * @param ChallengeMessage $msg
     */
    public function processChallenge(ClientSession $session, ChallengeMessage $msg)
    {

        $authmethod = $msg->getAuthMethod();
        $signature = $this->authMethods[$authmethod]['callback']($session, $authmethod, $msg->getExtra());

        $authenticateMsg = new AuthenticateMessage($signature);

        $session->sendMessage($authenticateMsg, $msg->getExtra());
    }

    /**
     * @param ClientSession $session
     * @param GoodbyeMessage $msg
     */
    public function processGoodbye(ClientSession $session, GoodbyeMessage $msg)
    {
        //TODO:  Implement this
    }

    /**
     * @param ClientSession $session
     * @param Message $msg
     */
    public function processOther(ClientSession $session, Message $msg)
    {
        /* @var $role AbstractRole */
        foreach ($this->roles as $role) {
            if ($role->handlesMessage($msg)) {
                $role->onMessage($session, $msg);
                break;
            }
        }
    }

    /**
     * @return Callee
     */
    public function getCallee()
    {
        return $this->callee;
    }


    /**
     * @return Caller
     */
    public function getCaller()
    {
        return $this->caller;
    }


    /**
     * @return Publisher
     */
    public function getPublisher()
    {
        return $this->publisher;
    }


    /**
     * @return Subscriber
     */
    public function getSubscriber()
    {
        return $this->subscriber;
    }


    /**
     * @return array
     */
    public function getRoles()
    {
        return $this->roles;
    }

    /**
     * @param AbstractTransportProvider $transportProvider
     * @throws \Exception
     */
    public function addTransportProvider(AbstractTransportProvider $transportProvider)
    {
        if ($this->transportProvider !== null) {
            throw new \Exception("You can only have one transport provider for a client");
        }
        $this->transportProvider = $transportProvider;
    }

    /**
     * Start the transport
     */
    public function start()
    {
        $this->transportProvider->startTransportProvider($this, $this->loop);

        $this->loop->run();
    }

    /**
     * Retry connecting to the transport
     */
    public function retryConnection()
    {
        $options = $this->reconnectOptions;

        if ($this->attemptRetry === false) {
            return;
        }

        if ($options['max_retries'] <= $this->retryAttempts) {
            return;
        }

        $this->retryAttempts++;

        if ($this->retryTimer >= $options['max_retry_delay']) {
            $this->retryTimer = $options['max_retry_delay'];
        } elseif ($this->retryTimer == 0) {
            $this->retryTimer = $options['initial_retry_delay'];
        } else {
            $this->retryTimer = $this->retryTimer * $options['retry_delay_growth'];
        }

        $this->loop->addTimer(
            $this->retryTimer,
            function () {
                $this->transportProvider->startTransportProvider($this, $this->loop);
            }
        );
    }

    /**
     * @param array $reconnectOptions
     */
    public function setReconnectOptions($reconnectOptions)
    {
        $this->reconnectOptions = array_merge($this->reconnectOptions, $reconnectOptions);
    }

    /**
     * @param boolean $attemptRetry
     */
    public function setAttemptRetry($attemptRetry)
    {
        $this->attemptRetry = $attemptRetry;
    }


}