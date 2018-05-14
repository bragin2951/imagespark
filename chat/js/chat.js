window.axios = require('axios');
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
let token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

window.Echo = require('laravel-echo');
window.io = require('socket.io-client');

const LaravelEcho = new Echo({
    broadcaster: 'socket.io',
    host: 'https://chat.kanitelka.test:6001'
});

const ResizeSensor = require('css-element-queries/src/ResizeSensor');

$(document).ready(function () {
    const $roomID = $("#room-id").val();

    const $select2 = $('.moderator-row.new-moderator select').select2({
        allowClear: true,
        multiple: true,
        maximumSelectionLength: 1,
        placeholder: "Введите имя...",
        width: 'calc(100% - 2 * 40px - 2 * 5px - 6px)',
        ajax: {
            url: '/chat/users',
            dataType: 'json',
            data: (params) => {
                return {
                    name: params.term,
                    roomID: $roomID
                };
            },
            processResults: function (data) {
                let $data = [];
                let $results = [];
                for (let $i = 0; $i < data.users.length; $i++) {
                    $data[data.users[$i].id] = data.users[$i];
                    $results.push({
                        id: data.users[$i].id,
                        text: data.users[$i].name
                    });
                }
                window.sessionStorage.setItem('users', JSON.stringify($data));
                return {
                    results: $results
                };
            }
        },
        language: "ru"
    });

    getUser($roomID);

    function getUser() {
        if (window.sessionStorage.getItem('user') === null) {
            axios.get('/chat/user?'+$.param({roomID: $roomID}))
                .then(function (response) {
                    const $data = response.data;
                    window.sessionStorage.setItem('user', JSON.stringify($data.user));
                }).catch(function (error) {
                console.log(error.response);
            });
        }
    }

    function newMessage($message, $containerID, $scrollPositionBefore, $previousMessageHeight) {
        axios.post(`/room/${$roomID}/newMessage`, {
            message: $message
        }).then(function (response) {
            const $data = response.data;
            afterSendMessage($data, $containerID);
            scrollAfterRenderMessage($scrollPositionBefore, $previousMessageHeight);
        }).catch(function (error) {
            console.log(error.response);
        });
    }

    function editMessage($message, $messageID) {
        axios.put(`/message/${$messageID}`, {
            message: $message
        }).then(function (response) {
            $("#messages").find(`[data-message-id="${$messageID}"]`).find(".message-text").html($message);
        }).catch(function (error) {
            console.log(error.response);
        });
    }

    function preSendMessage($data) {
        $todayDate = new Date();
        $month = ($todayDate.getMonth() < 9) ? `0${$todayDate.getMonth() + 1}` : $todayDate.getMonth() + 1;
        $date = ($todayDate.getDate() < 9) ? `0${$todayDate.getDate()}` : $todayDate.getDate();
        $todayDateString = `${$todayDate.getFullYear()}-${$month}-${$date}`;

        const messageRow = $("<div />").addClass("message-row").attr("id", $data.container);
        const userAvatar = $("<img />").addClass("user-avatar").attr("src", $data.author.photo);
        const messageContent = $("<div />").addClass("message-content");
        const messageHeader = $("<div />").addClass("message-header");
        const messageText = $("<div />").addClass("message-text").html($data.message.text);
        const messageAuthor = $("<div />").addClass("message-author").text($data.author.name);
        const messageTime = $("<div />").addClass("message-time");
        const messageActions = $("<div />").addClass("message-actions");
        const messageEdit = $("<i />").addClass("fa").addClass("fa-pencil").attr("data-action", "edit-message").css({'margin-right': '5px'});
        const messageDelete = $("<i />").addClass("fa").addClass("fa-trash").attr("data-action", "delete-message");
        messageActions.append(messageEdit).append(messageDelete);
        messageHeader.append(messageAuthor).append(messageTime).append(messageActions);
        messageContent.append(messageHeader).append(messageText);
        messageRow.append(userAvatar).append(messageContent);
        if (!$("#messages").find(".message-row").is(`[data-date="${$todayDateString}"]`)) {
            const dateRow = $("<div />").addClass("date-row").attr("id", `${$data.container}-date`);
            $("#messages").prepend(dateRow);
        }
        $("#messages").prepend(messageRow);
    }

    function afterSendMessage($data, $containerID) {
        if ($("div").is(`#${$containerID}`)) {
            if (!$("#messages").find(".message-row").is(`[data-date="${$data.message.date}"]`)) {
                $("#messages").find(`#${$containerID}-date`).attr("data-date", $data.message.date).removeAttr("id").text($data.message.dateShow);
            }
            const $messageContainer = $("#messages").find(`#${$containerID}`);
            $messageContainer.removeAttr("id").attr("data-date", $data.message.date).attr("data-message-id", $data.message.id);
            $messageContainer.find(".message-time").text($data.message.time);
            $messageContainer.find("[data-action='edit-message']").attr("data-message-id", $data.message.id);
            $messageContainer.find("[data-action='delete-message']").attr("data-message-id", $data.message.id);
        }
    }

    function renderMessage($data) {
        $user = JSON.parse(window.sessionStorage.getItem('user'));

        const messageRow = $("<div />").addClass("message-row").attr("data-date", $data.message.date.date).attr("data-message-id", $data.message.id);
        const userAvatar = $("<img />").addClass("user-avatar").attr("src", $data.message.author.photo);
        const messageContent = $("<div />").addClass("message-content");
        const messageHeader = $("<div />").addClass("message-header");
        const messageText = $("<div />").addClass("message-text").html($data.message.message);
        const messageAuthor = $("<div />").addClass("message-author").text($data.message.author.name);
        const messageTime = $("<div />").addClass("message-time").text($data.message.date.time);
        const messageActions = $("<div />").addClass("message-actions");
        const messageDelete = $("<i />").addClass("fa").addClass("fa-trash").attr("data-action", "delete-message").attr("data-message-id", $data.message.id).css({'margin-right': '5px'});
        messageActions.append(messageDelete);
        if ($data.message.author.canBeBanned) {
            const messageAuthorBan = $("<i />").addClass("fa").addClass("fa-ban").attr("data-action", "ban-user").attr("data-user-id", $data.message.author.id);
            messageActions.append(messageAuthorBan);
        }
        if (!$user.isModerator && !$user.isHost && !$user.isAdmin) {
            messageActions.addClass("hide");
        }
        messageHeader.append(messageAuthor).append(messageTime).append(messageActions);
        messageContent.append(messageHeader).append(messageText);
        messageRow.append(userAvatar).append(messageContent);
        if (!$("#messages").find(".message-row").is(`[data-date="${$data.message.date.date}"]`)) {
            const dateRow = $("<div />").addClass("date-row").attr("data-date", $data.message.date.date).text($data.message.date.dateShow);
            $("#messages").prepend(dateRow);
        }
        $("#messages").prepend(messageRow);
    }

    function getContainer($userID) {
        let IDs = [];
        $("#messages").find("[id^='"+$userID+"-message-']").each(function() {
            IDs.push(parseInt(this.id.replace(`${$userID}-message-`, '')));
        });
        IDs.sort(function (a, b) {
            if (a > b) {
                return 1;
            }
            if (a < b) {
                return -1;
            }

            return 0;
        });
        let $newID = 1;
        if (IDs.length > 0) {
            $newID = IDs.pop() + 1;
        }
        return `${$userID}-message-${$newID}`;
    }

    function scrollAfterRenderMessage($scrollPositionBefore, $previousMessageHeight) {
        if ((($("#messages").children().first().position().top >= $("#messages").parent().height()) && ($scrollPositionBefore + $previousMessageHeight == $("#messages").parent().height()))) {
            $("#messages").parent().animate({
                scrollTop: $("#messages").children().first().position().top
            }, 2000);
        }
    }

    function removeMessage($messageID) {
        axios.delete(`/message/${$messageID}`)
            .then(function (response) {
                const $date = $("#messages").find(`.message-row[data-message-id="${$messageID}"]`).attr('data-date');
                $("#messages").find(`.message-row[data-message-id="${$messageID}"]`).remove();
                if (!$("#messages").find(".message-row").is(`[data-date="${$date}"]`)) {
                    $("#messages").find(`.date-row[data-date="${$date}"]`).remove();
                }
            }).catch(function (error) {
                console.log(error.response);
            });
    }

    function banUser($userID) {
        axios.post(`/room/${$roomID}/ban/${$userID}`)
            .then(function (response) {
                $("#messages").find(`[data-action="ban-user"][data-user-id="${$userID}"]`).addClass("hide");
                Alert.success('Пользователь заблокирован.');
            }).catch(function (error) {
                console.log(error.response);
            });
    }

    function addModerator($userID) {
        axios.post(`/room/${$roomID}/moderator/${$userID}`)
            .then(function (response) {
                $(".moderator-row.new-moderator").find(".user-avatar").attr("src", "//dummyimage.com/600x600/fff/fff");
                $(".moderator-row.new-moderator").find(".moderator-action").removeAttr("data-user-id");
                $select2.val(null).trigger("change");
                const $user = JSON.parse(window.sessionStorage.getItem('chosen-user'));
                renderNewModerator($user);
                window.sessionStorage.removeItem('chosen-user');
                Alert.success('Модератор добавлен.');
            }).catch(function (error) {
            console.log(error.response);
        });
    }

    function renderNewModerator($user) {
        const $currentUser = JSON.parse(window.sessionStorage.getItem('user'));
        const $moderatorName = $("<span />").addClass("moderator-name").text($user.name);
        const $userAvatar = $("<img />").addClass("user-avatar").attr("src", $user.photo);
        const $moderatorRow = $("<div />").addClass("moderator-row").append($userAvatar).append($moderatorName);
        if (parseInt($currentUser.id) != parseInt($user.id)) {
            const $trashIcon = $("<i />").addClass("fa").addClass("fa-trash");
            const $actionButton = $("<a />").addClass("btn").addClass("btn-outline-dark").addClass("float-right")
                .addClass("moderator-action").attr("data-action", "remove-moderator").attr("data-user-id", $user.id)
                .append($trashIcon);
            $moderatorRow.append($actionButton);
        }
        const el = $(".moderator-row.new-moderator")[0];
        if (el.insertAdjacentHTML) {
            el.insertAdjacentHTML ("beforeBegin", $moderatorRow[0].outerHTML);
        } else {
            const range = document.createRange();
            const frag = range.createContextualFragment($moderatorRow[0].outerHTML);
            el.parentNode.insertBefore(frag, el);
        }
    }

    function removeModerator($userID) {
        axios.delete(`/room/${$roomID}/moderator/${$userID}`)
            .then(function (response) {
                renderRemoveModerator($userID);
                Alert.success('Модератор удален.');
            }).catch(function (error) {
            console.log(error.response);
        });
    }

    function renderRemoveModerator($userID) {
        $("#tab-moderators").find(`[data-user-id="${$userID}"]`).parent().remove();
    }

    function renderMessageScroll($message) {
        $user = JSON.parse(window.sessionStorage.getItem('user'));

        const messageRow = $("<div />").addClass("message-row").attr("data-date", $message.date.date).attr("data-message-id", $message.id);
        const userAvatar = $("<img />").addClass("user-avatar").attr("src", $message.author.photo);
        const messageContent = $("<div />").addClass("message-content");
        const messageHeader = $("<div />").addClass("message-header");
        const messageText = $("<div />").addClass("message-text").html($message.message);
        const messageAuthor = $("<div />").addClass("message-author").text($message.author.name);
        const messageTime = $("<div />").addClass("message-time").text($message.date.time);
        const messageActions = $("<div />").addClass("message-actions");
        const messageDelete = $("<i />").addClass("fa").addClass("fa-trash").attr("data-action", "delete-message").attr("data-message-id", $message.id).css({'margin-right': '5px'});
        messageActions.append(messageDelete);
        if ($message.author.canBeBanned) {
            const messageAuthorBan = $("<i />").addClass("fa").addClass("fa-ban").attr("data-action", "ban-user").attr("data-user-id", $message.author.id);
            messageActions.append(messageAuthorBan);
        }
        if (!$user.isModerator && !$user.isHost && !$user.isAdmin) {
            messageActions.addClass("hide");
        }
        messageHeader.append(messageAuthor).append(messageTime).append(messageActions);
        messageContent.append(messageHeader).append(messageText);
        messageRow.append(userAvatar).append(messageContent);
        $("#messages").append(messageRow);
    }

    function renderDate($date) {
        const dateRow = $("<div />").addClass("date-row").attr("data-date", $date.date).text($date.dateShow);
        $("#messages").append(dateRow);
    }

    function getPreviousMessages($lastID) {
        axios
            .get(`/room/${$roomID}/previous/${$lastID}`)
            .then(function (response) {
                const $data = response.data;
                if ($data.messages.length > 0) {
                    if ($data.messages.length < 20) {
                        $("#messages").unbind('scroll');
                    }
                    const $lastDate = $('#messages').find(".date-row").last().attr("data-date");
                    if ($lastDate === $data.messages[0].date.date) {
                        $('#messages').find(".date-row").last().remove();
                    }
                    for (let $i = 0; $i < $data.messages.length; $i++) {
                        renderMessageScroll($data.messages[$i]);
                        if ($i === ($data.messages.length - 1) || $data.messages[$i].date.date !== $data.messages[$i + 1].date.date) {
                            renderDate($data.messages[$i].date);
                        }
                    }
                } else {
                    $("#messages").unbind('scroll');
                }
            }).catch(function (error) {
                console.log(error.response);
            });
    }

    LaravelEcho
        .private(`room.${$roomID}`)
        .listen('.message.sent', (e) => {
            const $scrollPositionBefore = $("#messages").children().first().position().top;
            const $previousMessageHeight = $("#messages").children().first().height();
            renderMessage(e);
            scrollAfterRenderMessage($scrollPositionBefore, $previousMessageHeight);
        })
        .listen('.message.edited', (e) => {
            $("#messages").find(`[data-message-id="${e.message.id}"]`).find(".message-text").html(e.message.message);
        })
        .listen('.message.deleted', (e) => {
            const $date = $("#messages").find(`.message-row[data-message-id="${e.message.id}"]`).attr('data-date');
            $("#messages").find(`.message-row[data-message-id="${e.message.id}"]`).remove();
            if (!$("#messages").find(".message-row").is(`[data-date="${$date}"]`)) {
                $("#messages").find(`.date-row[data-date="${$date}"]`).remove();
            }
        })
        .listen('.user.banned', (e) => {
            $user = JSON.parse(window.sessionStorage.getItem('user'));
            $("#messages").find(`[data-action="ban-user"][data-user-id="${e.user.id}"]`).remove();
            if (parseInt(e.user.id) === parseInt($user.id)) {
                $user = JSON.parse(window.sessionStorage.getItem('user'));
                $user.isBanned = true;
                window.sessionStorage.setItem('user', JSON.stringify($user));

                $("#messages").find(".message-actions").addClass("hide");

                const $i = $("<i />").addClass("fa").addClass("fa-exclamation-circle");
                const $span = $("<span />").text("Вы не можете писать сообщения в данный чат");
                const $div = $("<div />").append($i).append($span);
                $("#message-input").addClass("user-banned").html($div);
                Alert.error("Вас заблокировали в данном чате.");
                LaravelEcho.leave(`room.${$roomID}`);
            }
        })
        .listen('.moderator.added', (e) => {
            $user = JSON.parse(window.sessionStorage.getItem('user'));

            renderNewModerator(e.user);

            if (parseInt(e.user.id) === parseInt($user.id)) {
                $user.isModerator = true;
                window.sessionStorage.setItem('user', JSON.stringify($user));

                $("#messages").find(".message-row").find(".message-actions").removeClass("hide");
                $(".chat-manage").removeClass("hide");
                $("#tab-chat").css('height', '100%')
                    .css('height', '-=25px').css('height', '-=1px')
                    .css('height', '-=50px').css('height', '-=1px');
                Alert.info("Вас назначили модератором данного чата.");
            }
        })
        .listen('.moderator.removed', (e) => {
            $user = JSON.parse(window.sessionStorage.getItem('user'));

            renderRemoveModerator(e.user.id);

            if (parseInt(e.user.id) === parseInt($user.id)) {
                $user.isModerator = false;
                window.sessionStorage.setItem('user', JSON.stringify($user));

                $("#messages").find(".message-row").find(".message-actions").find('[data-action="ban-user"]').addClass("hide");
                $("#tab-moderators").removeClass("active");
                $("#tab-chat").addClass("active");
                $(".chat-manage").addClass("hide");
                $("#tab-chat").css('height', '100%').css('height', '-=50px').css('height', '-=1px');
                Alert.info("Вас убрали из списка модераторов данного чата.");
            }
        });

    new ResizeSensor($('#message-input'), function() {
        const $height = $('#message-input').height();
        const $user = JSON.parse(window.sessionStorage.getItem('user'));
        if ($user.isModerator || $user.isHost || $user.isAdmin) {
            $(".chat-messages").css('height', `calc(100% - (${$height}px + 1px + 25px + 1px))`);
        } else {
            $(".chat-messages").css('height', `calc(100% - (${$height}px + 1px))`);
        }
    });

    $("#message-input").find(".message-input").keyup(function (event) {
        if ((event.metaKey || event.ctrlKey) && event.keyCode == 13) {
            $user = JSON.parse(window.sessionStorage.getItem('user'));

            if ($user.isBanned) {
                Alert.error("Вы не можете отправлять сообщения в данном чате.");
            } else {
                let $message = $(this).html();
                $message = $message.replace(/^(\s*<br( \/)?>)*|(<br( \/)?>\s*)*$/gm, '');

                if (window.sessionStorage.getItem('mode') == "edit") {
                    if ($user.isBanned) {
                        Alert.error("Вы не можете изменять сообщения в данном чате.");
                    } else {
                        $messageID = parseInt(window.sessionStorage.getItem('message-id'));
                        editMessage($message, $messageID);
                    }
                    window.sessionStorage.removeItem('mode');
                    window.sessionStorage.removeItem('message-id');
                } else {
                    if ($user.isBanned) {
                        Alert.error("Вы не можете отправлять сообщения в данном чате.");
                    } else {
                        $user = JSON.parse(window.sessionStorage.getItem('user'));
                        $container = getContainer($user.id);
                        $data = {
                            author: {
                                id: $user.id,
                                name: $user.name,
                                photo: $user.photo
                            },
                            message: {
                                text: $message
                            },
                            container: $container
                        };
                        const $scrollPositionBefore = $("#messages").children().first().position().top;
                        const $previousMessageHeight = $("#messages").children().first().height();
                        preSendMessage($data);
                        newMessage($message, $container, $scrollPositionBefore, $previousMessageHeight);
                    }
                }

                $(this).html('');
            }
        }
    });

    $("#messages").on('click', '[data-action="edit-message"]', function () {
        $user = JSON.parse(window.sessionStorage.getItem('user'));

        if ($user.isBanned) {
            Alert.error("Вы не можете изменять сообщения в данном чате.");
        } else {
            const $messageID = $(this).attr("data-message-id");
            const $message = $("#messages").find(`[data-message-id="${$messageID}"]`).find(".message-text").html();
            window.sessionStorage.setItem('mode', 'edit');
            window.sessionStorage.setItem('message-id', $messageID);
            $("#message-input").find(".message-input").html($message);
        }
    });

    $("#messages").on('click', '[data-action="delete-message"]', function () {
        $user = JSON.parse(window.sessionStorage.getItem('user'));

        if ($user.isBanned || (!$user.isModerator && !$user.isHost && !$user.isAdmin)) {
            Alert.error("Вы не можете удалять сообщения в данном чате.");
        } else {
            const $messageID = $(this).attr("data-message-id");
            removeMessage($messageID);
        }
    });

    $("#messages").on('click', '[data-action="ban-user"]', function () {
        $user = JSON.parse(window.sessionStorage.getItem('user'));

        if (!$user.isModerator && !$user.isHost && !$user.isAdmin) {
            Alert.error("Вы не можете блокировать пользователей в данном чате.");
        } else {
            const $userID = $(this).attr("data-user-id");
            banUser($userID);
        }
    });

    $("#tab-moderators").on('click', '[data-action="add-moderator"]', function () {
        $user = JSON.parse(window.sessionStorage.getItem('user'));

        if (!$user.isModerator && !$user.isHost && !$user.isAdmin) {
            Alert.error("Вы не можете добавлять модераторов в данном чате.");
        } else {
            const $userID = $(this).attr("data-user-id");
            addModerator($userID);
        }
    });

    $("#tab-moderators").on('click', '[data-action="remove-moderator"]', function () {
        $user = JSON.parse(window.sessionStorage.getItem('user'));

        if (!$user.isModerator && !$user.isHost && !$user.isAdmin) {
            Alert.error("Вы не можете удалять модераторов в данном чате.");
        } else {
            const $userID = $(this).attr("data-user-id");
            removeModerator($userID);
        }
    });

    $(".chat-manage-tab").click(function () {
        if (!$(this).hasClass("active")) {
            $(".chat-manage-tab.active").removeClass("active");
            $(this).addClass("active");
        }
    });

    $('.moderator-row.new-moderator select').on('select2:select', function (e) {
        const $index = e.params.data.id;
        const $users = JSON.parse(window.sessionStorage.getItem('users'));
        const $user = $users[$index];
        window.sessionStorage.setItem('chosen-user', JSON.stringify($user));
        $(".moderator-row.new-moderator").find(".user-avatar").attr("src", $user.photo);
        $(".moderator-row.new-moderator").find(".moderator-action").attr("data-user-id", $user.id);
    });

    $('.moderator-row.new-moderator select').on('select2:unselect', function (e) {
        $(".moderator-row.new-moderator").find(".user-avatar").attr("src", "//dummyimage.com/600x600/fff/fff");
        $(".moderator-row.new-moderator").find(".moderator-action").removeAttr("data-user-id");
        window.sessionStorage.removeItem('chosen-user');
    });

    if ($('#messages').find(".message-row").length % 20 === 0) {
        $('#messages').bind('scroll', function() {
            if ($(this).scrollTop() + $(this).innerHeight() >= $(this)[0].scrollHeight) {
                if ($('#messages').find(".message-row").length % 20 === 0) {
                    const $lastID = $('#messages').find(".message-row").last().attr("data-message-id");
                    getPreviousMessages($lastID);
                }
            }
        });
    }
});