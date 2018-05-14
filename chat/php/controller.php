<?php

namespace App\Http\Controllers\Chat;

use App\Http\Requests\Chat\Message\EditRequest;
use App\Http\Requests\Chat\Message\NewRequest;
use App\Http\Controllers\Controller;
use App\Models\Chat\Message;
use App\Models\Chat\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IndexController extends Controller
{
    public function getUser()
    {
        $user = Auth::user();
        $roomID = request()->query('roomID');
        $room = Room::findOrFail($roomID);
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->profile->name,
                'photo' => $user->profile->photo,
                'isModerator' => $user->moderatorInChatRooms->contains('id', $roomID),
                'isAdmin' => $user->isTechAdmin(),
                'isHost' => $room->webinar->host->id == $user->id,
                'isBanned' => $user->bannedInChatRooms->contains('id', $roomID)
            ]
        ]);
    }

    public function newMessage(NewRequest $request, $roomID)
    {
        $room = Room::query()->canAddMessage(Auth::id())->findOrFail($roomID);
        $message = $room->addMessage($request->input('message'), $request->input('extra'));

        return response()->json([
            'success' => true,
            'message' => [
                'id' => $message->id,
                'dateShow' => $message->created_at->formatLocalized('%e %B %Y'),
                'date' => $message->created_at->format('Y-m-d'),
                'time' => $message->created_at->format('H:i')
            ],
            'extra' => $request->input('extra')
        ]);
    }

    public function editMessage(EditRequest $request, $messageID)
    {
        $message = Message::query()->canEditMessage(Auth::id())->findOrFail($messageID);
        $message = $message->edit($request->input('message'));

        return response()->json([
            'success' => true,
            'message' => [
                'id' => $message->id,
                'editedDate' => $message->updated_at->format('Y-m-d'),
                'editedTime' => $message->updated_at->format('H:i')
            ]
        ]);
    }

    public function deleteMessage($messageID)
    {
        $message = Message::query()->canDeleteMessage(Auth::user())->findOrFail($messageID);
        $message->remove();

        return response()->json([
            'success' => true,
            'message' => [
                'id' => $message->id
            ]
        ]);
    }

    public function banUser($roomID, $userID)
    {
        $room = Room::findOrFail($roomID);
        $user = User::findOrFail($userID);

        if ($room->isModerator(Auth::user()) && $room->canBanUser($user)) {
            $room->banUser($user);
        } else {
            return abort(404);
        }

        return response()->json([
            'success' => true
        ]);
    }

    public function addModerator($roomID, $userID)
    {
        $room = Room::findOrFail($roomID);
        $user = User::findOrFail($userID);


        if ($room->isModerator(Auth::user())) {
            $room->addModerator($user);
        } else {
            return abort(404);
        }

        return response()->json([
            'success' => true
        ]);
    }

    public function removeModerator($roomID, $userID)
    {
        $room = Room::findOrFail($roomID);
        $user = User::findOrFail($userID);

        if ($room->isModerator(Auth::user()) && $room->canRemoveModerator($user)) {
            $room->removeModerator($user);
        } else {
            return abort(404);
        }

        return response()->json([
            'success' => true
        ]);
    }

    public function getUsers(Request $request)
    {
        $name = $request->query('name');
        $roomID = $request->query('roomID');

        $users = User::query()
            ->with('profile')
            ->notBannedInRoom($roomID)
            ->notModeratorInRoom($roomID)
            ->notHostOfRoom($roomID)
            ->notAdmin()
            ->where('id', '!=', Auth::id())
            ->name($name)
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $user = [
                    'id' => $item->id,
                    'name' => $item->profile->name.' '.$item->profile->surname,
                    'photo' => $item->profile->photo
                ];
                return $user;
            });

        return response()->json([
            'success' => true,
            'users' => $users
        ]);
    }

    public function getPrevious($roomID, $lastMessageID)
    {
        $messages = Message::query()
            ->with('author', 'author.profile')
            ->room($roomID)
            ->where('id', '<', $lastMessageID)
            ->orderByDesc('id', 'created_at')
            ->limit(20)
            ->get();

        $data = $messages->map(function ($message) {
            $item = [
                'id' => $message->id,
                'author' => [
                    'id' => $message->author->id,
                    'name' => $message->author->profile->name,
                    'photo' => $message->author->profile->photo,
                    'canBeBanned' => $message->room->canBanUser($message->author)
                ],
                'message' => $message->message,
                'date' => [
                    'dateShow' => $message->created_at->formatLocalized('%e %B %Y'),
                    'date' => $message->created_at->format('Y-m-d'),
                    'time' => $message->created_at->format('H:i')
                ]
            ];
            return $item;
        });

        return response()->json([
            'success' => true,
            'messages' => $data
        ]);
    }
}
