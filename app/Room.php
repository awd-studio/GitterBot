<?php
/**
 * This file is part of GitterBot package.
 *
 * @author Serafim <nesk@xakep.ru>
 * @date 09.10.2015 17:08
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace App;

use App\Gitter\Client;
use App\Gitter\Http\Stream;
use App\Gitter\Middleware\Storage;
use InvalidArgumentException;

/**
 * Class Room
 * @package App
 *
 * @property int $id
 */
class Room
{
    /**
     * @param $roomId
     * @return mixed
     */
    public static function getId($roomId)
    {
        $rooms = \Config::get('gitter.rooms');
        $alias = mb_strtolower(trim($roomId));

        if (array_key_exists($alias, $rooms)) {
            return $rooms[$alias];
        }

        return $roomId;
    }

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    public $id;

    /**
     * @var Storage
     */
    protected $storage;

    /**
     * Room constructor.
     * @param string $roomId
     */
    public function __construct($roomId)
    {
        $this->client = \App::make(Client::class);
        $this->id = static::getId($roomId);
        $this->storage = $this->createMiddlewaresStorage();

        $this->createAchievementsStorage();
    }

    /**
     * @TODO Нужно переместить в отдельную категорию "подписчиков"
     * Create achieve
     */
    protected function createAchievementsStorage()
    {
        $achievements = \Config::get('gitter.achievements');

        // Init achievements
        foreach ($achievements as $achieve) {
            (new $achieve)->handle();
        }

        Achieve::created(function (Achieve $achieve) {
            $this->write(
                '> #### ' . $achieve->title . "\n" .
                '> *Поздравляем тебя @' . $achieve->user->login . '! ' .
                    $achieve->description . '*' . "\n" .
                '> ![' . $achieve->title . '](' . $achieve->image . ')'
            );
        });
    }

    /**
     * Create middlewares storage
     */
    protected function createMiddlewaresStorage()
    {
        $container = \App::make('app');
        $middlewares = \Config::get('gitter.middlewares');


        $storage = new Storage($container);
        foreach ($middlewares as $middleware => $priority) {
            $storage->add($middleware, $priority);
        }

        return $storage;
    }

    /**
     * @throws InvalidArgumentException
     * @return Client
     */
    public function listen()
    {
        $client = $this->client
            ->stream('messages', ['roomId' => $this->id])
            ->on(Stream::EVENT_MESSAGE, function ($stream, $data) {
                $this->onMessage(Message::fromGitterObject($data));
            })
            ->on(Stream::EVENT_END, [$this, 'onClose'])
            ->on(Stream::EVENT_ERROR, [$this, 'onError']);

        return $client;
    }

    /**
     * @param Message $message
     */
    public function onMessage(Message $message)
    {
        try {
            $this->storage->handle($message);

        } catch (\Exception $e) {
            $message->pre(
                'Error =( ' . "\n" .
                $e->getMessage()
            );
        }
    }

    /**
     * @TODO I do not know if it works
     * @param Stream $stream
     */
    public function onClose(Stream $stream)
    {
        $stream->reconnect();
    }

    /**
     * @TODO I do not know if it works too
     * @param Stream $stream
     */
    public function onError(Stream $stream)
    {
        $stream->reconnect();
    }

    /**
     * @param $text
     * @return $this
     */
    public function write($text)
    {
        $client = \App::make(Client::class);

        $client->request('message.send', ['roomId' => $this->id], [
            'text' => (string)$text,
        ], 'POST');

        return $this;
    }
}