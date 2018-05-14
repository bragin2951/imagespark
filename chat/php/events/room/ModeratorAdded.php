<?php

namespace App\Events\Chat\Room;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ModeratorAdded implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $broadcastQueue = 'chat';

    public $user;
    public $room;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($user, $room)
    {
        $this->user = $user;
        $this->room = $room;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('room.'.$this->room->id);
    }

    public function broadcastAs()
    {
        return 'moderator.added';
    }

    public function broadcastWith()
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->profile->name.' '.$this->user->profile->surname,
                'photo' => $this->user->profile->photo
            ]
        ];
    }
}
