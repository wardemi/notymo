<?php
namespace nstdio\notymo;

use nstdio\notymo\exception\InvalidCert;

/**
 * Class APNSNotificationComponent
 */
class APNSNotification extends AbstractNotification
{
    /**
     * @var string APNS live server.
     */
    private $apnsHost = 'gateway.push.apple.com';

    /**
     * @var string APNS sandbox server
     */
    private $apnsSandboxHost = 'gateway.sandbox.push.apple.com';

    /**
     * @var int APNS server port to connect.
     */
    private $apnsPort = 2195;

    /**
     * @var string
     */
    private $apnsCert = 'apns-production.pem';

    /**
     * @var string
     */
    private $apnsSandboxCert = 'apns-dev.pem';

    /**
     * @var string
     */
    private $scheme = 'https';

    /**
     * @var bool Connect to APNS sandbox or live server.
     */
    private $live = false;

    /**
     * APNSNotification constructor.
     *
     * @param bool        $live
     * @param string|null $apnsCert
     * @param string|null $apnsSandboxCert
     *
     * @throws InvalidCert When cannot find one of certificate files.
     */
    public function __construct($live = false, $apnsCert = null, $apnsSandboxCert = null)
    {
        parent::__construct();
        $this->live = $live;

        if ($this->live && !is_readable($apnsCert)) {
            throw new InvalidCert("Cannot find certificate file: " . $apnsCert);
        }

        if (!$this->live && !is_readable($apnsSandboxCert)) {
            throw new InvalidCert("Cannot find certificate file: " . $apnsSandboxCert);
        }

        $this->apnsCert = $apnsCert;
        $this->apnsSandboxCert = $apnsSandboxCert;
    }

    /**
     * @throws \Exception
     */
    public function send()
    {
        if ($this->messageQueue->isEmpty()) {
            return;
        }

        $this->openConnection()
            ->write()
            ->close();
    }

    private function close()
    {
        $this->stream->close();
    }

    /**
     * Writes all data to the stream
     *
     * @return self
     */
    private function write()
    {
        /** @var MessageInterface $message */
        foreach ($this->messageQueue as $message) {
            if ($message->getType() !== MessageInterface::TYPE_IOS) {
                continue;
            }
            $payload = $this->createPayload($message);
            $binMsg = $this->createBinMessage($message, $payload);
            $this->stream->write(CURLOPT_POSTFIELDS, $binMsg);
            $this->stream->read();
        }

        return $this;
    }

    /**
     * @param MessageInterface $message
     *
     * @return string Тhe json encoded string
     */
    final protected function createPayload(MessageInterface $message)
    {
        $payload = array();
        $payload['aps'] = array(
            'alert' => $message->getMessage(),
            'badge' => $message->getBadge(),
            'sound' => $message->getSound(),
        );

        /**
         * @var MessageInterface $value
         */
        foreach ($message->getCustomData() as $key => $value) {
            $payload[$key] = $value;
        }


        return json_encode($payload);
    }

    /**
     * @param $message
     * @param $payload
     *
     * @return string
     */
    private function createBinMessage(MessageInterface $message, $payload)
    {
        $binMsg = '';
        if ($message->isMultiple()) {
            foreach ($message->getToken() as $token) {
                $binMsg .= $this->buildBinMessage($token, $payload);
            }
        } else {
            $binMsg = $this->buildBinMessage($message->getToken(), $payload);
        }

        return $binMsg;
    }

    /**
     * @param $token
     * @param $payload
     *
     * @return string
     *
     */
    private function buildBinMessage($token, $payload)
    {
        $token = $this->prepareToken($token);

        return chr(0)
        . chr(0)
        . chr(32)
        . pack('H*', $token)
        . chr(0)
        . chr(strlen($payload))
        . $payload;
    }

    /**
     * @param $deviceToken
     *
     * @return mixed
     */
    private function prepareToken($deviceToken)
    {
        return strtolower(str_replace(array('<', '>', ' '), '', $deviceToken));
    }

    protected function getConnectionParams()
    {
        return array(
            CURLOPT_URL            => $this->getRemoteSocketAddress(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLCERT        => $this->live ? $this->apnsCert : $this->apnsSandboxCert,
            CURLOPT_POST           => true,
        );
    }

    /**
     * @return string Full qualified url address of APNS server.
     */
    private function getRemoteSocketAddress()
    {
        return sprintf("%s://%s:%d", $this->scheme, $this->live ? $this->apnsHost : $this->apnsSandboxHost, $this->apnsPort);
    }
}