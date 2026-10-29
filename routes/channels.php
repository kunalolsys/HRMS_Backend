<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Chat Authorization: Receiver hi chat channel access kar sakta hai
Broadcast::channel('chat.{receiverId}', function (User $user, $receiverId) {
    return (int) $user->id === (int) $receiverId;
});

// Notifications Authorization: User sirf apna notification channel listen karega
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});