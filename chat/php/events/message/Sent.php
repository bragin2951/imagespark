<?php

namespace App\Events\Chat\Message;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class Sent implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $broadcastQueue = 'chat';

    public $message;
    public $extra;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($message, $extra)
    {
        $this->message = $message;
        $this->extra = $extra;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('room.'.$this->message->room->id);
    }

    public function broadcastAs()
    {
        return 'message.sent';
    }

    public function broadcastWith()
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'author' => [
                    'id' => $this->message->author->id,
                    'name' => $this->message->author->profile->name,
                    'photo' => $this->message->author->profile->photo,
                    'canBeBanned' => $this->message->room->canBanUser($this->message->author)
                ],
                'message' => $this->message->message,
                'date' => [
                    'dateShow' => $this->message->created_at->formatLocalized('%e %B %Y'),
                    'date' => $this->message->created_at->format('Y-m-d'),
                    'time' => $this->message->created_at->format('H:i')
                ]
            ],
            'extra' => $this->extra
        ];
    }
}
