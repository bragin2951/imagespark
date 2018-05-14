<?php

namespace App\Models\Chat;

use App\Events\Chat\Message\Deleted;
use App\Events\Chat\Message\Edited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = [
        'message'
    ];

    public function author()
    {
        return $this->belongsTo(User::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function scopeCanEditMessage($query, $userID)
    {
        return $query->where('author_id', $userID);
    }

    public function scopeCanDeleteMessage($query, $user)
    {
        if ($user->isTechAdmin() || $user->isAdmin()) {
            return $query->whereRaw('true is true');
        } else {
            return $query
                ->whereHas('room.moderators', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                })
                ->orWhere('author_id', $user->id);
        }
    }

    public function scopeRoom($query, $roomID)
    {
        return $query->where('room_id', $roomID);
    }

    public function scopeFilterForBanned($query, $user, $roomID)
    {
        if ($user->bannedInChatRooms->where('id', $roomID)->isNotEmpty()) {
            $banDateTime = $user->bannedInChatRooms->where('id', $roomID)->first()->since->created_at;
            return $query->where('created_at', '<=', $banDateTime);
        } else {
            return $query;
        }
    }

    public function getCreatedDateAttribute()
    {
        return $this->created_at->format('Y-m-d');
    }

    public function edit($message)
    {
        $this->message = $message;
        $this->save();

        broadcast(new Edited($this))->toOthers();

        return $this;
    }

    public function remove()
    {
        broadcast(new Deleted($this))->toOthers();

        $this->delete();
    }
}
