<?php

namespace App\Models\Chat;

use App\Events\Chat\Message\Sent;
use App\Events\Chat\Room\ModeratorAdded;
use App\Events\Chat\Room\ModeratorRemoved;
use App\Events\Chat\Room\UserBanned;
use App\Models\User;
use App\Models\Webinar\Webinar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Room extends Model
{
    protected $table = 'chat_rooms';

    public $timestamps = false;

    protected $fillable = [
        'is_active'
    ];

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function moderators()
    {
        return $this->belongsToMany(User::class, 'chat_room_moderator', 'room_id', 'user_id');
    }

    public function banned()
    {
        return $this->belongsToMany(User::class, 'chat_room_banned', 'room_id', 'user_id')
            ->as('since')
            ->withTimestamps();
    }

    public function scopeCanAddMessage($query, $userID)
    {
        return $query->whereDoesntHave('banned', function ($query) use ($userID) {
            $query->where('users.id', $userID);
        });
    }

    public function addMessage($messageText, $extra = [])
    {
        $message = new Message([
            'message' => $messageText
        ]);
        $message->author_id = Auth::id();
        $this->messages()->save($message);

        broadcast(new Sent($message, $extra))->toOthers();

        return $message;
    }

    public function isModerator($user)
    {
        return $this->moderators->contains('id', $user->id) || $this->webinar->host->id == $user->id || $user->isTechAdmin();
    }

    public function canBanUser($user)
    {
        return !$this->moderators->contains('id', $user->id) &&
            !$this->banned->contains('id', $user->id) &&
            $this->webinar->host->id != $user->id &&
            !$user->isTechAdmin() &&
            !$this->isBanned($user->id);
    }

    public function canRemoveModerator($user)
    {
        return $this->moderators->contains('id', $user->id) && $this->webinar->host->id != $user->id && !$user->isTechAdmin();
    }

    public function isBanned($userID)
    {
        return $this->banned->contains('id', $userID);
    }

    public function banUser($user)
    {
        $this->banned()->attach($user->id);

        broadcast(new UserBanned($user, $this))->toOthers();

        return $this;
    }

    public function addModerator($user)
    {
        $this->moderators()->attach($user->id);

        broadcast(new ModeratorAdded($user, $this))->toOthers();

        return $this;
    }

    public function removeModerator($user)
    {
        $this->moderators()->detach([$user->id]);

        broadcast(new ModeratorRemoved($user, $this))->toOthers();

        return $this;
    }
}
